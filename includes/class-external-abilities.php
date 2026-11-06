<?php
/**
 * Safe exposure of third-party SEO plugin abilities (Rank Math and Yoast SEO).
 *
 * - Read-only abilities (annotations.readonly = true) are exposed directly on the MCP server.
 * - Everything else is NOT exposed raw: a wrapper ability "crayn-safe/ext-<name>" honours read-only
 *   mode and dry_run, snapshots the plugin's options before running the original ability,
 *   logs the before/after option state and can be reverted with change-revert.
 * - While the setting is on, the original writing abilities are also hidden from the MCP
 *   adapter's default server (meta.mcp.public = false) so they cannot be reached without the wrapper.
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Wrapper factory + option snapshots for external abilities.
 */
class ExternalAbilities {

	/**
	 * Ability name prefixes that are handled.
	 *
	 * @var string[]
	 */
	const PREFIXES = array( 'rank-math/', 'yoast-seo/' );

	/**
	 * Original writing abilities that were public before we hid them (name => true).
	 *
	 * @var array<string, bool>
	 */
	private static $wrapped = array();

	/**
	 * Hook everything up. Must run before abilities are registered.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_register_ability_args', array( self::class, 'filter_args' ), 10, 2 );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_wrappers' ), 99 );
	}

	/**
	 * Does this ability name belong to a handled plugin?
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	private static function handled( $name ) {
		foreach ( self::PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Name of the wrapper ability for an original ability.
	 *
	 * @param string $name Original ability name.
	 * @return string
	 */
	public static function wrapper_name( $name ) {
		$slug = preg_replace( '/[^a-z0-9-]+/', '-', strtolower( str_replace( '/', '-', $name ) ) );
		return 'crayn-safe/ext-' . trim( $slug, '-' );
	}

	/**
	 * Hide original writing abilities from MCP discovery (they stay callable inside PHP).
	 *
	 * This holds whatever the external_abilities setting says: turning the setting off
	 * must never leave a raw, unlogged writing ability on the adapter's default server.
	 *
	 * @param array  $args Ability args.
	 * @param string $name Ability name.
	 * @return array
	 */
	public static function filter_args( $args, $name ) {
		if ( ! is_array( $args ) || ! self::handled( (string) $name ) ) {
			return $args;
		}
		if ( empty( $args['meta']['mcp']['public'] ) ) {
			return $args;
		}
		if ( ! empty( $args['meta']['annotations']['readonly'] ) ) {
			return $args; // Read only: exposed directly.
		}
		self::$wrapped[ (string) $name ] = true;
		$args['meta']['mcp']['public']   = false;
		return $args;
	}

	/**
	 * Register one wrapper per hidden writing ability.
	 *
	 * @return void
	 */
	public static function register_wrappers() {
		if ( ! Settings::external_abilities() || ! Settings::category_enabled( 'seo' ) || ! function_exists( 'wp_get_ability' ) ) {
			return;
		}
		foreach ( array_keys( self::$wrapped ) as $name ) {
			$orig = wp_get_ability( $name );
			if ( $orig ) {
				self::register_wrapper( $name, $orig );
			}
		}
	}

