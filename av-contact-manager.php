<?php
/**
 * Plugin Name: AV Contact Manager
 * Description: V6.8 - Subject Prefix saved to DB. Admin table shows Subject instead of "Link".
 * Version: 6.8
 * Author: Silver Sirp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AV_Contact_Manager {

    private $table_name;
    
    // Constants
    const MAX_NAME_LENGTH = 100;
    const MAX_EVENT_LENGTH = 100;
    const MAX_LOCATION_LENGTH = 150;
    const MAX_DESC_LENGTH = 5000;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'av_contact_entries';

        // Lifecycle Hooks
        register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );
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

        // SMTP
        add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
    }

    /**
     * 1. DATABASE
     */
    public function activate_plugin() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // UUS VEERG: subject_prefix
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
            PRIMARY KEY  (id),
            KEY time_index (time),
            KEY email_index (email(50))
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        if ( ! wp_next_scheduled( 'av_daily_cleanup_event' ) ) {
            wp_schedule_event( time(), 'daily', 'av_daily_cleanup_event' );
        }

        $role = get_role( 'administrator' );
        if ( $role ) {
            $role->add_cap( 'av_view_submissions' );
        }
    }

    public function deactivate_plugin() {
        wp_clear_scheduled_hook( 'av_daily_cleanup_event' );
    }

    public function cleanup_old_entries() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE time < %s", date( 'Y-m-d H:i:s', strtotime( '-1 year' ) ) ) );
    }

    /**
     * 2. ASSETS & HEADERS
     */
    public function send_csp_header() {
        global $post;

        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'av_contact_form' ) ) {

            header(
                "Content-Security-Policy: " .
                "default-src 'self'; " .
                "script-src 'self' 'unsafe-inline' https://ajax.googleapis.com https://www.youtube.com https://www.googletagmanager.com https://www.google-analytics.com; " .
                "style-src 'self' 'unsafe-inline' https://ajax.googleapis.com; " .
                "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com; " .
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
            wp_enqueue_script( 'av-script', plugins_url( 'js/av-script.js', __FILE__ ), array('jquery', 'jquery-ui-datepicker'), '6.6', true );
            wp_enqueue_style( 'jquery-ui-style', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css' );
            wp_enqueue_style( 'av-style', plugins_url( 'css/av-style.css', __FILE__ ), array(), '6.6' );
        }
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'av-contact' ) !== false ) {
            wp_enqueue_style( 'av-admin-style', plugins_url( 'css/av-admin.css', __FILE__ ), array(), '3.0' );
            wp_enqueue_script( 'av-admin-script', plugins_url( 'js/av-admin.js', __FILE__ ), array('jquery'), '1.0', true );
        }
    }

    /**
     * 3. SMTP CONFIG
     */
    
    public function sanitize_smtp_pass( $raw_pass ) {
        if ( empty( $raw_pass ) ) return '';
        $key = wp_salt( 'auth' );
        $iv = substr( wp_salt( 'secure_auth' ), 0, 16 );
        $encrypted = openssl_encrypt( $raw_pass, 'AES-256-CBC', $key, 0, $iv );
        return base64_encode( $encrypted );
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

    private function check_rate_limit( $ip ) {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $fingerprint = md5( $ip . $ua ); 
        $transient = 'av_sub_' . $fingerprint;
        
        $attempts = get_transient( $transient );
        if ( $attempts === false ) { set_transient( $transient, 1, 600 ); return true; }
        if ( $attempts >= 3 ) { set_transient( $transient, $attempts + 1, 3600 ); return false; }
        set_transient( $transient, $attempts + 1, 600 );
        return true;
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
            $ip = $this->get_client_ip();
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            $fingerprint = md5( $ip . $ua );
            
            if ( get_transient( 'av_success_' . $fingerprint ) ) {
                echo '<div id="av-result-message" class="av-msg success">Teie päring on edukalt saadetud! Kinnitus on teie e-mailil.</div>';
                delete_transient( 'av_success_' . $fingerprint );
            }
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['_av_nonce'] ) ) {
            $this->handle_submission( $args );
        }
        
        $this->display_form();
        return ob_get_clean();
    }

    private function handle_submission( $args ) {
        function av_msg($type, $text) {
            echo '<div id="av-result-message" class="av-msg ' . $type . '">' . $text . '</div>';
        }

        if ( ! wp_verify_nonce( $_POST['_av_nonce'], 'av_submit_form' ) ) {
            av_msg('error', 'Turvaviga. Palun laadige leht uuesti.'); return;
        }
        if ( ! empty( $_POST['av_website'] ) ) { av_msg('success', 'Saadetud!'); return; }
        
        if ( ! isset( $_POST['av_consent'] ) ) {
            av_msg('error', 'Palun nõustuge andmete töötlemise tingimustega.'); return;
        }

        if ( empty( $args['to'] ) && empty( get_option( 'av_recipient_email' ) ) ) {
            error_log( 'AV Contact Form: recipient email is not configured.' );
            av_msg('error', 'Vorm ei ole seadistatud. Palun võtke ühendust lehe haldajaga.'); return;
        }

        $ip = $this->get_client_ip();
        if ( ! $this->check_rate_limit( $ip ) ) {
            av_msg('error', 'Liiga palju päringuid. Oodake 1 tund.'); return;
        }

        $name     = substr( sanitize_text_field( $_POST['av_name'] ), 0, self::MAX_NAME_LENGTH );
        $email    = sanitize_email( $_POST['av_email'] );
        $event    = substr( sanitize_text_field( $_POST['av_event'] ), 0, self::MAX_EVENT_LENGTH );
        $date     = sanitize_text_field( $_POST['av_date'] );
        $loc      = substr( sanitize_text_field( $_POST['av_location'] ), 0, self::MAX_LOCATION_LENGTH );
        $desc     = substr( sanitize_textarea_field( $_POST['av_desc'] ), 0, self::MAX_DESC_LENGTH );
        $url      = $this->get_current_url();
        // Salvesta teema eesliide või jäta tühjaks
        $subj_prefix = !empty($args['subject']) ? sanitize_text_field($args['subject']) : '';

        if ( empty( $name ) || empty( $email ) || ! is_email( $email ) ) {
            av_msg('error', 'Palun kontrollige välju.'); return;
        }

        if ( ! empty( $date ) && ! preg_match( '/^\d{2}\.\d{2}\.\d{4}$/', $date ) ) {
            av_msg('error', 'Kuupäev peab olema formaadis PP.KK.AAAA'); return;
        }

        global $wpdb;
        $saved = $wpdb->insert( $this->table_name, array(
            'time' => current_time( 'mysql' ), 
            'name' => $name, 
            'email' => $email,
            'event_type' => $event, 
            'event_date' => $date, 
            'event_location' => $loc,
            'message' => $desc, 
            'ip_address' => $ip, 
            'source_url' => $url,
            'subject_prefix' => $subj_prefix // Uus veerg andmebaasis
        ));

        if ( $saved ) {
            $to_source = !empty( $args['to'] ) ? $args['to'] : get_option( 'av_recipient_email' );
            $to_emails = $this->sanitize_emails_list( $to_source );

            $reply_to = '';
            if ( !empty( $args['reply_to'] ) ) {
                $reply_to = $args['reply_to'];
            } elseif ( !empty( $args['to'] ) ) {
                if ( !empty($to_emails) ) {
                    $reply_to = implode( ', ', $to_emails );
                }
            }
            if ( empty( $reply_to ) ) {
                $reply_to = get_option( 'av_reply_to_email' );
            }
            if ( empty( $reply_to ) ) {
                $reply_to = get_option( 'av_recipient_email' );
            }

            $subject_text = !empty( $subj_prefix ) ? '[' . $this->sanitize_header($subj_prefix) . '] ' : '';

            $admin_sent = $this->send_admin_email( $to_emails, $subject_text, $name, $email, $event, $date, $loc, $desc, $url );
            $client_sent = $this->send_client_confirmation( $reply_to, $name, $email, $event, $date );

            if ( $admin_sent && $client_sent ) {
                $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
                $fingerprint = md5( $ip . $ua );
                set_transient( 'av_success_' . $fingerprint, true, 60 );
                wp_redirect( add_query_arg( 'av_sent', '1', $this->get_current_url() ) );
                exit;
            } else {
                error_log( 'AV Contact Form: Mail Error.' );
                av_msg('error', 'Andmed salvestati, kuid e-maili saatmine ebaõnnestus.');
            }
        } else {
            error_log( 'AV Contact Form: DB Error: ' . $wpdb->last_error );
            av_msg('error', 'Andmebaasi viga.');
        }
    }

    private function send_admin_email( $to_array, $prefix, $name, $email, $event, $date, $loc, $desc, $url ) {
        $safe_name = $this->sanitize_header( $name );
        
        $subject_parts = [];
        if ( !empty($event) ) $subject_parts[] = $this->sanitize_header($event);
        $subject_parts[] = "- $safe_name";
        
        $details = [];
        if ( !empty($date) ) $details[] = $this->sanitize_header($date);
        if ( !empty($loc) ) $details[] = "@ " . $this->sanitize_header($loc);
        
        $details_str = !empty($details) ? ' (' . implode(' ', $details) . ')' : '';
        $subject = $prefix . "Päring: " . implode(' ', $subject_parts) . $details_str;
        
        $body  = "Nimi: $name\n";
        $body .= "E-mail: $email\n";
        $body .= "Sündmus: $event\n";
        $body .= "Kuupäev: $date\n";
        $body .= "Koht: $loc\n\n";
        $body .= "Kirjeldus:\n$desc\n\n";
        $body .= "---------------------------\n";
        $body .= "Saadetud lehelt: $url";

        $headers = array( 'Content-Type: text/plain; charset=UTF-8', "Reply-To: $safe_name <$email>" );
        
        return wp_mail( $to_array, $subject, $body, $headers );
    }

    private function send_client_confirmation( $reply_to, $name, $email, $event, $date ) {
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

        $body = "Tere, $name\n\nOlen teie päringu ($event, $date) kätte saanud ja vastan esimesel võimalusel.\n\nLugupidamisega,\n$site_name";
        return wp_mail( $email, "Kinnitus: Päring vastu võetud", $body, $headers );
    }

    private function get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'];
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) return $ip;
        foreach ( ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header ) {
            if ( isset( $_SERVER[$header] ) ) return trim( explode( ',', $_SERVER[$header] )[0] );
        }
        return '0.0.0.0';
    }

    private function display_form() {
        $val_name = isset($_POST['av_name']) ? esc_attr($_POST['av_name']) : '';
        $val_email = isset($_POST['av_email']) ? esc_attr($_POST['av_email']) : '';
        $val_event = isset($_POST['av_event']) ? esc_attr($_POST['av_event']) : '';
        $val_date = isset($_POST['av_date']) ? esc_attr($_POST['av_date']) : '';
        $val_loc = isset($_POST['av_location']) ? esc_attr($_POST['av_location']) : '';
        $val_desc = isset($_POST['av_desc']) ? esc_textarea($_POST['av_desc']) : '';
        $chk_consent = isset($_POST['av_consent']) ? 'checked' : '';

        ?>
        <form method="post" class="av-contact-form" id="avContactForm" novalidate>
            <?php wp_nonce_field( 'av_submit_form', '_av_nonce' ); ?>
            <div class="av-website-field"><input type="text" name="av_website" tabindex="-1" autocomplete="off"></div>

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

            <div class="av-actions">
                <input type="submit" name="av_contact_submit" value="Saada päring" class="wp-element-button">
                <span class="av-loader" style="display:none;">⏳</span>
            </div>
            <p class="av-gdpr-note"><small>Isikuandmeid säilitatakse 1 aasta.</small></p>
        </form>
        <?php
    }

    public function add_admin_menu() {
        add_menu_page( 'Päringud', 'Päringud', 'av_view_submissions', 'av-contact-entries', array( $this, 'render_admin_page' ), 'dashicons-email', 26 );
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
    }

    public function render_instructions_page() {
        ?>
        <div class="wrap">
            <h1>Kasutusjuhend (Versioon 6.7)</h1>
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>1. Tavaline kasutamine</h2>
                <code style="display:block; padding:10px; background:#f0f0f1;">[av_contact_form]</code>
            </div>
            <div class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #00a0d2;">
                <h2>2. Mitme vormi kasutamine</h2>
                <code style="display:block; padding:10px; background:#f0f0f1;">[av_contact_form to="info@band.ee, manager@band.ee" subject="Bänd"]</code>
            </div>
            <div class="card" style="margin-top:20px; padding:10px; background:#fff8e5; border-left:4px solid #ffba00;">
                <strong>Zone.ee SMTP Seaded:</strong> Host: <code>localhost</code>, Port: <code>25</code>, Parool: (tühi).
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Kontaktivormi Seaded</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'av_contact_settings' ); do_settings_sections( 'av_contact_settings' ); ?>
                <h2>Üldseaded</h2>
                <table class="form-table">
                    <tr><th>Vaikimisi saaja:</th><td><input type="email" name="av_recipient_email" value="<?php echo esc_attr( get_option('av_recipient_email') ); ?>" class="regular-text"></td></tr>
                    <tr><th>Vaikimisi vastamise aadress (Reply-To):</th><td><input type="email" name="av_reply_to_email" value="<?php echo esc_attr( get_option('av_reply_to_email') ); ?>" class="regular-text"></td></tr>
                    <tr><th>Saatja aadress (From):</th><td><input type="email" name="av_sender_email" value="<?php echo esc_attr( get_option('av_sender_email', 'noreply@' . $_SERVER['SERVER_NAME']) ); ?>" class="regular-text"></td></tr>
                </table>
                <hr>
                <h2>SMTP Seaded</h2>
                <table class="form-table">
                    <tr><th>SMTP Host:</th><td><input type="text" name="av_smtp_host" value="<?php echo esc_attr( get_option('av_smtp_host') ); ?>" class="regular-text"></td></tr>
                    <tr><th>SMTP Port:</th><td><input type="number" name="av_smtp_port" value="<?php echo esc_attr( get_option('av_smtp_port', 25) ); ?>" class="small-text"></td></tr>
                    <tr><th>SMTP Kasutaja:</th><td><input type="text" name="av_smtp_user" value="<?php echo esc_attr( get_option('av_smtp_user') ); ?>" class="regular-text"></td></tr>
                    <tr><th>SMTP Parool:</th><td><input type="password" name="av_smtp_pass" value="<?php echo esc_attr( get_option('av_smtp_pass') ); ?>" class="regular-text"></td></tr>
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
        if ( $id ) { global $wpdb; $wpdb->delete( $this->table_name, array( 'id' => $id ) ); }
        wp_redirect( admin_url( 'admin.php?page=av-contact-entries&msg=deleted' ) ); exit;
    }

    public function render_admin_page() {
        global $wpdb;
        $query = "SELECT * FROM {$this->table_name} ORDER BY time DESC";
        $entries = $wpdb->get_results( $query ); 
        
        if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'deleted' ) echo '<div class="notice notice-success"><p>Kirje kustutatud.</p></div>';
        ?>
        <div class="wrap">
            <h1>Sissetulnud Päringud</h1>
            <div class="av-table-container">
            <table class="wp-list-table widefat fixed striped av-responsive-table">
                <thead><tr><th width="120">Kuupäev</th><th>Nimi / Email</th><th>Sündmus</th><th>Koht</th><th>Allikas</th><th>Tegevused</th></tr></thead>
                <tbody>
                    <?php if ( ! empty( $entries ) ) : foreach ( $entries as $entry ) : ?>
                    <tr>
                        <td data-label="Kuupäev"><?php echo esc_html( $entry->time ); ?></td>
                        <td data-label="Nimi"><strong><?php echo esc_html( $entry->name ); ?></strong><br><a href="mailto:<?php echo esc_attr( $entry->email ); ?>"><?php echo esc_html( $entry->email ); ?></a></td>
                        <td data-label="Sündmus"><?php echo esc_html( $entry->event_type ); ?><br><small><?php echo esc_html( $entry->event_date ); ?></small></td>
                        <td data-label="Koht"><?php echo esc_html( $entry->event_location ); ?></td>
                        <td data-label="Allikas">
                            <?php 
                                $link_text = !empty($entry->subject_prefix) ? esc_html($entry->subject_prefix) : 'Kitarr';
                            ?>
                            <a href="<?php echo esc_url( $entry->source_url ); ?>" target="_blank"><?php echo $link_text; ?></a>
                        </td>
                        <td data-label="Tegevused">
                            <button class="button av-toggle-msg">Vaata</button>
                            <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=av_delete_entry&id=' . $entry->id), 'av_delete_action' ); ?>" class="button button-link-delete" onclick="return confirm('Kustuta?');">X</a>
                            <div class="av-msg-content" style="display:none; margin-top:10px; background:#fff; padding:10px; border:1px solid #ddd;"><?php echo nl2br( esc_html( $entry->message ) ); ?></div>
                        </td>
                    </tr>
                    <?php endforeach; else : ?><tr><td colspan="6">Päringud puuduvad.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }
}
new AV_Contact_Manager();