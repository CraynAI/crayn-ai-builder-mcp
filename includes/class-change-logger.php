<?php
/**
 * Change log database table for Crayn AI Builder MCP.
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads rows from {$wpdb->prefix}craynai_log.
 *
 * Every non-dry-run write operation stores one row BEFORE returning to the
 * caller. The row holds the before/after JSON snapshots so the write can be
 * reverted later via change-revert.
 */
class ChangeLogger {

	/**
	 * Create the log table on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$table           = $wpdb->prefix . 'craynai_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	created_at DATETIME NOT NULL,
	user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	ability VARCHAR(191) NOT NULL DEFAULT '',
	object_type VARCHAR(64) NOT NULL DEFAULT '',
	object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	before_data LONGTEXT NULL,
	after_data LONGTEXT NULL,
	reverted_at DATETIME NULL DEFAULT NULL,
	note TEXT NOT NULL,
	PRIMARY KEY  (id),
	KEY ability (ability),
	KEY object_type (object_type, object_id),
	KEY created_at (created_at)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		add_option( 'crayn_ai_builder_mcp_settings', Settings::defaults(), '', false );
	}

	/**
	 * Create the log table when it is missing (checked once per request).
	 * Covers installs where the plugin was copied in or network-activated
	 * without running the activation hook for this site.
	 *
	 * @return void
	 */
	public static function ensure_table() {
		global $wpdb;
		static $checked = false;

		if ( $checked ) {
			return;
		}
		$checked = true;

		$table = $wpdb->prefix . 'craynai_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			self::activate();
		}
	}

	/**
	 * Error returned when a write succeeded but could not be logged.
	 *
	 * @param string $what Short description of the applied write.
	 * @return \WP_Error
	 */
	public static function unlogged_error( $what = '' ) {
		return new \WP_Error(
			'craynai_change_not_logged',
			trim( 'The change was applied but could not be written to the change log, so it cannot be reverted with change-revert. ' . $what )
		);
	}

	/**
	 * Insert a change row. Returns the change id, or 0 on failure.
	 *
	 * @param string     $ability     Ability name, e.g. "craynai/post-update".
	 * @param string     $object_type Object type: post|seo|term|attachment|plugin.
	 * @param int|string $object_id   Object id (0 or slug-ish string when not applicable).
	 * @param array|null $before      Before snapshot.
	 * @param array|null $after       After snapshot.
	 * @param string     $note        Free-form note.
	 * @return int
	 */
	public static function log( $ability, $object_type, $object_id, $before, $after, $note = '' ) {
		global $wpdb;

		self::ensure_table();

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own change log table, has no WP API; all values are inserted via the $wpdb->insert() format array.
			$wpdb->prefix . 'craynai_log',
			array(
				'created_at'  => current_time( 'mysql', true ),
				'user_id'     => get_current_user_id(),
				'ability'     => $ability,
				'object_type' => (string) $object_type,
				'object_id'   => (int) $object_id,
				'before_data' => wp_json_encode( $before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'after_data'  => wp_json_encode( $after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'reverted_at' => null,
				'note'        => (string) $note,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get one row as an array, or null.
	 *
	 * @param int $change_id Change id.
	 * @return array|null
	 */
	public static function get( $change_id ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own change log row fetched once for a revert; caching it would serve stale data right after concurrent writes.
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'craynai_log WHERE id = %d',
				$change_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * List change rows with optional filters.
	 *
	 * @param array $filters object_type, object_id, limit.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_changes( array $filters = array() ) {
		global $wpdb;

		$limit    = isset( $filters['limit'] ) ? max( 1, min( 100, (int) $filters['limit'] ) ) : 20;
		$table    = $wpdb->prefix . 'craynai_log';
		$where    = array( '1=1' );
		$prepare  = array();

		if ( ! empty( $filters['object_type'] ) ) {
			$where[]   = 'object_type = %s';
			$prepare[] = (string) $filters['object_type'];
		}
		if ( ! empty( $filters['object_id'] ) ) {
			$where[]   = 'object_id = %d';
			$prepare[] = (int) $filters['object_id'];
		}

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$prepare[] = $limit;

		if ( empty( $prepare ) ) {
			// $table is $wpdb->prefix . a fixed suffix (never a user value) and $limit is a
			// min()-capped cast int, so the interpolation is safe; there are no values to prepare.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constants only: table name is prefix + fixed suffix, $limit is a cast int.
			$rows = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id DESC LIMIT ' . $limit, ARRAY_A );
		} else {
			// $sql is built internally from $wpdb->prefix, literal strings and %s/%d
			// placeholders; every value is bound through $wpdb->prepare().
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Query string built internally and always prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare ), ARRAY_A );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a change as reverted. Returns false when it was already reverted.
	 *
	 * @param int $change_id Change id.
	 * @return bool
	 */
	public static function mark_reverted( $change_id ) {
		global $wpdb;

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic "revert claim" on the plugin's own table; caching would defeat its purpose.
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'craynai_log SET reverted_at = %s WHERE id = %d AND reverted_at IS NULL',
				current_time( 'mysql', true ),
				$change_id
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Undo mark_reverted() after a failed restore so the change can be retried.
	 *
	 * @param int $change_id Change id.
	 * @return void
	 */
	public static function unmark_reverted( $change_id ) {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'craynai_log SET reverted_at = NULL WHERE id = %d', $change_id )
		);
	}

	/**
	 * Decode a snapshot column.
	 *
	 * @param string|null $json JSON string.
	 * @return array<string, mixed>
	 */
	public static function decode( $json ) {
		if ( empty( $json ) || ! is_string( $json ) ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