	/**
	 * Names to add to the MCP server tool list.
	 *
	 * @return string[]
	 */
	public static function server_names() {
		if ( ! Settings::external_abilities() || ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( ! self::handled( $name ) ) {
				continue;
			}
			if ( isset( self::$wrapped[ $name ] ) ) {
				$wrapper = self::wrapper_name( $name );
				if ( wp_get_ability( $wrapper ) ) {
					$names[] = $wrapper;
				}
				continue;
			}
			$meta = $ability->get_meta();
			if ( ! empty( $meta['mcp']['public'] ) && ! empty( $meta['annotations']['readonly'] ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Option names to snapshot for an original ability.
	 *
	 * @param string $name Original ability name.
	 * @return string[]
	 */
	public static function option_names( $name ) {
		global $wpdb;

		if ( 0 === strpos( $name, 'rank-math/' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'rank-math-options-' ) . '%' ) );
			$found = is_array( $found ) ? $found : array();
			return array_values( array_unique( array_merge( $found, array( 'rank_math_modules' ) ) ) );
		}
		if ( 0 === strpos( $name, 'yoast-seo/' ) ) {
			return array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_llmstxt' );
		}
		return array();
	}

	/**
	 * Snapshot options as name => { exists, value }.
	 *
	 * @param string[] $names Option names.
	 * @return array<string, array{exists: bool, value: mixed}>
	 */
	public static function snapshot_options( array $names ) {
		$missing = '__crayn_safe_missing__';
		$snap    = array();
		foreach ( $names as $option ) {
			$value          = get_option( $option, $missing );
			$exists         = $missing !== $value;
			$snap[ $option ] = array(
				'exists' => $exists,
				'value'  => $exists ? $value : null,
			);
		}
		return $snap;
	}

	/**
	 * Keep only options whose state differs.
	 *
	 * @param array $before Snapshot before.
	 * @param array $after  Snapshot after.
	 * @return string[]
	 */
	private static function changed_options( array $before, array $after ) {
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $name ) {
			$b = isset( $before[ $name ] ) ? wp_json_encode( $before[ $name ] ) : null;
			$a = isset( $after[ $name ] ) ? wp_json_encode( $after[ $name ] ) : null;
			if ( $b !== $a ) {
				$changed[] = $name;
			}
		}
		return $changed;
	}

	/**
	 * Register the wrapper for one original ability.
	 *
	 * @param string      $name Original name.
	 * @param \WP_Ability $orig Original ability.
	 * @return void
	 */
	private static function register_wrapper( $name, $orig ) {
		$input_schema = $orig->get_input_schema();
		if ( ! is_array( $input_schema ) || empty( $input_schema ) ) {
			$input_schema = array( 'type' => 'object', 'properties' => array() );
		}
		if ( ! isset( $input_schema['properties'] ) || ! is_array( $input_schema['properties'] ) ) {
			$input_schema['properties'] = array();
		}
		$input_schema['properties']['dry_run'] = Safety::dry_run_property();
		unset( $input_schema['default'] );

		$any = array( 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' );

		wp_register_ability(
			self::wrapper_name( $name ),
			array(
				'label'               => 'Safe: ' . $orig->get_label(),
				'description'         => $orig->get_description() . ' [Crayn Safe wrapper: supports dry_run, honours read-only mode, snapshots the plugin options first, logs the change and can be reverted with change-revert.]',
				'category'            => 'crayn-seo',
				'input_schema'        => $input_schema,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'dry_run'   => array( 'type' => 'boolean' ),
						'before'    => array( 'type' => array( 'object', 'null' ), 'description' => 'Plugin options (only the changed ones after a real run, all of them on a dry run).' ),
						'after'     => array( 'type' => array( 'object', 'null' ), 'description' => 'Options after the run, or the planned input on a dry run.' ),
						'change_id' => array( 'type' => 'integer' ),
						'result'    => array( 'type' => $any, 'description' => 'Result of the original ability.' ),
					),
				),
				'execute_callback'    => static function ( $input = null ) use ( $name ) {
					return ExternalAbilities::run( $name, $input );
				},
				'permission_callback' => static function ( $input = null ) use ( $name ) {
					return ExternalAbilities::permission( $name, $input );
				},
				'meta'                => Safety::meta( Safety::annotations( false, false, false, 'Safe: ' . $orig->get_label() ) ),
			)
		);
	}

	/**
	 * Permission of a wrapper: original permission + manage_options (site-wide settings).
	 *
	 * @param string $name  Original ability name.
	 * @param mixed  $input Input.
	 * @return bool
	 */
	public static function permission( $name, $input ) {
		if ( ! current_user_can( 'manage_options' ) || null !== Safety::guard( 'crayn-seo', false ) ) {
			return false;
		}
		$orig = wp_get_ability( $name );
		if ( ! $orig ) {
			return false;
		}
		$input = is_array( $input ) ? $input : array();
		unset( $input['dry_run'] );
		return true === $orig->check_permissions( $input );
	}

