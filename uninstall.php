<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;
global $wpdb;
$table_name = $wpdb->prefix . 'av_contact_entries';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
delete_option( 'av_recipient_email' );
delete_option( 'av_sender_email' );
delete_option( 'av_reply_to_email' );
delete_option( 'av_smtp_host' );
delete_option( 'av_smtp_port' );
delete_option( 'av_smtp_user' );
delete_option( 'av_smtp_pass' );
wp_clear_scheduled_hook( 'av_daily_cleanup_event' );