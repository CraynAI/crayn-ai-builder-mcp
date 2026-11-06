<?php
/**
 * Uninstall routine for Crayn AI Builder MCP.
 *
 * Removes everything the plugin created, per site:
 * - the settings option "crayn_ai_builder_mcp_settings" (real name: Settings::OPTION_NAME),
 * - the change log table "{$wpdb->prefix}craynai_log" (real name: ChangeLogger),
 * - the plugin backups folder under uploads: "craynai-backups"
 *   (real name: PluginBackup::backup_dir(), parent folder),
 * - the legacy backup folder wp-content/craynai-backups from versions
 *   that stored backups outside uploads.
 *
 * The plugin stores no transients of its own. Rank Math / Yoast options touched
 * through the ext-* wrappers belong to those plugins and are intentionally kept.
 *
 * This file must not need the vendor autoloader: it runs with the plugin's
 * classes not loaded, so it uses the literal names verified against
 * includes/class-settings.php, includes/class-change-logger.php and
 * includes/class-plugin-backup.php.
 *
 * @package CraynAiBuilderMcp
 */

// Exit if uninstall.php was not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin's data for the current site.
 *
 * @return void
 */
function crayn_ai_builder_mcp_uninstall_site() {
	global $wpdb;

	// Settings option.
	delete_option( 'crayn_ai_builder_mcp_settings' );

	// Change log table.
	$table = $wpdb->prefix . 'craynai_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time uninstall cleanup; the table name is $wpdb->prefix . a fixed suffix, never a user value.
	$wpdb->query( sprintf( 'DROP TABLE IF EXISTS %s', $table ) );

	// Backup folders (current location under uploads, and the legacy location
	// in wp-content from older plugin versions). Both are wiped recursively;
	// they only ever contain the plugin's own backup zips and their metadata.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	$upload_dir = wp_upload_dir();
	$roots      = array();
	if ( empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
		$roots[] = rtrim( $upload_dir['basedir'], '/' ) . '/craynai-backups';
	}
	$roots[] = WP_CONTENT_DIR . '/craynai-backups';

	$seen = array();
	foreach ( $roots as $root ) {
		if ( isset( $seen[ $root ] ) || ! is_dir( $root ) ) {
			continue;
		}
		$seen[ $root ] = true;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $root, true );
		}
	}
}

if ( is_multisite() ) {
	$crayn_ai_builder_mcp_sites = get_sites(
		array(
			'fields'             => 'ids',
			'number'             => 0,
			'update_site_cache'  => false,
			'update_site_meta_cache' => false,
		)
	);
	foreach ( $crayn_ai_builder_mcp_sites as $crayn_ai_builder_mcp_site_id ) {
		switch_to_blog( (int) $crayn_ai_builder_mcp_site_id );
		crayn_ai_builder_mcp_uninstall_site();
		restore_current_blog();
	}
} else {
	crayn_ai_builder_mcp_uninstall_site();
}
