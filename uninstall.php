<?php
/**
 * Fired when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 1. Delete Database Table
$table_name = $wpdb->prefix . 'av_contact_entries';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

// 2. Delete Options
delete_option( 'av_recipient_email' );

// 3. Clear Scheduled Hooks
wp_clear_scheduled_hook( 'av_daily_cleanup_event' );