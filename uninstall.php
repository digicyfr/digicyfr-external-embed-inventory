<?php
/**
 * Remove only plugin-owned scan state.
 *
 * @package Digicyfr_External_Embed_Inventory
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
global $wpdb;
foreach ( array( 'deei_rows', 'deei_coverage', 'deei_runs', 'deei_lock' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned suffix and trusted prefix; uninstall must remove its tables.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}
foreach ( array( 'deei_schema_version', 'deei_current_run', 'deei_active_complete_run_id' ) as $option ) {
	delete_option( $option );
}
