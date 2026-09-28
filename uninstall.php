<?php
/**
 * Remove plugin settings and logs. Files, git folders and deploy keys on disk are left alone.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'gdw_repos' );
delete_option( 'gdw_settings' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'gdw\\_log\\_%' OR option_name LIKE 'gdw\\_pending\\_%'" );
