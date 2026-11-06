<?php
/**
 * Site health ability.
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers "craynai-site" abilities (read only).
 */
class SiteAbilities {

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		return array(
			'craynai/site-health',
		);
	}

	/**
	 * Register every site-category ability.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_site_health();
	}

	/**
	 * craynai/site-health
	 *
	 * @return void
	 */
	private static function register_site_health() {
		wp_register_ability(
			'craynai/site-health',
			array(
				'label'              => 'Site health',
				'description'        => 'Get site health details: WordPress version, PHP version, active theme, debug flags, pending update counts, free disk space (when available) and the last 10 debug log entries.',
				'category'           => 'craynai-site',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'wp_version'        => array( 'type' => 'string' ),
						'php_version'       => array( 'type' => 'string' ),
						'active_theme'      => array( 'type' => 'string' ),
						'active_theme_version' => array( 'type' => 'string' ),
						'multisite'         => array( 'type' => 'boolean' ),
						'debug_flags'       => array(
							'type'       => 'object',
							'properties' => array(
								'WP_DEBUG'         => array( 'type' => 'boolean' ),
								'WP_DEBUG_LOG'     => array( 'type' => 'boolean' ),
								'WP_DEBUG_DISPLAY' => array( 'type' => 'boolean' ),
								'SCRIPT_DEBUG'     => array( 'type' => 'boolean' ),
								'DISALLOW_FILE_EDIT' => array( 'type' => 'boolean' ),
							),
						),
						'pending_updates'   => array(
							'type'       => 'object',
							'properties' => array(
								'core'    => array( 'type' => 'integer' ),
								'plugins' => array( 'type' => 'integer' ),
								'themes'  => array( 'type' => 'integer' ),
							),
						),
						'disk_free'         => array( 'type' => 'string', 'description' => 'Free disk space formatted, or "unknown".' ),
						'last_log_entries'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_site_health' ),
				'permission_callback' => array( self::class, 'permission' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, false, 'Site Health' ) ),
			)
		);
	}

	/**
	 * Execute site-health.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_site_health( $input = array() ) {
		$guard = Safety::guard( 'craynai-site', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// Pending updates.
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		PluginAbilities::refresh_update_data();
		$core_updates    = get_core_updates();
		$pending_core    = 0;
		foreach ( (array) $core_updates as $core_update ) {
			if ( is_object( $core_update ) && isset( $core_update->response ) && 'upgrade' === $core_update->response ) {
				$pending_core++;
			}
		}

		$update_plugins = get_site_transient( 'update_plugins' );
		$update_themes  = get_site_transient( 'update_themes' );
		$pending_plugins = ( $update_plugins && ! empty( $update_plugins->response ) ) ? count( (array) $update_plugins->response ) : 0;
		$pending_themes  = ( $update_themes && ! empty( $update_themes->response ) ) ? count( (array) $update_themes->response ) : 0;

		// Disk free (not available on all hosts).
		$disk_free = 'unknown';
		if ( function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_float( $free ) && $free > 0 ) {
				$disk_free = size_format( $free, 2 );
			}
		}

		return array(
			'wp_version'           => (string) get_bloginfo( 'version' ),
			'php_version'          => PHP_VERSION,
			'active_theme'         => wp_get_theme()->get( 'Name' ),
			'active_theme_version' => wp_get_theme()->get( 'Version' ),
			'multisite'            => is_multisite(),
			'debug_flags'          => array(
				'WP_DEBUG'           => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'WP_DEBUG_LOG'       => (bool) ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ),
				'WP_DEBUG_DISPLAY'   => (bool) ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ),
				'SCRIPT_DEBUG'       => (bool) ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ),
				'DISALLOW_FILE_EDIT' => (bool) ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
			),
			'pending_updates'      => array(
				'core'    => $pending_core,
				'plugins' => $pending_plugins,
				'themes'  => $pending_themes,
			),
			'disk_free'            => $disk_free,
			'last_log_entries'     => self::last_log_lines( 10 ),
		);
	}

	/**
	 * Tail the WordPress debug log (WP_CONTENT_DIR/debug.log) when it exists.
	 *
	 * @param int $count Number of lines.
	 * @return string[] Lines, newest last.
	 */
	private static function last_log_lines( $count ) {
		$log_path = WP_CONTENT_DIR . '/debug.log';
		if ( ! ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) || ! file_exists( $log_path ) || ! is_readable( $log_path ) ) {
			return array();
		}

		clearstatcache();
		$size = (int) filesize( $log_path );
		if ( $size <= 0 ) {
			return array();
		}

		// Only read the tail so log rotation keeps this cheap. WP_Filesystem cannot
		// offset-read the tail of a large file, so a direct fopen/fread is required.
		$read_size = min( $size, 32 * 1024 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Tail-read: WP_Filesystem has no seek/offset read; only the last 32 KB of the log are read, never the whole file.
		$handle = fopen( $log_path, 'r' );
		if ( ! $handle ) {
			return array();
		}
		fseek( $handle, $size - $read_size, SEEK_SET );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Tail-read: WP_Filesystem has no offset read; only the last 32 KB are read, never the whole log.
		$chunk = fread( $handle, $read_size );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $chunk ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return array_slice( $lines, -1 * $count );
	}

	/**
	 * Permission for site abilities (admin only).
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission( $input = array() ) {
		return current_user_can( 'manage_options' ) && null === Safety::guard( 'craynai-site', false );
	}
}
