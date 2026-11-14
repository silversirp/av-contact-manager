<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'av_contact_entries';
$mail_log_table = $wpdb->prefix . 'av_mail_log';

// 1. Drop DB Table
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
$wpdb->query( "DROP TABLE IF EXISTS $mail_log_table" );

// 2. Delete Settings
delete_option( 'av_recipient_email' );
delete_option( 'av_sender_email' );
delete_option( 'av_reply_to_email' );
delete_option( 'av_smtp_host' );
delete_option( 'av_smtp_port' );
delete_option( 'av_smtp_user' );
delete_option( 'av_smtp_pass' );
delete_option( 'av_turnstile_site_key' );
delete_option( 'av_turnstile_secret' );
delete_option( 'av_block_cyrillic' );
delete_option( 'av_db_version' );

// 3. Clear Scheduler
wp_clear_scheduled_hook( 'av_daily_cleanup_event' );