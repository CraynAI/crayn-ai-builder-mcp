<?php
/**
 * Plugin management abilities: safe updates, activation, rollback and backups.
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers "crayn-plugins" abilities. Every write takes a backup first and
 * verifies site health afterwards, rolling back automatically on failure.
 */
class PluginAbilities extends AbilitiesBase {

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		return array(
			'crayn-safe/plugins-list',
			'crayn-safe/plugin-update',
			'crayn-safe/plugin-activate',
			'crayn-safe/plugin-deactivate',
			'crayn-safe/plugin-rollback',
			'crayn-safe/backups-list',
			'crayn-safe/updates-list',
		);
	}

	/**
	 * Register every plugin-category ability.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_plugins_list();
		self::register_plugin_update();
		self::register_plugin_activate();
		self::register_plugin_deactivate();
		self::register_plugin_rollback();
		self::register_backups_list();
		self::register_updates_list();
	}

	/**
	 * Load the wp-admin includes needed for plugin/upgrader work.
	 *
	 * @return void
	 */
	/**
	 * Make sure the update transients are populated (WordPress throttles the
	 * remote check itself; $force bypasses the throttle once).
	 *
	 * @param bool $force Force a fresh remote check.
	 * @return void
	 */
	public static function refresh_update_data( $force = false ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( $force ) {
			delete_site_transient( 'update_plugins' );
		}
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
	}

	private static function load_plugin_includes() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( file_exists( ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
	}

	/**
	 * Is this plugin file Crayn Safe MCP itself? It must never update, deactivate or
	 * roll back itself (the running code would be replaced mid-request).
	 *
	 * @param string $plugin_file Plugin file relative to the plugins dir.
	 * @return bool
	 */
	public static function is_self( $plugin_file ) {
		return plugin_basename( CRAYN_SAFE_MCP_FILE ) === (string) $plugin_file;
	}

	/**
	 * Error returned when a tool is pointed at this plugin itself.
	 *
	 * @return \WP_Error
	 */
	private static function self_error() {
		return new \WP_Error( 'crayn_safe_self_protected', 'Crayn Safe MCP refuses to update, deactivate or roll back itself. Do this manually in wp-admin.' );
	}

	/**
	 * Resolve and validate a plugin file argument.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins dir.
	 * @return array<string, mixed>|\WP_Error Plugin data with "file" key added.
	 */
	private static function resolve_plugin( $plugin_file ) {
		self::load_plugin_includes();

		$plugins = get_plugins();
		$plugin_file = str_replace( '\\', '/', (string) $plugin_file );

		if ( ! isset( $plugins[ $plugin_file ] ) && 0 < substr_count( $plugin_file, '/' ) ) {
			return new \WP_Error( 'crayn_safe_plugin_not_found', sprintf( 'Plugin "%s" is not installed.', $plugin_file ) );
		}
		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			// Allow passing just a folder name or slug.
			$matches = array();
			foreach ( $plugins as $file => $data ) {
				if ( $plugin_file === dirname( $file ) ) {
					$plugin_file = $file;
					$matches[]   = $file;
					break;
				}
			}
			if ( empty( $matches ) ) {
				return new \WP_Error( 'crayn_safe_plugin_not_found', sprintf( 'Plugin "%s" is not installed.', $plugin_file ) );
			}
		}

		$data          = $plugins[ $plugin_file ];
		$data['file']  = $plugin_file;
		$data['active']         = is_plugin_active( $plugin_file );
		$data['network_active'] = is_multisite() && is_plugin_active_for_network( $plugin_file );
		return $data;
	}

	/**
	 * crayn-safe/plugins-list
	 *
	 * @return void
	 */
	private static function register_plugins_list() {
		wp_register_ability(
			'crayn-safe/plugins-list',
			array(
				'label'              => 'List plugins',
				'description'        => 'List installed plugins: name, plugin file, version, activation state, network activation, pending update availability with the new version, and auto-update status.',
				'category'           => 'crayn-plugins',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'active_only'   => array(
							'type'        => 'boolean',
							'description' => 'Only list active plugins. Default false.',
							'default'     => false,
						),
						'updates_only'  => array(
							'type'        => 'boolean',
							'description' => 'Only list plugins with a pending update. Default false.',
							'default'     => false,
						),
						'search'        => array(
							'type'        => 'string',
							'description' => 'Match against plugin name or slug.',
						),
					),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'total' => array( 'type' => 'integer' ),
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'             => array( 'type' => 'string' ),
									'plugin_file'      => array( 'type' => 'string' ),
									'slug'             => array( 'type' => 'string' ),
									'version'          => array( 'type' => 'string' ),
									'active'           => array( 'type' => 'boolean' ),
									'network_active'   => array( 'type' => 'boolean' ),
									'update_available' => array( 'type' => 'boolean' ),
									'new_version'      => array( 'type' => 'string', 'description' => 'New version when an update is available.' ),
									'auto_update'      => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_plugins_list' ),
				'permission_callback' => array( self::class, 'permission_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, false, 'List Plugins' ) ),
			)
		);
	}

	/**
	 * Execute plugins-list.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_plugins_list( $input ) {
		$guard = Safety::guard( 'crayn-plugins', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		self::load_plugin_includes();
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$auto_updates = (array) get_option( 'auto_update_plugins', array() );
		$updates      = array();
		self::refresh_update_data();
		foreach ( get_plugin_updates() as $plugin_update ) {
			if ( $plugin_update instanceof \stdClass && ! empty( $plugin_update->update ) && ! empty( $plugin_update->update->new_version ) ) {
				$updates[ $plugin_update->update->plugin ] = $plugin_update->update->new_version;
			} elseif ( method_exists( $plugin_update, 'get_data' ) ) {
				// A WP_PluginUpdate meta style object may also expose the new version directly.
				$data = $plugin_update;
				if ( isset( $data->new_version ) && ! empty( $data->plugin ) ) {
					$updates[ $data->plugin ] = $data->new_version;
				}
			}
		}

		$search = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
		$items  = array();
		foreach ( get_plugins() as $file => $data ) {
			$active           = is_plugin_active( $file );
			$network_active   = is_multisite() && is_plugin_active_for_network( $file );
			if ( ! empty( $input['active_only'] ) && ! ( $active || $network_active ) ) {
				continue;
			}

			$update_available = isset( $updates[ $file ] );
			$new_version      = $update_available ? (string) $updates[ $file ] : '';
			if ( ! empty( $input['updates_only'] ) && ! $update_available ) {
				continue;
			}

			$haystack = strtolower( $data['Name'] . ' ' . $file );
			if ( '' !== $search && false === strpos( $haystack, $search ) ) {
				continue;
			}

			$items[] = array(
				'name'             => $data['Name'],
				'plugin_file'      => $file,
				'slug'             => ( '' !== dirname( $file ) ) ? dirname( $file ) : sanitize_title( $data['Name'] ),
				'version'          => $data['Version'],
				'active'           => $active || $network_active,
				'network_active'   => $network_active,
				'update_available' => $update_available,
				'new_version'      => $new_version,
				'auto_update'      => in_array( $file, $auto_updates, true ),
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( strtolower( $a['name'] ), strtolower( $b['name'] ) );
			}
		);

		return array(
			'total' => count( $items ),
			'items' => $items,
		);
	}

	/**
	 * Shared registration for the plugin update ability.
	 *
	 * @return void
	 */
	private static function register_plugin_update() {
		wp_register_ability(
			'crayn-safe/plugin-update',
			array(
				'label'              => 'Update plugin (auto-backup + auto-rollback)',
				'description'        => 'Update a single plugin safely: 1) zip the plugin folder as a backup, 2) run the WordPress upgrader, 3) check site health (front end and wp-admin must respond correctly), 4) if the health check fails, restore the backup, re-activate the plugin and report rolled_back:true. Logged with change_id.',
				'category'           => 'crayn-plugins',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_file' => array(
							'type'        => 'string',
							'description' => 'Plugin file, e.g. "akismet/akismet.php" (or plugin slug).',
						),
						'dry_run'     => Safety::dry_run_property(),
					),
					'required'   => array( 'plugin_file' ),
				),
				'output_schema'      => self::change_output_schema(
					array(
						'plugin_file'   => array( 'type' => 'string' ),
						'backup_file'   => array( 'type' => 'string' ),
						'rolled_back'   => array( 'type' => 'boolean', 'description' => 'True when the update was undone after a failed health check.' ),
						'reason'        => array( 'type' => 'string' ),
						'health'        => array( 'type' => 'string', 'description' => '"ok" or the health check error.' ),
					)
				),
				'execute_callback'   => array( self::class, 'execute_plugin_update' ),
				'permission_callback' => array( self::class, 'permission_update' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Update Plugin' ) ),
			)
		);
	}

	/**
	 * Execute plugin-update.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_plugin_update( $input ) {
		$dry_run = Safety::dry_run( $input );
		$guard   = Safety::guard( 'crayn-plugins', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$plugin = self::resolve_plugin( isset( $input['plugin_file'] ) ? $input['plugin_file'] : '' );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		if ( self::is_self( $plugin['file'] ) ) {
			return self::self_error();
		}

		require_once ABSPATH . 'wp-admin/includes/update.php';
		self::refresh_update_data();
		$available = get_plugin_updates();
		if ( ! isset( $available[ $plugin['file'] ] ) ) {
			// Update data may be stale: force one fresh check before saying "no update".
			self::refresh_update_data( true );
			$available = get_plugin_updates();
		}
		$new_version = '';
		if ( isset( $available[ $plugin['file'] ] ) && ! empty( $available[ $plugin['file'] ]->update->new_version ) ) {
			$new_version = (string) $available[ $plugin['file'] ]->update->new_version;
		}

		$before = array(
			'plugin_file'    => $plugin['file'],
			'name'           => $plugin['Name'],
			'version'        => $plugin['Version'],
			'active'         => $plugin['active'],
			'network_active' => $plugin['network_active'],
		);

		if ( $dry_run ) {
			$after             = $before;
			$after['planned_version'] = '' !== $new_version ? $new_version : '(latest available)';
			$after['planned_steps'] = array(
				'backup zip into ' . PluginBackup::backup_dir(),
				'run Plugin_Upgrader->upgrade()',
				'site health check (front end + wp-admin)',
				'automatic rollback from the zip if the health check fails',
			);
			return array(
				'dry_run'     => true,
				'before'      => $before,
				'after'       => $after,
				'change_id'   => 0,
				'plugin_file' => $plugin['file'],
				'backup_file' => '',
				'rolled_back' => false,
				'reason'      => '',
				'health'      => '',
			);
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot update plugins.' );
		}

		if ( '' === $new_version ) {
			return new \WP_Error( 'crayn_safe_no_update', 'No update is available for this plugin; nothing was changed.' );
		}

		// Step 0: the site must be healthy BEFORE we touch it, otherwise every
		// update would look "broken" and be rolled back (e.g. loopback blocked).
		$pre_health = HealthCheck::check();
		if ( is_wp_error( $pre_health ) ) {
			return new \WP_Error( 'crayn_safe_unhealthy_before', 'Update not started: the site health check already fails before the update (' . $pre_health->get_error_message() . '). Fix that first; nothing was changed.' );
		}

		// Step 1: backup.
		$backup = PluginBackup::backup( $plugin['file'] );
		if ( is_wp_error( $backup ) ) {
			ChangeLogger::log( 'crayn-safe/plugin-update', 'plugin', 0, $before, null, 'update aborted: backup failed - ' . $backup->get_error_message() );
			return new \WP_Error( 'crayn_safe_backup_failed', 'Update aborted, nothing was written: ' . $backup->get_error_message() );
		}

		// Step 2: update.
		self::load_plugin_includes();
		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $plugin['file'] );
		if ( false === $result || is_wp_error( $result ) ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : 'upgrader returned no result';
			// A failed upgrade can leave the plugin folder half-replaced or missing: restore it.
			wp_clean_plugins_cache( true );
			$restored = PluginBackup::restore( $backup['file'], true );
			$restore_note = is_wp_error( $restored ) ? ' restore FAILED: ' . $restored->get_error_message() : ' previous version restored from backup';
			ChangeLogger::log( 'crayn-safe/plugin-update', 'plugin', 0, $before, array( 'backup_file' => $backup['file'], 'version' => '', 'rolled_back' => ! is_wp_error( $restored ) ), 'update failed - ' . $message . ';' . $restore_note );
			return new \WP_Error( 'crayn_safe_update_failed', 'Plugin update failed: ' . $message . ';' . $restore_note . ' (backup: ' . $backup['file'] . ')' );
		}

		// Step 3: health check.
		wp_clean_plugins_cache( true );
		$health = HealthCheck::check();
		if ( is_wp_error( $health ) ) {
			// Step 4: automatic rollback.
			$restored = PluginBackup::restore( $backup['file'], true );
			$reason   = $health->get_error_message();
			$change_id = ChangeLogger::log( 'crayn-safe/plugin-update', 'plugin', 0, $before, array( 'backup_file' => $backup['file'], 'rolled_back' => true, 'reactivated' => ! is_wp_error( $restored ) ), 'update rolled back after health check failure - ' . $reason );

			return array(
				'dry_run'     => false,
				'before'      => $before,
				'after'       => array( 'version' => $plugin['Version'], 'active' => $plugin['active'], 'backup_file' => $backup['file'] ),
				'change_id'   => $change_id,
				'plugin_file' => $plugin['file'],
				'backup_file' => $backup['file'],
				'rolled_back' => true,
				'reason'      => $reason,
				'health'      => sprintf( 'Site health failed: %s (site was restored to version %s).', $reason, $plugin['Version'] ),
			);
		}

		$fresh = self::resolve_plugin( $plugin['file'] );
		$after = array(
			'version'     => is_wp_error( $fresh ) ? '' : $fresh['Version'],
			'active'      => is_plugin_active( $plugin['file'] ) || ( is_multisite() && is_plugin_active_for_network( $plugin['file'] ) ),
			'backup_file' => $backup['file'],
			'prev_version' => $plugin['Version'],
		);

		$change_id = ChangeLogger::log( 'crayn-safe/plugin-update', 'plugin', 0, $before, $after, sprintf( 'plugin updated %s -> %s (backup: %s)', $plugin['Version'], $after['version'], $backup['file'] ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Plugin %s was updated; backup: %s.', $plugin['file'], $backup['file'] ) );
		}

		return array(
			'dry_run'     => false,
			'before'      => $before,
			'after'       => $after,
			'change_id'   => $change_id,
			'plugin_file' => $plugin['file'],
			'backup_file' => $backup['file'],
			'rolled_back' => false,
			'reason'      => '',
			'health'      => 'ok',
		);
	}

	/**
	 * Shared registration for activation-state writes (activate/deactivate).
	 *
	 * @param string $name     Ability name suffix.
	 * @param string $action   'activate' or 'deactivate'.
	 * @return void
	 */
	private static function register_activation_ability( $name, $action ) {
		wp_register_ability(
			'crayn-safe/plugin-' . $name,
			array(
				'label'              => 'activate' === $action ? 'Activate plugin' : 'Deactivate plugin',
				'description'        => ( 'activate' === $action
					? 'Activate a plugin, then run a site health check; if the site breaks, roll back to the previous activation state automatically.'
					: 'Deactivate a plugin, then run a site health check; if the site breaks, undo the deactivation automatically.' )
					. ' Logged with change_id.',
				'category'           => 'crayn-plugins',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_file' => array(
							'type'        => 'string',
							'description' => 'Plugin file, e.g. "akismet/akismet.php" (or plugin slug).',
						),
						'dry_run'     => Safety::dry_run_property(),
					),
					'required'   => array( 'plugin_file' ),
				),
				'output_schema'      => self::change_output_schema(
					array(
						'plugin_file' => array( 'type' => 'string' ),
						'rolled_back' => array( 'type' => 'boolean', 'description' => 'True when the state change was undone after a failed health check.' ),
						'reason'      => array( 'type' => 'string' ),
						'health'      => array( 'type' => 'string' ),
					)
				),
				'execute_callback'   => 'activate' === $action
					? array( self::class, 'execute_plugin_activate' )
					: array( self::class, 'execute_plugin_deactivate' ),
				'permission_callback' => array( self::class, 'permission_activate' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'activate' === $action ? 'Activate Plugin' : 'Deactivate Plugin' ) ),
			)
		);
	}

	/**
	 * Register plugin-activate.
	 *
	 * @return void
	 */
	private static function register_plugin_activate() {
		self::register_activation_ability( 'activate', 'activate' );
	}

	/**
	 * Register plugin-deactivate.
	 *
	 * @return void
	 */
	private static function register_plugin_deactivate() {
		self::register_activation_ability( 'deactivate', 'deactivate' );
	}

	/**
	 * Execute plugin-activate.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_plugin_activate( $input ) {
		return self::run_activation_change( $input, 'activate' );
	}

	/**
	 * Execute plugin-deactivate.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_plugin_deactivate( $input ) {
		return self::run_activation_change( $input, 'deactivate' );
	}

	/**
	 * Shared runner for activation state changes with health check + undo.
	 *
	 * @param array  $input  Validated input.
	 * @param string $action 'activate' or 'deactivate'.
	 * @return array|\WP_Error
	 */
	private static function run_activation_change( $input, $action ) {
		$dry_run = Safety::dry_run( $input );
		$guard   = Safety::guard( 'crayn-plugins', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot activate plugins.' );
		}

		$plugin = self::resolve_plugin( isset( $input['plugin_file'] ) ? $input['plugin_file'] : '' );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		$file          = $plugin['file'];
		if ( 'deactivate' === $action && self::is_self( $file ) ) {
			return self::self_error();
		}
		$was_active    = is_plugin_active( $file );
		$was_network   = is_multisite() && is_plugin_active_for_network( $file );
		$network_wide  = $was_network || ( is_multisite() && current_user_can( 'manage_network_plugins' ) );

		$before = array(
			'plugin_file'    => $file,
			'active'         => $was_active || $was_network,
			'network_active' => $was_network,
		);

		if ( 'activate' === $action && ( $was_active || $was_network ) ) {
			return new \WP_Error( 'crayn_safe_already_active', sprintf( 'Plugin "%s" is already active.', $file ) );
		}
		if ( 'deactivate' === $action && ! ( $was_active || $was_network ) ) {
			return new \WP_Error( 'crayn_safe_already_inactive', sprintf( 'Plugin "%s" is already inactive.', $file ) );
		}

		$after_planned = array(
			'plugin_file' => $file,
			'active'      => 'activate' === $action,
		);

		if ( $dry_run ) {
			return array(
				'dry_run'     => true,
				'before'      => $before,
				'after'       => $after_planned,
				'change_id'   => 0,
				'plugin_file' => $file,
				'rolled_back' => false,
				'reason'      => '',
				'health'      => '',
			);
		}

		self::load_plugin_includes();

		if ( 'activate' === $action ) {
			$result = activate_plugin( $file, '', $was_network );
		} else {
			deactivate_plugins( $file, false, $was_network );
			$result = true;
		}

		if ( is_wp_error( $result ) ) {
			ChangeLogger::log( 'crayn-safe/plugin-' . $action, 'plugin', 0, $before, null, 'operation failed - ' . $result->get_error_message() );
			return $result;
		}

		$health = HealthCheck::check();
		if ( is_wp_error( $health ) ) {
			// Automatic undo: restore the previous activation state.
			if ( 'activate' === $action ) {
				deactivate_plugins( $file, false, $was_network );
				$undone = true;
			} else {
				$reactivated = activate_plugin( $file, '', $was_network );
				$undone      = ! is_wp_error( $reactivated );
			}

			$reason    = $health->get_error_message();
			$change_id = ChangeLogger::log( 'crayn-safe/plugin-' . $action, 'plugin', 0, $before, array( 'rolled_back' => true, 'undone' => $undone ), 'state change rolled back after health check failure - ' . $reason );

			return array(
				'dry_run'     => false,
				'before'      => $before,
				'after'       => $before,
				'change_id'   => $change_id,
				'plugin_file' => $file,
				'rolled_back' => true,
				'reason'      => $reason,
				'health'      => sprintf( 'Site health failed: %s (change was undone).', $reason ),
			);
		}

		$after = array(
			'plugin_file'    => $file,
			'active'         => 'activate' === $action,
			'network_active' => $was_network && 'activate' === $action,
		);

		$change_id = ChangeLogger::log( 'crayn-safe/plugin-' . $action, 'plugin', 0, $before, $after, sprintf( 'plugin %sd (%s)', $action, $file ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Plugin %s was %sd.', $file, $action ) );
		}

		return array(
			'dry_run'     => false,
			'before'      => $before,
			'after'       => $after,
			'change_id'   => $change_id,
			'plugin_file' => $file,
			'rolled_back' => false,
			'reason'      => '',
			'health'      => 'ok',
		);
	}

	/**
	 * Shared registration for plugin-rollback.
	 *
	 * @return void
	 */
	private static function register_plugin_rollback() {
		wp_register_ability(
			'crayn-safe/plugin-rollback',
			array(
				'label'              => 'Rollback plugin from backup',
				'description'        => 'Restore a plugin from a backup zip created by crayn-safe/plugin-update (see backups-list for file names). Plugin files are restored from the zip and the plugin is re-activated if it was active at backup time. A health check runs after the restore. Logged with change_id.',
				'category'           => 'crayn-plugins',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'backup_file' => array(
							'type'        => 'string',
							'description' => 'Backup zip file name as returned by backups-list, e.g. "akismet-5.3.1-20250101120000.zip".',
						),
						'dry_run'     => Safety::dry_run_property(),
					),
					'required'   => array( 'backup_file' ),
				),
				'output_schema'      => self::change_output_schema(
					array(
						'plugin_file'   => array( 'type' => 'string' ),
						'restored_version' => array( 'type' => 'string' ),
						'reactivated'   => array( 'type' => 'boolean' ),
						'rolled_back'   => array( 'type' => 'boolean', 'description' => 'True when the rollback broke the site and was undone automatically.' ),
						'health'        => array( 'type' => 'string' ),
						'error'         => array( 'type' => 'string', 'description' => 'Filled when the dry run cannot be executed.' ),
					)
				),
				'execute_callback'   => array( self::class, 'execute_plugin_rollback' ),
				'permission_callback' => array( self::class, 'permission_update' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Rollback Plugin From Backup' ) ),
			)
		);
	}

	/**
	 * Execute plugin-rollback.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_plugin_rollback( $input ) {
		$dry_run = Safety::dry_run( $input );
		$guard   = Safety::guard( 'crayn-plugins', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot update plugins.' );
		}

		$backup_file = isset( $input['backup_file'] ) ? (string) $input['backup_file'] : '';
		$backups     = PluginBackup::list_backups();
		$backup      = null;
		foreach ( $backups as $item ) {
			if ( $item['file'] === basename( $backup_file ) ) {
				$backup = $item;
				break;
			}
		}
		if ( null === $backup ) {
			return new \WP_Error( 'crayn_safe_backup_not_found', sprintf( 'Backup "%s" not found. Use backups-list to get valid file names.', $backup_file ) );
		}

		$plugin = self::resolve_plugin( $backup['plugin'] );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		if ( self::is_self( $plugin['file'] ) ) {
			return self::self_error();
		}

		$before = array(
			'plugin_file'    => $backup['plugin'],
			'version'        => $plugin['Version'],
			'active'         => $plugin['active'],
			'network_active' => $plugin['network_active'],
		);
		$after_planned = array(
			'plugin_file'      => $backup['plugin'],
			'restored_version' => $backup['version'],
			'backup_file'      => $backup['file'],
		);

		if ( $dry_run ) {
			return array(
				'dry_run'          => true,
				'before'           => $before,
				'after'            => $after_planned,
				'change_id'        => 0,
				'plugin_file'      => $backup['plugin'],
				'restored_version' => '',
				'reactivated'      => false,
				'health'           => '',
				'error'            => '',
			);
		}

		// The site must be healthy before we touch it, and we snapshot the current
		// state first so a rollback that breaks the site can itself be undone.
		$pre_health = HealthCheck::check();
		if ( is_wp_error( $pre_health ) ) {
			return new \WP_Error( 'crayn_safe_unhealthy_before', 'Rollback not started: the site health check already fails (' . $pre_health->get_error_message() . '). Nothing was changed.' );
		}
		$current_backup = PluginBackup::backup( $plugin['file'] );
		if ( is_wp_error( $current_backup ) ) {
			return new \WP_Error( 'crayn_safe_backup_failed', 'Rollback aborted, nothing was written: ' . $current_backup->get_error_message() );
		}

		$result = PluginBackup::restore( $backup['file'], true );
		if ( is_wp_error( $result ) ) {
			// A failed restore may leave the folder half written: put the current state back.
			PluginBackup::restore( $current_backup['file'], true );
			ChangeLogger::log( 'crayn-safe/plugin-rollback', 'plugin', 0, $before, array( 'backup_file' => $backup['file'] ), 'rollback failed - ' . $result->get_error_message() );
			return $result;
		}

		$health    = HealthCheck::check();
		$health_ok = ! is_wp_error( $health );

		if ( ! $health_ok ) {
			$reason   = $health->get_error_message();
			$undo     = PluginBackup::restore( $current_backup['file'], true );
			$undo_ok  = ! is_wp_error( $undo );
			$fail_id  = ChangeLogger::log( 'crayn-safe/plugin-rollback', 'plugin', 0, $before, array( 'backup_file' => $backup['file'], 'rolled_back' => true, 'undone' => $undo_ok ), 'rollback undone after health check failure - ' . $reason . ( $undo_ok ? '' : ' (undo FAILED: ' . $undo->get_error_message() . ')' ) );
			return array(
				'dry_run'          => false,
				'before'           => $before,
				'after'            => $before,
				'change_id'        => $fail_id,
				'plugin_file'      => $backup['plugin'],
				'restored_version' => '',
				'reactivated'      => false,
				'rolled_back'      => true,
				'health'           => sprintf( 'Site health failed after the rollback: %s. The previous state (version %s) was %s.', $reason, $plugin['Version'], $undo_ok ? 'restored' : 'NOT restored - check the site' ),
				'error'            => '',
			);
		}

		$after = array(
			'plugin_file'      => $result['plugin_file'],
			'restored_version' => $result['version'],
			'reactivated'      => (bool) $result['reactivated'],
			'backup_file'      => $backup['file'],
		);

		$change_id = ChangeLogger::log( 'crayn-safe/plugin-rollback', 'plugin', 0, $before, array_merge( $after, array( 'health_ok' => $health_ok ) ), sprintf( 'plugin rolled back to %s from %s', $result['version'], $backup['file'] ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Plugin %s was rolled back to %s.', $result['plugin_file'], $result['version'] ) );
		}

		return array(
			'dry_run'          => false,
			'before'           => $before,
			'after'            => $after,
			'change_id'        => $change_id,
			'plugin_file'      => $result['plugin_file'],
			'restored_version' => $result['version'],
			'reactivated'      => (bool) $result['reactivated'],
			'rolled_back'      => false,
			'health'           => 'ok',
			'error'            => '',
		);
	}

	/**
	 * Shared registration for backups-list.
	 *
	 * @return void
	 */
	private static function register_backups_list() {
		wp_register_ability(
			'crayn-safe/backups-list',
			array(
				'label'              => 'List backups',
				'description'        => 'List plugin backup zips stored in wp-content/crayn-safe-backups/plugins with size, date, plugin and version. Read only.',
				'category'           => 'crayn-plugins',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_file' => array(
							'type'        => 'string',
							'description' => 'Optional: only backups of this plugin.',
						),
					),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'total' => array( 'type' => 'integer' ),
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'file'       => array( 'type' => 'string' ),
									'plugin'     => array( 'type' => 'string' ),
									'slug'       => array( 'type' => 'string' ),
									'version'    => array( 'type' => 'string' ),
									'active'     => array( 'type' => 'boolean' ),
									'size'       => array( 'type' => 'integer' ),
									'size_human'  => array( 'type' => 'string' ),
									'created_at' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_backups_list' ),
				'permission_callback' => array( self::class, 'permission_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, false, 'List Backups' ) ),
			)
		);
	}

	/**
	 * Execute backups-list.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_backups_list( $input ) {
		$guard = Safety::guard( 'crayn-plugins', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$items = PluginBackup::list_backups( isset( $input['plugin_file'] ) ? (string) $input['plugin_file'] : null );
		return array(
			'total' => count( $items ),
			'items' => $items,
		);
	}

	/**
	 * Shared registration for updates-list.
	 *
	 * @return void
	 */
	private static function register_updates_list() {
		wp_register_ability(
			'crayn-safe/updates-list',
			array(
				'label'              => 'List available updates',
				'description'        => 'List available WordPress core, plugin and theme updates with current and new versions. Read only.',
				'category'           => 'crayn-plugins',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'core' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'installed' => array( 'type' => 'string' ),
									'new'       => array( 'type' => 'string' ),
								),
							),
						),
						'plugins' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'plugin_file' => array( 'type' => 'string' ),
									'current'     => array( 'type' => 'string' ),
									'new'         => array( 'type' => 'string' ),
								),
							),
						),
						'themes' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'theme'   => array( 'type' => 'string' ),
									'current' => array( 'type' => 'string' ),
									'new'     => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'   => array( self::class, 'execute_updates_list' ),
				'permission_callback' => array( self::class, 'permission_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, false, 'List Available Updates' ) ),
			)
		);
	}

	/**
	 * Execute updates-list.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_updates_list( $input ) {
		$guard = Safety::guard( 'crayn-plugins', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		self::load_plugin_includes();
		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( file_exists( ABSPATH . 'wp-admin/includes/theme.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$core = array();
		foreach ( (array) get_core_updates() as $core_update ) {
			if ( is_object( $core_update ) && isset( $core_update->current ) ) {
				// "current" holds the offered (new) version in the core updates API response.
				$core[] = array(
					'installed' => (string) get_bloginfo( 'version' ),
					'new'       => (string) $core_update->current, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				);
			}
		}

		$plugins = array();
		self::refresh_update_data();
		foreach ( get_plugin_updates() as $plugin_update ) {
			$update = is_object( $plugin_update ) && isset( $plugin_update->update ) ? $plugin_update->update : $plugin_update;
			if ( is_object( $update ) && isset( $update->plugin ) && isset( $update->new_version ) ) {
				$installed = '';
				$all_plugins = get_plugins();
				if ( isset( $all_plugins[ (string) $update->plugin ]['Version'] ) ) {
					$installed = $all_plugins[ (string) $update->plugin ]['Version'];
				}
				$plugins[] = array(
					'plugin_file' => (string) $update->plugin, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'current'     => $installed,
					'new'         => (string) $update->new_version, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				);
			}
		}

		$themes = array();
		$theme_updates = function_exists( 'get_theme_updates' ) ? get_theme_updates() : array();
		foreach ( (array) $theme_updates as $stylesheet => $theme ) {
			$new = '';
			if ( is_object( $theme ) && isset( $theme->update ) && ! empty( $theme->update->new_version ) ) {
				$new = (string) $theme->update->new_version;
			}
			$themes[] = array(
				'theme'   => is_string( $stylesheet ) ? $stylesheet : (string) $stylesheet,
				'current' => is_object( $theme ) && isset( $theme->version ) ? (string) $theme->version : '',
				'new'     => $new,
			);
		}

		return array(
			'core'    => $core,
			'plugins' => $plugins,
			'themes'  => $themes,
			'total'   => count( $core ) + count( $plugins ) + count( $themes ),
		);
	}

	/**
	 * Permission for read-type plugin abilities.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission_read( $input = array() ) {
		return current_user_can( 'activate_plugins' ) && null === Safety::guard( 'crayn-plugins', false );
	}

	/**
	 * Permission for plugin update/rollback writes.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission_update( $input = array() ) {
		return current_user_can( 'update_plugins' ) && null === Safety::guard( 'crayn-plugins', false );
	}

	/**
	 * Permission for activation writes.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission_activate( $input = array() ) {
		return current_user_can( 'activate_plugins' ) && null === Safety::guard( 'crayn-plugins', false );
	}
}