	/**
	 * Run a wrapped ability.
	 *
	 * @param string $name  Original ability name.
	 * @param mixed  $input Input.
	 * @return array|\WP_Error
	 */
	public static function run( $name, $input ) {
		$input   = is_array( $input ) ? $input : array();
		$dry_run = Safety::dry_run( $input );
		unset( $input['dry_run'] );

		$guard = Safety::guard( 'crayn-seo', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$orig = wp_get_ability( $name );
		if ( ! $orig ) {
			return new \WP_Error( 'crayn_safe_ability_missing', sprintf( 'The wrapped ability "%s" is no longer registered.', $name ) );
		}

		$names  = self::option_names( $name );
		$before = self::snapshot_options( $names );

		if ( $dry_run ) {
			return array(
				'dry_run'   => true,
				'before'    => $before,
				'after'     => array( 'planned_input' => $input, 'note' => 'The original ability was not executed.' ),
				'change_id' => 0,
				'result'    => null,
			);
		}

		$result = $orig->execute( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::flush_caches();
		$after   = self::snapshot_options( $names );
		$changed = self::changed_options( $before, $after );

		$change_id = ChangeLogger::log(
			'crayn-safe/ext-' . substr( self::wrapper_name( $name ), strlen( 'crayn-safe/ext-' ) ),
			'options',
			0,
			array(
				'original' => $name,
				'input'    => $input,
				'options'  => array_intersect_key( $before, array_flip( $changed ) ),
			),
			array(
				'original' => $name,
				'options'  => array_intersect_key( $after, array_flip( $changed ) ),
			),
			sprintf( '%s executed; %d option(s) changed%s', $name, count( $changed ), $changed ? ' (' . implode( ', ', $changed ) . ')' : '' )
		);
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( '%s was executed.', $name ) );
		}

		return array(
			'dry_run'   => false,
			'before'    => array_intersect_key( $before, array_flip( $changed ) ),
			'after'     => array_intersect_key( $after, array_flip( $changed ) ),
			'change_id' => $change_id,
			'result'    => $result,
		);
	}

	/**
	 * Drop plugin-side settings caches after options were written.
	 *
	 * @return void
	 */
	private static function flush_caches() {
		if ( function_exists( 'rank_math' ) ) {
			$rm = rank_math();
			if ( is_object( $rm ) && isset( $rm->settings ) && is_object( $rm->settings ) && method_exists( $rm->settings, 'reset' ) ) {
				$rm->settings->reset();
			}
		}
		if ( class_exists( '\\WPSEO_Options' ) && method_exists( '\\WPSEO_Options', 'clear_cache' ) ) {
			\WPSEO_Options::clear_cache();
		}
	}

	/**
	 * Revert a logged wrapper change: put the changed options back.
	 *
	 * @param array $before Decoded before_data.
	 * @param array $after  Decoded after_data.
	 * @return string|\WP_Error
	 */
	public static function revert( array $before, array $after ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'Reverting option changes requires manage_options.' );
		}
		$allowed = isset( $before['original'] ) ? self::option_names( (string) $before['original'] ) : array();
		$options = isset( $before['options'] ) && is_array( $before['options'] ) ? $before['options'] : array();
		if ( empty( $options ) ) {
			return 'The original ability changed no options; nothing to restore.';
		}
		$restored = array();
		foreach ( $options as $option => $state ) {
			if ( ! in_array( $option, $allowed, true ) || ! is_array( $state ) ) {
				continue;
			}
			if ( ! empty( $state['exists'] ) ) {
				update_option( $option, $state['value'] );
			} else {
				delete_option( $option );
			}
			$restored[] = $option;
		}
		self::flush_caches();
		return sprintf( 'Options restored: %s.', $restored ? implode( ', ', $restored ) : '(none)' );
	}
}
