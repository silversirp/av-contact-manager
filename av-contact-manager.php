<?php
/**
 * Plugin Name: AV Contact Manager
 * Description: V7.0 - Spam protection: Cloudflare Turnstile, JS/time check, content filter, spam folder, safe confirmation e-mail.
 * Version: 7.0
 * Author: Silver Sirp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AV_Contact_Manager {

    const VERSION    = '7.0';
    const DB_VERSION = '2';

    // Field limits
    const MAX_NAME_LENGTH     = 100;
    const MAX_EVENT_LENGTH    = 100;
    const MAX_LOCATION_LENGTH = 150;
    const MAX_DESC_LENGTH     = 5000;

    // Spam protection
    const MIN_FILL_SECONDS          = 4;   // Faster than this = bot
    const MAX_LINKS_IN_DESC         = 2;   // More links than this = spam
    const CONFIRM_PER_EMAIL_HOURS   = 24;  // Max 1 confirmation per address in this window
    const CONFIRM_GLOBAL_PER_HOUR   = 10;  // Circuit breaker for confirmation e-mails
    const RATE_LIMIT_PER_10_MIN     = 3;   // Submissions per IP

    const SPAM_KEYWORDS = array(
        'seo', 'backlink', 'google ranking', 'rankings', 'search engine', 'website traffic',
        'web traffic', 'digital marketing', 'marketing services', 'lead generation', 'b2b data',
        'guest post', 'crypto', 'bitcoin', 'forex', 'casino', 'viagra', 'cialis', 'loan offer',
        'web design services', 'redesign your website', 'increase your sales', 'unsubscribe',
    );

    private $table_name;
    private $mail_log_table;
    private $last_encrypted_pass = null;
    private $last_mail_error = '';

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'av_contact_entries';
        $this->mail_log_table = $wpdb->prefix . 'av_mail_log';

        // Lifecycle
        register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );
        add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ) );
        add_action( 'av_daily_cleanup_event', array( $this, 'cleanup_old_entries' ) );

        // Frontend
        add_shortcode( 'av_contact_form', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'send_headers', array( $this, 'send_csp_header' ) );

        // Admin
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_av_delete_entry', array( $this, 'handle_delete_entry' ) );
        add_action( 'admin_post_av_mark_entry', array( $this, 'handle_mark_entry' ) );
        add_action( 'admin_post_av_clear_mail_log', array( $this, 'handle_clear_mail_log' ) );

        // Mail
        add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
        add_action( 'wp_mail_failed', array( $this, 'log_mail_failure' ) );
    }

    /**
     * 1. DATABASE
     */
    public function activate_plugin() {
        $this->create_or_update_table();

        if ( ! wp_next_scheduled( 'av_daily_cleanup_event' ) ) {
            wp_schedule_event( time(), 'daily', 'av_daily_cleanup_event' );
        }

        $role = get_role( 'administrator' );
        if ( $role ) {
            $role->add_cap( 'av_view_submissions' );
        }
    }

    public function maybe_upgrade_db() {
        if ( get_option( 'av_db_version' ) === self::DB_VERSION || get_transient( 'av_db_upgrade_failed' ) ) {
            return;
        }
        try {
            $this->create_or_update_table();
        } catch ( \Throwable $e ) {
            // Never take the whole site down because of a schema upgrade; retry in an hour.
            error_log( 'AV Contact Form: DB upgrade failed: ' . $e->getMessage() );
            set_transient( 'av_db_upgrade_failed', 1, HOUR_IN_SECONDS );
        }
    }

    private function table_exists( $table ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
    }

    /**
     * dbDelta() runs DESCRIBE on each table, which fails for a table that doesn't exist yet –
     * and W3 Total Cache's DB cache turns that failed query into a fatal error. So new tables are
     * created directly and dbDelta() is only used to add columns/indexes to existing ones.
     */
    private function create_or_alter( $create_sql, $table ) {
        global $wpdb;
        if ( $this->table_exists( $table ) ) {
            dbDelta( $create_sql );
        } else {
            $wpdb->query( preg_replace( '/^\s*CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $create_sql ) );
        }
    }

    private function create_or_update_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            name tinytext NOT NULL,
            email varchar(100) NOT NULL,
            event_type text NOT NULL,
            event_date text NOT NULL,
            event_location text NOT NULL,
            message text NOT NULL,
            ip_address varchar(45) NOT NULL,
            source_url text NOT NULL,
            subject_prefix tinytext NOT NULL,
            status varchar(10) DEFAULT 'ok' NOT NULL,
            spam_reason varchar(255) DEFAULT '' NOT NULL,
            PRIMARY KEY  (id),
            KEY time_index (time),
            KEY email_index (email(50)),
            KEY status_index (status)
        ) $charset_collate;";

        $sql_log = "CREATE TABLE $this->mail_log_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            entry_id mediumint(9) DEFAULT 0 NOT NULL,
            type varchar(20) NOT NULL,
            recipient varchar(255) NOT NULL,
            subject varchar(255) NOT NULL,
            status varchar(10) NOT NULL,
            error text NOT NULL,
            PRIMARY KEY  (id),
            KEY time_index (time),
            KEY entry_index (entry_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $this->create_or_alter( $sql, $this->table_name );
        $this->create_or_alter( $sql_log, $this->mail_log_table );

        if ( $this->table_exists( $this->mail_log_table ) ) {
            update_option( 'av_db_version', self::DB_VERSION );
        }
    }

    public function deactivate_plugin() {
        wp_clear_scheduled_hook( 'av_daily_cleanup_event' );
    }

    public function cleanup_old_entries() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE time < %s", date( 'Y-m-d H:i:s', strtotime( '-1 year' ) ) ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->mail_log_table} WHERE time < %s", date( 'Y-m-d H:i:s', strtotime( '-1 year' ) ) ) );
        // Spam is kept only for 60 days
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE status = 'spam' AND time < %s", date( 'Y-m-d H:i:s', strtotime( '-60 days' ) ) ) );
    }

    /**
     * 2. ASSETS & HEADERS
     */
    private function turnstile_enabled() {
        return get_option( 'av_turnstile_site_key' ) && get_option( 'av_turnstile_secret' );
    }

    public function send_csp_header() {
        global $post;

        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'av_contact_form' ) ) {

            header(
                "Content-Security-Policy: " .
                "default-src 'self'; " .
                "script-src 'self' 'unsafe-inline' https://ajax.googleapis.com https://www.youtube.com https://www.googletagmanager.com https://www.google-analytics.com https://challenges.cloudflare.com; " .
                "style-src 'self' 'unsafe-inline' https://ajax.googleapis.com; " .
                "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://challenges.cloudflare.com; " .
                "connect-src 'self' https://challenges.cloudflare.com https://www.google-analytics.com; " .
                "img-src 'self' https://i.ytimg.com https://secure.gravatar.com data:; " .
                "font-src 'self' data:; " .
                "worker-src 'self' blob:;"
            );
        }
    }

    public function enqueue_frontend_assets() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'av_contact_form' ) ) {
            wp_enqueue_script( 'jquery-ui-datepicker' );
            wp_enqueue_script( 'av-script', plugins_url( 'js/av-script.js', __FILE__ ), array( 'jquery', 'jquery-ui-datepicker' ), self::VERSION, true );
            wp_enqueue_style( 'jquery-ui-style', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css' );
            wp_enqueue_style( 'av-style', plugins_url( 'css/av-style.css', __FILE__ ), array(), self::VERSION );

            if ( $this->turnstile_enabled() ) {
                wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) );
            }
        }
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'av-contact' ) !== false ) {
            wp_enqueue_style( 'av-admin-style', plugins_url( 'css/av-admin.css', __FILE__ ), array(), self::VERSION );
            wp_enqueue_script( 'av-admin-script', plugins_url( 'js/av-admin.js', __FILE__ ), array( 'jquery' ), self::VERSION, true );
        }
    }

    /**
     * 3. SMTP CONFIG
     */
    public function sanitize_smtp_pass( $raw_pass ) {
        // WP may call the sanitize callback twice on first save – don't encrypt our own output again.
        if ( $this->last_encrypted_pass !== null && $raw_pass === $this->last_encrypted_pass ) {
            return $raw_pass;
        }
        if ( ! empty( $_POST['av_smtp_pass_clear'] ) ) {
            return $this->last_encrypted_pass = '';
        }
        // Empty field = keep the stored password (the form never shows the stored value).
        if ( $raw_pass === '' || $raw_pass === null ) {
            return $this->last_encrypted_pass = (string) get_option( 'av_smtp_pass', '' );
        }
        $key = wp_salt( 'auth' );
        $iv = substr( wp_salt( 'secure_auth' ), 0, 16 );
        $encrypted = openssl_encrypt( $raw_pass, 'AES-256-CBC', $key, 0, $iv );
        return $this->last_encrypted_pass = base64_encode( $encrypted );
    }

    private function get_decrypted_smtp_pass() {
        $encrypted = get_option( 'av_smtp_pass' );
        if ( empty( $encrypted ) ) return '';

        $key = wp_salt( 'auth' );
        $iv = substr( wp_salt( 'secure_auth' ), 0, 16 );
        return openssl_decrypt( base64_decode( $encrypted ), 'AES-256-CBC', $key, 0, $iv );
    }

    public function configure_smtp( $phpmailer ) {
        $smtp_host = get_option( 'av_smtp_host' );
        $decrypted_pass = $this->get_decrypted_smtp_pass();

        if ( ! empty( $smtp_host ) ) {
            $phpmailer->isSMTP();
            $phpmailer->Host = $smtp_host;
            $phpmailer->Port = get_option( 'av_smtp_port', 25 );

            if ( ! empty( $decrypted_pass ) ) {
                $phpmailer->SMTPAuth = true;
                $phpmailer->Username = get_option( 'av_smtp_user' );
                $phpmailer->Password = $decrypted_pass;
            } else {
                $phpmailer->SMTPAuth = false;
                $phpmailer->SMTPAutoTLS = false;
            }

            if ( $phpmailer->Port == 465 ) $phpmailer->SMTPSecure = 'ssl';
            elseif ( $phpmailer->Port == 587 ) $phpmailer->SMTPSecure = 'tls';
            else $phpmailer->SMTPSecure = '';

            $phpmailer->From = get_option( 'av_sender_email', 'noreply@' . $_SERVER['SERVER_NAME'] );
            $phpmailer->FromName = get_bloginfo( 'name' );
        }
    }

    public function log_mail_failure( $wp_error ) {
        $data = $wp_error->get_error_data();
        $to = ( is_array( $data ) && isset( $data['to'] ) ) ? implode( ',', (array) $data['to'] ) : '?';
        $this->last_mail_error = $wp_error->get_error_message();
        error_log( 'AV Contact Form: wp_mail failed to ' . $to . ': ' . $this->last_mail_error );
    }

    private function log_mail( $entry_id, $type, $recipient, $subject, $status, $error = '' ) {
        global $wpdb;
        $wpdb->insert( $this->mail_log_table, array(
            'time'      => current_time( 'mysql' ),
            'entry_id'  => (int) $entry_id,
            'type'      => $type,
            'recipient' => mb_substr( is_array( $recipient ) ? implode( ', ', $recipient ) : (string) $recipient, 0, 255 ),
            'subject'   => mb_substr( (string) $subject, 0, 255 ),
            'status'    => $status,
            'error'     => (string) $error,
        ) );
    }

    /** wp_mail wrapper that writes every attempt to the plugin's mail log. */
    private function send_logged( $entry_id, $type, $to, $subject, $body, $headers ) {
        $this->last_mail_error = '';
        $ok = wp_mail( $to, $subject, $body, $headers );
        $this->log_mail( $entry_id, $type, $to, $subject, $ok ? 'sent' : 'failed', $ok ? '' : ( $this->last_mail_error ? $this->last_mail_error : 'wp_mail returned false' ) );
        return $ok;
    }

    /**
     * 4. FORM LOGIC
     */
    private function sanitize_emails_list( $emails_string ) {
        $emails = explode( ',', $emails_string );
        $clean = array();
        foreach ( $emails as $email ) {
            $e = sanitize_email( trim( $email ) );
            if ( is_email( $e ) ) {
                $clean[] = $e;
            }
        }
        return $clean;
    }

    private function sanitize_header( $str ) {
        return str_replace( array( "\r", "\n", "\t" ), '', $str );
    }

    private function get_current_url() {
        $protocol = is_ssl() ? 'https' : 'http';
        $host = sanitize_text_field( $_SERVER['HTTP_HOST'] );
        $uri = esc_url_raw( $_SERVER['REQUEST_URI'] );
        return $protocol . '://' . $host . $uri;
    }

    private function post_field( $key ) {
        return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
    }

    private function msg( $type, $text ) {
        echo '<div id="av-result-message" class="av-msg ' . esc_attr( $type ) . '">' . esc_html( $text ) . '</div>';
    }

    private function fingerprint( $ip ) {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
        return md5( $ip . $ua );
    }

    private function check_rate_limit( $ip ) {
        // Keyed by IP only – changing the User-Agent must not reset the counter.
        $transient = 'av_sub_' . md5( $ip );

        $attempts = get_transient( $transient );
        if ( $attempts === false ) { set_transient( $transient, 1, 600 ); return true; }
        if ( $attempts >= self::RATE_LIMIT_PER_10_MIN ) { set_transient( $transient, $attempts + 1, 3600 ); return false; }
        set_transient( $transient, $attempts + 1, 600 );
        return true;
    }

    /** Static token the JS copies into the form. Bots that don't run JS won't send it. */
    private function js_token() {
        return substr( hash_hmac( 'sha256', 'av-js-check', wp_salt( 'nonce' ) ), 0, 16 );
    }

    private function verify_turnstile( $ip ) {
        $token = $this->post_field( 'cf-turnstile-response' );
        if ( $token === '' ) return false;

        $response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
            'timeout' => 10,
            'body'    => array(
                'secret'   => get_option( 'av_turnstile_secret' ),
                'response' => $token,
                'remoteip' => $ip,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            // Fail open so real leads aren't lost when Cloudflare is unreachable; the other checks still apply.
            error_log( 'AV Contact Form: Turnstile verify error: ' . $response->get_error_message() );
            return true;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['success'] ) ) {
            error_log( 'AV Contact Form: Turnstile rejected: ' . implode( ',', isset( $body['error-codes'] ) ? (array) $body['error-codes'] : array() ) );
            return false;
        }
        return true;
    }

    /**
     * Returns a list of reasons why the submission looks like spam (empty = clean).
     */
    private function detect_spam( $name, $email, $event, $loc, $desc ) {
        $reasons = array();

        // JS check + fill time
        $js = explode( '|', $this->post_field( 'av_js' ) );
        if ( count( $js ) !== 2 || ! hash_equals( $this->js_token(), $js[0] ) ) {
            $reasons[] = 'no_js';
        } elseif ( (int) $js[1] < self::MIN_FILL_SECONDS ) {
            $reasons[] = 'too_fast';
        }

        $link_re = '~(https?://|www\.|\.(com|net|org|ru|xyz|info|biz|top|cfd|io|site|online)\b/?)~i';

        // Links or e-mail addresses in name / event / location
        foreach ( array( 'name' => $name, 'event' => $event, 'location' => $loc ) as $field => $value ) {
            if ( preg_match( $link_re, $value ) || strpos( $value, '@' ) !== false ) {
                $reasons[] = 'link_in_' . $field;
            }
        }

        // Too many links in the description
        if ( preg_match_all( '~(https?://|www\.)~i', $desc ) > self::MAX_LINKS_IN_DESC ) {
            $reasons[] = 'many_links';
        }

        $all_text = $name . ' ' . $event . ' ' . $loc . ' ' . $desc;

        // Cyrillic text / Russian mail domains
        if ( get_option( 'av_block_cyrillic', '1' ) ) {
            if ( preg_match( '/\p{Cyrillic}/u', $all_text ) ) {
                $reasons[] = 'cyrillic';
            }
            if ( preg_match( '/\.(ru|su)$/i', $email ) ) {
                $reasons[] = 'ru_email';
            }
        }

        // Chinese / Japanese / Korean
        if ( preg_match( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $all_text ) ) {
            $reasons[] = 'cjk';
        }

        // Typical marketing spam keywords
        $lower = mb_strtolower( $all_text );
        foreach ( self::SPAM_KEYWORDS as $kw ) {
            if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/u', $lower ) ) {
                $reasons[] = 'kw:' . $kw;
                break;
            }
        }

        // Empty description and no event = nothing to answer (typical bot)
        if ( trim( $desc ) === '' && trim( $event ) === '' ) {
            $reasons[] = 'empty';
        }

        return $reasons;
    }

    public function render_shortcode( $atts ) {
        $args = shortcode_atts( array(
            'to'       => '',
            'reply_to' => '',
            'subject'  => '',
        ), $atts );

        $args['to'] = sanitize_text_field( $args['to'] );
        $args['reply_to'] = sanitize_text_field( $args['reply_to'] );
        $args['subject'] = sanitize_text_field( $args['subject'] );

        ob_start();

        if ( isset( $_GET['av_sent'] ) && $_GET['av_sent'] === '1' ) {
            $fingerprint = $this->fingerprint( $this->get_client_ip() );
            if ( get_transient( 'av_success_' . $fingerprint ) ) {
                $this->msg( 'success', 'Teie päring on edukalt saadetud! Vastan esimesel võimalusel.' );
                delete_transient( 'av_success_' . $fingerprint );
            }
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['_av_nonce'] ) ) {
            $this->handle_submission( $args );
        }

        $this->display_form();
        return ob_get_clean();
    }

    /** Pretend success: bots and spammers must not learn which check stopped them. */
    private function fake_success( $ip ) {
        set_transient( 'av_success_' . $this->fingerprint( $ip ), true, 60 );
        wp_redirect( add_query_arg( 'av_sent', '1', $this->get_current_url() ) );
        exit;
    }

    private function handle_submission( $args ) {
        if ( ! wp_verify_nonce( $_POST['_av_nonce'], 'av_submit_form' ) ) {
            $this->msg( 'error', 'Turvaviga. Palun laadige leht uuesti.' ); return;
        }

        $ip = $this->get_client_ip();

        // Honeypot: drop silently, don't even store
        if ( $this->post_field( 'av_website' ) !== '' ) {
            $this->fake_success( $ip );
        }

        if ( ! isset( $_POST['av_consent'] ) ) {
            $this->msg( 'error', 'Palun nõustuge andmete töötlemise tingimustega.' ); return;
        }

        if ( empty( $args['to'] ) && empty( get_option( 'av_recipient_email' ) ) ) {
            error_log( 'AV Contact Form: recipient email is not configured.' );
            $this->msg( 'error', 'Vorm ei ole seadistatud. Palun võtke ühendust lehe haldajaga.' ); return;
        }

        if ( ! $this->check_rate_limit( $ip ) ) {
            $this->msg( 'error', 'Liiga palju päringuid. Oodake 1 tund.' ); return;
        }

        $name  = mb_substr( sanitize_text_field( $this->post_field( 'av_name' ) ), 0, self::MAX_NAME_LENGTH );
        $email = sanitize_email( $this->post_field( 'av_email' ) );
        $event = mb_substr( sanitize_text_field( $this->post_field( 'av_event' ) ), 0, self::MAX_EVENT_LENGTH );
        $date  = sanitize_text_field( $this->post_field( 'av_date' ) );
        $loc   = mb_substr( sanitize_text_field( $this->post_field( 'av_location' ) ), 0, self::MAX_LOCATION_LENGTH );
        $desc  = mb_substr( sanitize_textarea_field( $this->post_field( 'av_desc' ) ), 0, self::MAX_DESC_LENGTH );
        $url   = $this->get_current_url();
        $subj_prefix = ! empty( $args['subject'] ) ? sanitize_text_field( $args['subject'] ) : '';

        if ( empty( $name ) || empty( $email ) || ! is_email( $email ) ) {
            $this->msg( 'error', 'Palun kontrollige välju.' ); return;
        }

        if ( ! empty( $date ) && ! preg_match( '/^\d{2}\.\d{2}\.\d{4}$/', $date ) ) {
            $this->msg( 'error', 'Kuupäev peab olema formaadis PP.KK.AAAA' ); return;
        }

        if ( $this->turnstile_enabled() && ! $this->verify_turnstile( $ip ) ) {
            $this->msg( 'error', 'Robotikontroll ebaõnnestus. Palun proovige uuesti.' ); return;
        }

        $spam_reasons = $this->detect_spam( $name, $email, $event, $loc, $desc );
        $is_spam = ! empty( $spam_reasons );

        global $wpdb;
        $saved = $wpdb->insert( $this->table_name, array(
            'time'           => current_time( 'mysql' ),
            'name'           => $name,
            'email'          => $email,
            'event_type'     => $event,
            'event_date'     => $date,
            'event_location' => $loc,
            'message'        => $desc,
            'ip_address'     => $ip,
            'source_url'     => $url,
            'subject_prefix' => $subj_prefix,
            'status'         => $is_spam ? 'spam' : 'ok',
            'spam_reason'    => mb_substr( implode( ', ', $spam_reasons ), 0, 255 ),
        ) );

        $entry_id = (int) $wpdb->insert_id;

        if ( ! $saved ) {
            error_log( 'AV Contact Form: DB Error: ' . $wpdb->last_error );
            $this->msg( 'error', 'Andmebaasi viga.' );
            return;
        }

        // Spam: stored for review, no e-mails at all
        if ( $is_spam ) {
            $this->fake_success( $ip );
        }

        $to_source = ! empty( $args['to'] ) ? $args['to'] : get_option( 'av_recipient_email' );
        $to_emails = $this->sanitize_emails_list( $to_source );

        $reply_to = '';
        if ( ! empty( $args['reply_to'] ) ) {
            $reply_to = $args['reply_to'];
        } elseif ( ! empty( $args['to'] ) && ! empty( $to_emails ) ) {
            $reply_to = implode( ', ', $to_emails );
        }
        if ( empty( $reply_to ) ) $reply_to = get_option( 'av_reply_to_email' );
        if ( empty( $reply_to ) ) $reply_to = get_option( 'av_recipient_email' );

        $subject_text = ! empty( $subj_prefix ) ? '[' . $this->sanitize_header( $subj_prefix ) . '] ' : '';

        $admin_sent = $this->send_admin_email( $entry_id, $to_emails, $subject_text, $name, $email, $event, $date, $loc, $desc, $url );

        if ( ! $admin_sent ) {
            error_log( 'AV Contact Form: admin mail failed.' );
            $this->msg( 'error', 'Andmed salvestati, kuid e-maili saatmine ebaõnnestus.' );
            return;
        }

        // Confirmation is a courtesy – a failure here must not fail the submission
        $skip_reason = $this->confirmation_block_reason( $email );
        if ( $skip_reason ) {
            $this->log_mail( $entry_id, 'confirmation', $email, '', 'skipped', $skip_reason );
        } else {
            if ( ! $this->send_client_confirmation( $entry_id, $reply_to, $email ) ) {
                error_log( 'AV Contact Form: confirmation mail failed.' );
            }
        }

        set_transient( 'av_success_' . $this->fingerprint( $ip ), true, 60 );
        wp_redirect( add_query_arg( 'av_sent', '1', $this->get_current_url() ) );
        exit;
    }

    /**
     * Limits confirmation e-mails so the form can't be used to mail-bomb a third party.
     */
    private function confirmation_block_reason( $email ) {
        $per_email_key = 'av_conf_' . md5( strtolower( $email ) );
        if ( get_transient( $per_email_key ) ) {
            return 'Sellele aadressile on viimase ' . self::CONFIRM_PER_EMAIL_HOURS . ' h jooksul juba kinnitus saadetud';
        }

        $hour_key = 'av_conf_hour_' . gmdate( 'YmdH' );
        $sent_this_hour = (int) get_transient( $hour_key );
        if ( $sent_this_hour >= self::CONFIRM_GLOBAL_PER_HOUR ) {
            error_log( 'AV Contact Form: confirmation limit reached for this hour.' );
            return 'Tunnilimiit (' . self::CONFIRM_GLOBAL_PER_HOUR . ' kinnitust) täis';
        }

        set_transient( $per_email_key, 1, self::CONFIRM_PER_EMAIL_HOURS * HOUR_IN_SECONDS );
        set_transient( $hour_key, $sent_this_hour + 1, HOUR_IN_SECONDS );
        return '';
    }

    private function send_admin_email( $entry_id, $to_array, $prefix, $name, $email, $event, $date, $loc, $desc, $url ) {
        $safe_name = $this->sanitize_header( $name );

        $subject_parts = array();
        if ( ! empty( $event ) ) $subject_parts[] = $this->sanitize_header( $event );
        $subject_parts[] = "- $safe_name";

        $details = array();
        if ( ! empty( $date ) ) $details[] = $this->sanitize_header( $date );
        if ( ! empty( $loc ) ) $details[] = '@ ' . $this->sanitize_header( $loc );

        $details_str = ! empty( $details ) ? ' (' . implode( ' ', $details ) . ')' : '';
        $subject = $prefix . 'Päring: ' . implode( ' ', $subject_parts ) . $details_str;

        $body  = "Nimi: $name\n";
        $body .= "E-mail: $email\n";
        $body .= "Sündmus: $event\n";
        $body .= "Kuupäev: $date\n";
        $body .= "Koht: $loc\n\n";
        $body .= "Kirjeldus:\n$desc\n\n";
        $body .= "---------------------------\n";
        $body .= "Saadetud lehelt: $url";

        $headers = array( 'Content-Type: text/plain; charset=UTF-8', "Reply-To: $safe_name <$email>" );

        return $this->send_logged( $entry_id, 'admin', $to_array, $subject, $body, $headers );
    }

    /**
     * The confirmation contains NO text entered by the visitor (name, event...),
     * so it is worthless as a spam vehicle even if someone enters a third party's address.
     */
    private function send_client_confirmation( $entry_id, $reply_to, $email ) {
        if ( empty( $reply_to ) ) {
            $reply_to = get_option( 'av_recipient_email' );
        }

        $site_name = $this->sanitize_header( get_bloginfo( 'name' ) );
        $reply_to = $this->sanitize_header( $reply_to );
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        if ( strpos( $reply_to, ',' ) !== false ) {
            $headers[] = "Reply-To: $reply_to";
        } else {
            $headers[] = "Reply-To: $site_name <$reply_to>";
        }

        $body  = "Tere!\n\n";
        $body .= "Teie päring on kätte saadud. Vastan esimesel võimalusel.\n\n";
        $body .= "Lugupidamisega,\n$site_name\n\n";
        $body .= "---\n";
        $body .= "Saite selle kirja, sest teie e-posti aadress sisestati kontaktivormi lehel " . home_url() . ". ";
        $body .= "Kui see polnud teie, võite kirja lihtsalt kustutada.";

        return $this->send_logged( $entry_id, 'confirmation', $email, 'Kinnitus: päring vastu võetud', $body, $headers );
    }

    private function get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'];
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) return $ip;
        foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $header ) {
            if ( isset( $_SERVER[ $header ] ) ) return trim( explode( ',', $_SERVER[ $header ] )[0] );
        }
        return '0.0.0.0';
    }

    private function display_form() {
        $val_name    = esc_attr( $this->post_field( 'av_name' ) );
        $val_email   = esc_attr( $this->post_field( 'av_email' ) );
        $val_event   = esc_attr( $this->post_field( 'av_event' ) );
        $val_date    = esc_attr( $this->post_field( 'av_date' ) );
        $val_loc     = esc_attr( $this->post_field( 'av_location' ) );
        $val_desc    = esc_textarea( $this->post_field( 'av_desc' ) );
        $chk_consent = isset( $_POST['av_consent'] ) ? 'checked' : '';

        ?>
        <form method="post" class="av-contact-form" id="avContactForm" novalidate data-av-token="<?php echo esc_attr( $this->js_token() ); ?>">
            <?php wp_nonce_field( 'av_submit_form', '_av_nonce' ); ?>
            <div class="av-website-field" aria-hidden="true"><input type="text" name="av_website" tabindex="-1" autocomplete="off"></div>
            <input type="hidden" name="av_js" value="">

            <div class="av-group"><label>Nimi <span class="av-req">*</span></label><input type="text" name="av_name" value="<?php echo $val_name; ?>" required maxlength="<?php echo self::MAX_NAME_LENGTH; ?>"></div>
            <div class="av-group"><label>E-mail <span class="av-req">*</span></label><input type="email" name="av_email" value="<?php echo $val_email; ?>" required maxlength="100"></div>
            <div class="av-group"><label>Sündmus</label><input type="text" name="av_event" value="<?php echo $val_event; ?>" maxlength="<?php echo self::MAX_EVENT_LENGTH; ?>"></div>
            <div class="av-group"><label>Kuupäev</label><input type="text" name="av_date" value="<?php echo $val_date; ?>" class="av_datepicker_input" placeholder="PP.KK.AAAA" autocomplete="off"></div>
            <div class="av-group"><label>Koht</label><input type="text" name="av_location" value="<?php echo $val_loc; ?>" maxlength="<?php echo self::MAX_LOCATION_LENGTH; ?>"></div>
            <div class="av-group"><label>Kirjeldus <span class="char-counter"></span></label><textarea name="av_desc" rows="5" maxlength="<?php echo self::MAX_DESC_LENGTH; ?>"><?php echo $val_desc; ?></textarea></div>

            <div class="av-group av-checkbox-group" style="margin-top:15px;">
                <label style="display:inline; font-weight:normal;">
                    <input type="checkbox" name="av_consent" required <?php echo $chk_consent; ?>>
                    Olen nõus, et minu andmeid töödeldakse päringule vastamiseks.
                </label>
            </div>

            <?php if ( $this->turnstile_enabled() ) : ?>
            <div class="av-group">
                <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( get_option( 'av_turnstile_site_key' ) ); ?>" data-language="et" data-theme="light"></div>
            </div>
            <?php endif; ?>

            <div class="av-actions">
                <input type="submit" name="av_contact_submit" value="Saada päring" class="wp-element-button">
                <span class="av-loader" style="display:none;">⏳</span>
            </div>
            <p class="av-gdpr-note"><small>Isikuandmeid säilitatakse 1 aasta.</small></p>
        </form>
        <?php
    }

    /**
     * 5. ADMIN
     */
    public function add_admin_menu() {
        add_menu_page( 'Päringud', 'Päringud', 'av_view_submissions', 'av-contact-entries', array( $this, 'render_admin_page' ), 'dashicons-email', 26 );
        add_submenu_page( 'av-contact-entries', 'E-posti logi', 'E-posti logi', 'av_view_submissions', 'av-contact-mail-log', array( $this, 'render_mail_log_page' ) );
        add_submenu_page( 'av-contact-entries', 'Seaded', 'Seaded', 'manage_options', 'av-contact-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'av-contact-entries', 'Juhised', 'Juhised', 'manage_options', 'av-contact-instructions', array( $this, 'render_instructions_page' ) );
    }

    public function register_settings() {
        register_setting( 'av_contact_settings', 'av_recipient_email', 'sanitize_email' );
        register_setting( 'av_contact_settings', 'av_reply_to_email', 'sanitize_email' );
        register_setting( 'av_contact_settings', 'av_sender_email', 'sanitize_email' );
        register_setting( 'av_contact_settings', 'av_smtp_host', 'sanitize_text_field' );
        register_setting( 'av_contact_settings', 'av_smtp_port', 'absint' );
        register_setting( 'av_contact_settings', 'av_smtp_user', 'sanitize_text_field' );
        register_setting( 'av_contact_settings', 'av_smtp_pass', array( $this, 'sanitize_smtp_pass' ) );
        register_setting( 'av_contact_settings', 'av_turnstile_site_key', 'sanitize_text_field' );
        register_setting( 'av_contact_settings', 'av_turnstile_secret', 'sanitize_text_field' );
        register_setting( 'av_contact_settings', 'av_block_cyrillic', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => '1' ) );
    }

    public function sanitize_checkbox( $value ) {
        return empty( $value ) ? '0' : '1';
    }

    public function render_instructions_page() {
        ?>
        <div class="wrap">
            <h1>Kasutusjuhend (Versioon <?php echo esc_html( self::VERSION ); ?>)</h1>
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>1. Tavaline kasutamine</h2>
                <code style="display:block; padding:10px; background:#f0f0f1;">[av_contact_form]</code>
            </div>
            <div class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #00a0d2;">
                <h2>2. Mitme vormi kasutamine</h2>
                <code style="display:block; padding:10px; background:#f0f0f1;">[av_contact_form to="info@band.ee, manager@band.ee" subject="Bänd"]</code>
            </div>
            <div class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #46b450;">
                <h2>3. Spämmikaitse</h2>
                <ul style="list-style:disc; padding-left:20px;">
                    <li><strong>Cloudflare Turnstile</strong> – lisa Seadetes Site Key ja Secret Key (dash.cloudflare.com → Turnstile → Add widget, hostname andresvago.ee).</li>
                    <li><strong>JS- ja ajakontroll</strong> – alla <?php echo (int) self::MIN_FILL_SECONDS; ?> sekundiga või JavaScriptita saadetud vorm loetakse spämmiks.</li>
                    <li><strong>Sisufilter</strong> – lingid nimes/sündmuses/kohas, üle <?php echo (int) self::MAX_LINKS_IN_DESC; ?> lingi kirjelduses, kirillitsa (seadetes välja lülitatav), turundusspämmi märksõnad.</li>
                    <li>Spämm salvestatakse <strong>Spämm</strong> vahekaardile (hoitakse 60 päeva), e-kirju ei saadeta. Ekslikult märgitud päringu saab „Pole spämm“ nupuga tagasi tõsta.</li>
                    <li><strong>Kinnituskiri</strong> ei sisalda kasutaja sisestatud teksti; ühele aadressile max 1 kiri <?php echo (int) self::CONFIRM_PER_EMAIL_HOURS; ?> h jooksul ja kokku max <?php echo (int) self::CONFIRM_GLOBAL_PER_HOUR; ?> kirja tunnis.</li>
                </ul>
            </div>
            <div class="card" style="margin-top:20px; padding:10px; background:#fff8e5; border-left:4px solid #ffba00;">
                <strong>Zone.ee SMTP Seaded:</strong> Host: <code>localhost</code>, Port: <code>25</code>, Parool: (tühi).
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        $has_pass = (bool) get_option( 'av_smtp_pass' );
        ?>
        <div class="wrap">
            <h1>Kontaktivormi Seaded</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'av_contact_settings' ); do_settings_sections( 'av_contact_settings' ); ?>
                <h2>Üldseaded</h2>
                <table class="form-table">
                    <tr><th>Vaikimisi saaja:</th><td><input type="email" name="av_recipient_email" value="<?php echo esc_attr( get_option( 'av_recipient_email' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>Vaikimisi vastamise aadress (Reply-To):</th><td><input type="email" name="av_reply_to_email" value="<?php echo esc_attr( get_option( 'av_reply_to_email' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>Saatja aadress (From):</th><td><input type="email" name="av_sender_email" value="<?php echo esc_attr( get_option( 'av_sender_email', 'noreply@' . $_SERVER['SERVER_NAME'] ) ); ?>" class="regular-text"></td></tr>
                </table>
                <hr>
                <h2>Spämmikaitse</h2>
                <table class="form-table">
                    <tr><th>Cloudflare Turnstile Site Key:</th><td><input type="text" name="av_turnstile_site_key" value="<?php echo esc_attr( get_option( 'av_turnstile_site_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>Cloudflare Turnstile Secret Key:</th><td><input type="password" name="av_turnstile_secret" value="<?php echo esc_attr( get_option( 'av_turnstile_secret' ) ); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Mõlemad võtmed täidetud = Turnstile on sees. Tühjenda, et välja lülitada.</p></td></tr>
                    <tr><th>Kirillitsa filter:</th><td><label><input type="hidden" name="av_block_cyrillic" value="0"><input type="checkbox" name="av_block_cyrillic" value="1" <?php checked( get_option( 'av_block_cyrillic', '1' ), '1' ); ?>> Märgi spämmiks päringud, milles on kirillitsas teksti või .ru/.su e-posti aadress</label></td></tr>
                </table>
                <hr>
                <h2>SMTP Seaded</h2>
                <table class="form-table">
                    <tr><th>SMTP Host:</th><td><input type="text" name="av_smtp_host" value="<?php echo esc_attr( get_option( 'av_smtp_host' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>SMTP Port:</th><td><input type="number" name="av_smtp_port" value="<?php echo esc_attr( get_option( 'av_smtp_port', 25 ) ); ?>" class="small-text"></td></tr>
                    <tr><th>SMTP Kasutaja:</th><td><input type="text" name="av_smtp_user" value="<?php echo esc_attr( get_option( 'av_smtp_user' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>SMTP Parool:</th><td><input type="password" name="av_smtp_pass" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_pass ? '•••••• (salvestatud – jäta tühjaks, et mitte muuta)' : ''; ?>">
                        <?php if ( $has_pass ) : ?><br><label><input type="checkbox" name="av_smtp_pass_clear" value="1"> Kustuta salvestatud parool</label><?php endif; ?></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function handle_delete_entry() {
        if ( ! current_user_can( 'av_view_submissions' ) ) wp_die( 'Õigused puuduvad' );
        check_admin_referer( 'av_delete_action' );
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $view = ( isset( $_GET['view'] ) && $_GET['view'] === 'spam' ) ? 'spam' : 'ok';
        if ( $id ) { global $wpdb; $wpdb->delete( $this->table_name, array( 'id' => $id ) ); }
        wp_redirect( admin_url( 'admin.php?page=av-contact-entries&view=' . $view . '&msg=deleted' ) ); exit;
    }

    public function handle_mark_entry() {
        if ( ! current_user_can( 'av_view_submissions' ) ) wp_die( 'Õigused puuduvad' );
        check_admin_referer( 'av_mark_action' );
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $to = ( isset( $_GET['to'] ) && $_GET['to'] === 'spam' ) ? 'spam' : 'ok';
        if ( $id ) {
            global $wpdb;
            $wpdb->update( $this->table_name, array( 'status' => $to, 'spam_reason' => $to === 'spam' ? 'manual' : '' ), array( 'id' => $id ) );
        }
        $back = $to === 'spam' ? 'ok' : 'spam';
        wp_redirect( admin_url( 'admin.php?page=av-contact-entries&view=' . $back . '&msg=moved' ) ); exit;
    }

    public function render_admin_page() {
        global $wpdb;
        $view = ( isset( $_GET['view'] ) && $_GET['view'] === 'spam' ) ? 'spam' : 'ok';
        $entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE status = %s ORDER BY time DESC", $view ) );
        $spam_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'spam'" );
        $ok_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'ok'" );
        $base = admin_url( 'admin.php?page=av-contact-entries' );

        if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'deleted' ) echo '<div class="notice notice-success"><p>Kirje kustutatud.</p></div>';
        if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'moved' ) echo '<div class="notice notice-success"><p>Kirje teisaldatud.</p></div>';
        ?>
        <div class="wrap">
            <h1>Sissetulnud Päringud</h1>
            <ul class="subsubsub">
                <li><a href="<?php echo esc_url( $base . '&view=ok' ); ?>" class="<?php echo $view === 'ok' ? 'current' : ''; ?>">Päringud <span class="count">(<?php echo $ok_count; ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( $base . '&view=spam' ); ?>" class="<?php echo $view === 'spam' ? 'current' : ''; ?>">Spämm <span class="count">(<?php echo $spam_count; ?>)</span></a></li>
            </ul>
            <div class="av-table-container" style="clear:both;">
            <table class="wp-list-table widefat fixed striped av-responsive-table">
                <thead><tr><th width="120">Kuupäev</th><th>Nimi / Email</th><th>Sündmus</th><th>Koht</th><th><?php echo $view === 'spam' ? 'Põhjus' : 'Allikas'; ?></th><th>Tegevused</th></tr></thead>
                <tbody>
                    <?php if ( ! empty( $entries ) ) : foreach ( $entries as $entry ) : ?>
                    <tr>
                        <td data-label="Kuupäev"><?php echo esc_html( $entry->time ); ?></td>
                        <td data-label="Nimi"><strong><?php echo esc_html( $entry->name ); ?></strong><br><a href="mailto:<?php echo esc_attr( $entry->email ); ?>"><?php echo esc_html( $entry->email ); ?></a></td>
                        <td data-label="Sündmus"><?php echo esc_html( $entry->event_type ); ?><br><small><?php echo esc_html( $entry->event_date ); ?></small></td>
                        <td data-label="Koht"><?php echo esc_html( $entry->event_location ); ?></td>
                        <td data-label="<?php echo $view === 'spam' ? 'Põhjus' : 'Allikas'; ?>">
                            <?php if ( $view === 'spam' ) : ?>
                                <small><?php echo esc_html( $entry->spam_reason ); ?></small><br><small><?php echo esc_html( $entry->ip_address ); ?></small>
                            <?php else :
                                $link_text = ! empty( $entry->subject_prefix ) ? $entry->subject_prefix : 'Kitarr'; ?>
                                <a href="<?php echo esc_url( $entry->source_url ); ?>" target="_blank"><?php echo esc_html( $link_text ); ?></a>
                            <?php endif; ?>
                        </td>
                        <td data-label="Tegevused">
                            <button class="button av-toggle-msg">Vaata</button>
                            <?php if ( $view === 'spam' ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=av_mark_entry&to=ok&id=' . $entry->id ), 'av_mark_action' ) ); ?>" class="button">Pole spämm</a>
                            <?php else : ?>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=av_mark_entry&to=spam&id=' . $entry->id ), 'av_mark_action' ) ); ?>" class="button">Spämm</a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=av_delete_entry&view=' . $view . '&id=' . $entry->id ), 'av_delete_action' ) ); ?>" class="button button-link-delete" onclick="return confirm('Kustuta?');">X</a>
                            <div class="av-msg-content" style="display:none; margin-top:10px; background:#fff; padding:10px; border:1px solid #ddd;"><?php echo nl2br( esc_html( $entry->message ) ); ?></div>
                        </td>
                    </tr>
                    <?php endforeach; else : ?><tr><td colspan="6"><?php echo $view === 'spam' ? 'Spämmi pole.' : 'Päringud puuduvad.'; ?></td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    public function handle_clear_mail_log() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Õigused puuduvad' );
        check_admin_referer( 'av_clear_mail_log' );
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->mail_log_table}" );
        wp_redirect( admin_url( 'admin.php?page=av-contact-mail-log&msg=cleared' ) ); exit;
    }

    public function render_mail_log_page() {
        global $wpdb;
        $filter = ( isset( $_GET['status'] ) && in_array( $_GET['status'], array( 'sent', 'failed', 'skipped' ), true ) ) ? $_GET['status'] : '';
        $where = $filter ? $wpdb->prepare( 'WHERE l.status = %s', $filter ) : '';
        $rows = $wpdb->get_results(
            "SELECT l.*, e.name AS entry_name
             FROM {$this->mail_log_table} l LEFT JOIN {$this->table_name} e ON e.id = l.entry_id
             $where ORDER BY l.time DESC, l.id DESC LIMIT 200"
        );
        $counts = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$this->mail_log_table} GROUP BY status", OBJECT_K );
        $base = admin_url( 'admin.php?page=av-contact-mail-log' );
        $labels = array( 'sent' => 'Saadetud', 'failed' => 'Ebaõnnestus', 'skipped' => 'Vahele jäetud' );
        $colors = array( 'sent' => '#46b450', 'failed' => '#dc3232', 'skipped' => '#999' );
        $types = array( 'admin' => 'Teavitus', 'confirmation' => 'Kinnitus' );

        if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'cleared' ) echo '<div class="notice notice-success"><p>Logi tühjendatud.</p></div>';
        ?>
        <div class="wrap">
            <h1>E-posti logi</h1>
            <p>Kõik selle vormi saadetud kirjad (viimased 200). Spämmiks märgitud päringutele kirju ei saadeta.</p>
            <ul class="subsubsub">
                <li><a href="<?php echo esc_url( $base ); ?>" class="<?php echo $filter === '' ? 'current' : ''; ?>">Kõik</a> |</li>
                <?php $i = 0; foreach ( $labels as $k => $label ) : $i++; ?>
                    <li><a href="<?php echo esc_url( $base . '&status=' . $k ); ?>" class="<?php echo $filter === $k ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?> <span class="count">(<?php echo isset( $counts[ $k ] ) ? (int) $counts[ $k ]->c : 0; ?>)</span></a><?php echo $i < count( $labels ) ? ' |' : ''; ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="av-table-container" style="clear:both;">
            <table class="wp-list-table widefat fixed striped av-responsive-table">
                <thead><tr><th width="140">Aeg</th><th width="90">Tüüp</th><th>Saaja</th><th>Teema</th><th width="160">Staatus</th><th>Päring</th></tr></thead>
                <tbody>
                <?php if ( $rows ) : foreach ( $rows as $r ) : ?>
                    <tr>
                        <td data-label="Aeg"><?php echo esc_html( $r->time ); ?></td>
                        <td data-label="Tüüp"><?php echo esc_html( isset( $types[ $r->type ] ) ? $types[ $r->type ] : $r->type ); ?></td>
                        <td data-label="Saaja"><?php echo esc_html( $r->recipient ); ?></td>
                        <td data-label="Teema"><?php echo esc_html( $r->subject ); ?></td>
                        <td data-label="Staatus">
                            <strong style="color:<?php echo esc_attr( isset( $colors[ $r->status ] ) ? $colors[ $r->status ] : '#000' ); ?>"><?php echo esc_html( isset( $labels[ $r->status ] ) ? $labels[ $r->status ] : $r->status ); ?></strong>
                            <?php if ( $r->error !== '' ) : ?><br><small><?php echo esc_html( $r->error ); ?></small><?php endif; ?>
                        </td>
                        <td data-label="Päring"><?php echo $r->entry_name !== null ? esc_html( '#' . $r->entry_id . ' ' . $r->entry_name ) : '<em>kustutatud</em>'; ?></td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="6">Logi on tühi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php if ( current_user_can( 'manage_options' ) && $rows ) : ?>
                <p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=av_clear_mail_log' ), 'av_clear_mail_log' ) ); ?>" class="button" onclick="return confirm('Tühjenda kogu logi?');">Tühjenda logi</a></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
new AV_Contact_Manager();
