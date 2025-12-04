<?php
/**
 * Plugin Name: AV Contact Manager
 * Description: V3.0 - Secure form with Datepicker, Delete capability, Responsive Admin, and GDPR compliance.
 * Version: 3.0
 * Author: Silver Sirp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AV_Contact_Manager {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'av_contact_entries';

        // Installation
        register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );
        
        // GDPR Schedule
        add_action( 'av_daily_cleanup_event', array( $this, 'cleanup_old_entries' ) );

        // Frontend
        add_shortcode( 'av_contact_form', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // Admin
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        
        // Handle Delete Action
        add_action( 'admin_post_av_delete_entry', array( $this, 'handle_delete_entry' ) );
    }

    /**
     * ACTIVATION & DB
     */
    public function activate_plugin() {
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

    // GDPR: Delete entries older than 1 year
    public function cleanup_old_entries() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE time < %s", date( 'Y-m-d H:i:s', strtotime( '-1 year' ) ) ) );
    }

    /**
     * ASSETS (CSS/JS)
     */
    public function enqueue_frontend_assets() {
        global $post;
        // Conditional Loading: Only if shortcode is present
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'av_contact_form' ) ) {
            
            // CSP Header
            header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://ajax.googleapis.com; style-src 'self' 'unsafe-inline' https://ajax.googleapis.com;" );

            // Scripts
            wp_enqueue_script( 'jquery-ui-datepicker' );
            wp_enqueue_script( 'av-script', plugins_url( 'js/av-script.js', __FILE__ ), array('jquery', 'jquery-ui-datepicker'), '3.0', true );

            // Styles
            wp_enqueue_style( 'jquery-ui-style', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css' );
            wp_enqueue_style( 'av-style', plugins_url( 'css/av-style.css', __FILE__ ), array(), '3.0' );
            
            // Pass localized data to JS
            wp_localize_script( 'av-script', 'av_vars', array(
                'ajax_url' => admin_url( 'admin-ajax.php' )
            ));
        }
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_av-contact-entries' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'av-admin-style', plugins_url( 'css/av-admin.css', __FILE__ ), array(), '3.0' );
    }

    /**
     * FRONTEND LOGIC
     */
    private function get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'];
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) return $ip;
        foreach ( ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header ) {
            if ( isset( $_SERVER[$header] ) ) {
                $ip = trim( explode( ',', $_SERVER[$header] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '0.0.0.0';
    }

    private function check_rate_limit( $ip ) {
        $transient = 'av_sub_' . md5( $ip );
        $attempts = get_transient( $transient );
        if ( $attempts === false ) { set_transient( $transient, 1, 600 ); return true; }
        if ( $attempts >= 3 ) { set_transient( $transient, $attempts + 1, 3600 ); return false; }
        set_transient( $transient, $attempts + 1, 600 );
        return true;
    }

    public function render_shortcode() {
        ob_start();
        if ( isset( $_POST['av_contact_submit'] ) ) {
            $this->handle_submission();
        }
        $this->display_form();
        return ob_get_clean();
    }

    private function handle_submission() {
        if ( ! isset( $_POST['_av_nonce'] ) || ! wp_verify_nonce( $_POST['_av_nonce'], 'av_submit_form' ) ) {
            echo '<div class="av-msg error">Turvaviga. Laadige leht uuesti.</div>'; return;
        }
        if ( ! empty( $_POST['av_website'] ) ) { echo '<div class="av-msg success">Saadetud!</div>'; return; } // Honeypot
        
        $ip = $this->get_client_ip();
        if ( ! $this->check_rate_limit( $ip ) ) {
            echo '<div class="av-msg error">Liiga palju päringuid. Oodake 1 tund.</div>'; return;
        }

        $name = substr( sanitize_text_field( $_POST['av_name'] ), 0, 100 );
        $email = sanitize_email( $_POST['av_email'] );
        $event = substr( sanitize_text_field( $_POST['av_event'] ), 0, 100 );
        $date = sanitize_text_field( $_POST['av_date'] );
        $loc = substr( sanitize_text_field( $_POST['av_location'] ), 0, 150 );
        $desc = substr( sanitize_textarea_field( $_POST['av_desc'] ), 0, 5000 );

        if ( empty( $name ) || empty( $email ) || ! is_email( $email ) ) {
            echo '<div class="av-msg error">Vigased andmed. Kontrollige välju.</div>'; return;
        }
        if ( ! empty( $date ) && ! preg_match( '/^\d{2}\.\d{2}\.\d{4}$/', $date ) ) {
            echo '<div class="av-msg error">Kuupäev peab olema PP.KK.AAAA</div>'; return;
        }

        global $wpdb;
        $saved = $wpdb->insert( $this->table_name, array(
            'time' => current_time( 'mysql' ), 'name' => $name, 'email' => $email,
            'event_type' => $event, 'event_date' => $date, 'event_location' => $loc,
            'message' => $desc, 'ip_address' => $ip
        ));

        if ( $saved ) {
            // Verify Email Delivery
            $admin_sent = $this->send_admin_email( $name, $email, $event, $date, $loc, $desc );
            $client_sent = $this->send_client_confirmation( $name, $email, $event, $date );

            if ( $admin_sent && $client_sent ) {
                echo '<div class="av-msg success">Päring saadetud! Kinnitus on teie e-mailil.</div>';
            } elseif ( $admin_sent ) {
                echo '<div class="av-msg success">Päring saadetud, kuid kinnituskirja ei õnnestunud saata.</div>';
            } else {
                echo '<div class="av-msg error">Andmed salvestati, kuid e-maili saatmine ebaõnnestus.</div>';
                error_log( 'AV Plugin: Mail sending failed.' );
            }
        } else {
            echo '<div class="av-msg error">Andmebaasi viga.</div>';
        }
    }

    private function send_admin_email( $name, $email, $event, $date, $loc, $desc ) {
        $to = get_option( 'av_recipient_email', 'andres.vago@gmail.com' );
        $safe_name = str_replace( ["\r", "\n"], '', $name );
        $headers = array( 'Content-Type: text/plain; charset=UTF-8', "Reply-To: $safe_name <$email>" );
        $body = "Nimi: $name\nE-mail: $email\nSündmus: $event\nKuupäev: $date\nKoht: $loc\n\nKirjeldus:\n$desc";
        return wp_mail( $to, "Päring: $event ($date)", $body, $headers );
    }

    private function send_client_confirmation( $name, $email, $event, $date ) {
        $from = get_option( 'av_recipient_email', 'andres.vago@gmail.com' );
        $headers = array( 'Content-Type: text/plain; charset=UTF-8', "From: Andres Vago <$from>" );
        $body = "Tere $name,\n\nOlen teie päringu ($event, $date) kätte saanud ja vastan esimesel võimalusel.\n\nLugupidamisega,\nAndres Vago";
        return wp_mail( $email, "Kinnitus: Päring vastu võetud", $body, $headers );
    }

    private function display_form() {
        ?>
        <form method="post" class="av-contact-form" id="avContactForm" novalidate>
            <?php wp_nonce_field( 'av_submit_form', '_av_nonce' ); ?>
            <div class="av-website-field"><input type="text" name="av_website" tabindex="-1" autocomplete="off"></div>

            <div class="av-group">
                <label>Nimi <span class="av-req">*</span></label>
                <input type="text" name="av_name" required maxlength="100">
            </div>
            <div class="av-group">
                <label>E-mail <span class="av-req">*</span></label>
                <input type="email" name="av_email" required maxlength="100">
            </div>
            <div class="av-group">
                <label>Sündmus</label>
                <input type="text" name="av_event" maxlength="100">
            </div>
            <div class="av-group">
                <label>Kuupäev</label>
                <input type="text" name="av_date" class="av_datepicker_input" placeholder="PP.KK.AAAA" autocomplete="off">
            </div>
            <div class="av-group">
                <label>Koht</label>
                <input type="text" name="av_location" maxlength="150">
            </div>
            <div class="av-group">
                <label>Kirjeldus (max 5000 tähemärki)</label>
                <textarea name="av_desc" rows="5" maxlength="5000"></textarea>
            </div>
            
            <div class="av-actions">
                <input type="submit" name="av_contact_submit" value="Saada Päring" class="button button-primary">
                <span class="av-loader" style="display:none;">⏳ Saatmine...</span>
            </div>
            <p class="av-gdpr-note"><small>Isikuandmeid säilitatakse 1 aasta.</small></p>
        </form>
        <?php
    }

    /**
     * ADMIN & DELETE
     */
    public function add_admin_menu() {
        add_menu_page( 'Päringud', 'Päringud', 'av_view_submissions', 'av-contact-entries', array( $this, 'render_admin_page' ), 'dashicons-email', 26 );
        add_submenu_page( 'av-contact-entries', 'Seaded', 'Seaded', 'manage_options', 'av-contact-settings', array( $this, 'render_settings_page' ) );
    }

    public function register_settings() {
        register_setting( 'av_contact_settings', 'av_recipient_email', 'sanitize_email' );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Kontaktivormi Seaded</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'av_contact_settings' ); do_settings_sections( 'av_contact_settings' ); ?>
                <table class="form-table">
                    <tr><th>Teavituse saaja e-mail:</th><td><input type="email" name="av_recipient_email" value="<?php echo esc_attr( get_option('av_recipient_email', 'andres.vago@gmail.com') ); ?>" class="regular-text"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // Process Delete Request
    public function handle_delete_entry() {
        if ( ! current_user_can( 'av_view_submissions' ) ) wp_die( 'Õigused puuduvad' );
        check_admin_referer( 'av_delete_action' );

        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $id ) {
            global $wpdb;
            $wpdb->delete( $this->table_name, array( 'id' => $id ) );
        }
        wp_redirect( admin_url( 'admin.php?page=av-contact-entries&msg=deleted' ) );
        exit;
    }

    public function render_admin_page() {
        global $wpdb;
        $entries = $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY time DESC" );
        
        if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'deleted' ) echo '<div class="notice notice-success"><p>Kirje kustutatud.</p></div>';
        ?>
        <div class="wrap">
            <h1>Sissetulnud Päringud</h1>
            <div class="av-table-container">
            <table class="wp-list-table widefat fixed striped av-responsive-table">
                <thead>
                    <tr>
                        <th width="120">Kuupäev</th>
                        <th>Nimi / Email</th>
                        <th>Sündmus / Aeg</th>
                        <th>Koht</th>
                        <th>Tegevused</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $entries ) ) : ?>
                        <?php foreach ( $entries as $entry ) : ?>
                            <tr>
                                <td data-label="Kuupäev"><?php echo esc_html( $entry->time ); ?></td>
                                <td data-label="Nimi">
                                    <strong><?php echo esc_html( $entry->name ); ?></strong><br>
                                    <a href="mailto:<?php echo esc_attr( $entry->email ); ?>"><?php echo esc_html( $entry->email ); ?></a>
                                </td>
                                <td data-label="Sündmus">
                                    <?php echo esc_html( $entry->event_type ); ?><br>
                                    <small><?php echo esc_html( $entry->event_date ); ?></small>
                                </td>
                                <td data-label="Koht"><?php echo esc_html( $entry->event_location ); ?></td>
                                <td data-label="Tegevused">
                                    <button class="button av-toggle-msg">Vaata Sisu</button>
                                    <a href="<?php echo wp_nonce_url( admin_url('admin-post.php?action=av_delete_entry&id=' . $entry->id), 'av_delete_action' ); ?>" class="button button-link-delete" onclick="return confirm('Olete kindel?');">Kustuta</a>
                                    <div class="av-msg-content" style="display:none; margin-top:10px; background:#fff; padding:10px; border:1px solid #ddd;">
                                        <?php echo nl2br( esc_html( $entry->message ) ); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="5">Päringud puuduvad.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            <script>
                jQuery('.av-toggle-msg').click(function(){ jQuery(this).siblings('.av-msg-content').slideToggle(); });
            </script>
        </div>
        <?php
    }
}
new AV_Contact_Manager();