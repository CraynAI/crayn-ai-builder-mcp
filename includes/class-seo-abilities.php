<?php
/**
 * SEO abilities with auto-detected Rank Math / Yoast support.
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers "crayn-seo" abilities. Reads/writes the SEO plugin meta fields of a
 * post and logs old values so seo-update can be reverted.
 */
class SeoAbilities extends AbilitiesBase {

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		$names = array(
			'crayn-safe/seo-get',
			'crayn-safe/seo-update',
		);
		if ( RankMathAbilities::is_active() ) {
			$names = array_merge( $names, RankMathAbilities::ability_names() );
		}
		return $names;
	}

	/**
	 * Register every SEO ability.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_seo_get();
		self::register_seo_update();
	}

	/**
	 * Detect the active SEO plugin.
	 *
	 * @return string 'rank-math', 'yoast' or '' when none is active.
	 */
	public static function detected_plugin() {
		if ( ( defined( 'RANK_MATH_VERSION' ) && RANK_MATH_VERSION ) || class_exists( 'RankMath' ) ) {
			return 'rank-math';
		}
		if ( ( defined( 'WPSEO_VERSION' ) && WPSEO_VERSION ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		return '';
	}

	/**
	 * Error used by both abilities when no SEO plugin is active.
	 *
	 * @return \WP_Error
	 */
	private static function no_plugin_error() {
		return new \WP_Error(
			'crayn_safe_no_seo_plugin',
			__( 'No supported SEO plugin detected. Crayn Safe MCP supports Rank Math and Yoast SEO; install and activate one of them.', 'crayn-safe-mcp' )
		);
	}

	/**
	 * Snapshot the SEO fields of a post (empty array when no SEO plugin).
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>
	 */
	public static function seo_snapshot( $post_id ) {
		$plugin = self::detected_plugin();
		if ( '' === $plugin ) {
			return array();
		}

		$values = self::read_values( $post_id, $plugin );
		if ( null === $values ) {
			return array();
		}

		return array_merge( array( 'plugin' => $plugin ), $values, array( 'raw' => self::raw_meta( $post_id, $plugin ) ) );
	}

	/**
	 * Meta keys used by each supported SEO plugin.
	 *
	 * @param string $plugin 'rank-math' or 'yoast'.
	 * @return string[]
	 */
	private static function meta_keys( $plugin ) {
		if ( 'rank-math' === $plugin ) {
			return array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_canonical_url', 'rank_math_robots' );
		}
		if ( 'yoast' === $plugin ) {
			return array(
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'_yoast_wpseo_focuskw',
				'_yoast_wpseo_canonical',
				'_yoast_wpseo_meta-robots-noindex',
				'_yoast_wpseo_meta-robots-nofollow',
				'_yoast_wpseo_meta-robots-adv',
			);
		}
		return array();
	}

	/**
	 * Exact raw meta state (value + whether the row existed) for lossless reverts.
	 *
	 * @param int    $post_id Post id.
	 * @param string $plugin  Plugin slug.
	 * @return array<string, array{exists: bool, value: mixed}>
	 */
	private static function raw_meta( $post_id, $plugin ) {
		$raw = array();
		foreach ( self::meta_keys( $plugin ) as $key ) {
			$exists      = metadata_exists( 'post', $post_id, $key );
			$raw[ $key ] = array(
				'exists' => $exists,
				'value'  => $exists ? get_post_meta( $post_id, $key, true ) : '',
			);
		}
		return $raw;
	}

	/**
	 * Read raw SEO values for a post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $plugin  'rank-math' or 'yoast'.
	 * @return array<string, mixed>|null
	 */
	private static function read_values( $post_id, $plugin ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		return self::robots_out( $post_id, $plugin );
	}

	/**
	 * Read SEO field values and normalize robots into "index,follow" style strings.
	 *
	 * @param int    $post_id Post id.
	 * @param string $plugin  Plugin slug.
	 * @return array<string, string>|null
	 */
	private static function robots_out( $post_id, $plugin ) {
		if ( 'rank-math' === $plugin ) {
			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			$flags  = is_array( $robots ) ? $robots : array_filter( array_map( 'trim', explode( ',', (string) $robots ) ) );

			$index  = in_array( 'noindex', $flags, true ) ? 'noindex' : 'index';
			$follow = in_array( 'nofollow', $flags, true ) ? 'nofollow' : 'follow';

			return array(
				'title'         => (string) get_post_meta( $post_id, 'rank_math_title', true ),
				'description'   => (string) get_post_meta( $post_id, 'rank_math_description', true ),
				'focus_keyword' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
				'canonical'     => (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ),
				'robots'        => implode( ',', array( $index, $follow ) ),
			);
		}

		if ( 'yoast' === $plugin ) {
			$noindex  = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			$nofollow = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );

			return array(
				'title'         => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
				'description'   => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
				'focus_keyword' => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
				'canonical'     => (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
				'robots'        => implode(
					',',
					array(
						( (string) $noindex === '1' ) ? 'noindex' : 'index',
						( (string) $nofollow === '1' ) ? 'nofollow' : 'follow',
					)
				),
			);
		}

		return null;
	}

	/**
	 * crayn-safe/seo-get
	 *
	 * @return void
	 */
	private static function register_seo_get() {
		wp_register_ability(
			'crayn-safe/seo-get',
			array(
				'label'              => 'Get SEO data',
				'description'        => 'Get SEO fields of a post from the active SEO plugin (Rank Math or Yoast): title, description, focus keyword, canonical URL and robots directives.',
				'category'           => 'crayn-seo',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'plugin'        => array( 'type' => 'string', 'description' => 'Detected SEO plugin: rank-math or yoast.' ),
						'post_id'       => array( 'type' => 'integer' ),
						'title'         => array( 'type' => 'string', 'description' => 'SEO title (may contain variables like %%title%%).' ),
						'description'   => array( 'type' => 'string' ),
						'focus_keyword' => array( 'type' => 'string' ),
						'canonical'     => array( 'type' => 'string' ),
						'robots'        => array( 'type' => 'string', 'description' => 'Normalized robots string, e.g. "index,follow" or "noindex,nofollow".' ),
					),
				),
				'execute_callback'   => array( self::class, 'execute_seo_get' ),
				'permission_callback' => array( self::class, 'permission_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, true, 'Get SEO Data' ) ),
			)
		);
	}

	/**
	 * Execute seo-get.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_seo_get( $input ) {
		$guard = Safety::guard( 'crayn-seo', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$plugin = self::detected_plugin();
		if ( '' === $plugin ) {
			return self::no_plugin_error();
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'crayn_safe_post_not_found', sprintf( 'Post %d does not exist.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot access this post.' );
		}

		$values = self::robots_out( $post_id, $plugin );
		if ( null === $values ) {
			return self::no_plugin_error();
		}

		return array_merge( array( 'plugin' => $plugin, 'post_id' => $post_id ), $values );
	}

	/**
	 * crayn-safe/seo-update
	 *
	 * @return void
	 */
	private static function register_seo_update() {
		wp_register_ability(
			'crayn-safe/seo-update',
			array(
				'label'              => 'Update SEO data',
				'description'        => 'Update SEO fields of a post in the active SEO plugin (Rank Math or Yoast): title, description, focus keyword, canonical URL, robots directives. Old values are logged with the change and can be reverted via change-revert.',
				'category'           => 'crayn-seo',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => 'Post ID whose SEO fields are updated.',
						),
						'title'         => array( 'type' => 'string', 'description' => 'New SEO title.' ),
						'description'   => array( 'type' => 'string', 'description' => 'New meta description.' ),
						'focus_keyword' => array( 'type' => 'string', 'description' => 'New focus keyword.' ),
						'canonical'     => array( 'type' => 'string', 'description' => 'New canonical URL.' ),
						'robots'        => array(
							'type'        => 'string',
							'description' => 'Robots directives as a comma string, e.g. "index,follow", "noindex", "noindex,nofollow".',
						),
						'dry_run'       => Safety::dry_run_property(),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'      => self::change_output_schema(
					array( 'plugin' => array( 'type' => 'string' ) )
				),
				'execute_callback'   => array( self::class, 'execute_seo_update' ),
				'permission_callback' => array( self::class, 'permission_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Update SEO Data' ) ),
			)
		);
	}

	/**
	 * Execute seo-update.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_seo_update( $input ) {
		$dry_run = Safety::dry_run( $input );
		$guard   = Safety::guard( 'crayn-seo', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$plugin = self::detected_plugin();
		if ( '' === $plugin ) {
			return self::no_plugin_error();
		}

		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'crayn_safe_post_not_found', sprintf( 'Post %d does not exist.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot edit this post.' );
		}

		$before = self::robots_out( $post_id, $plugin );
		if ( null === $before ) {
			return self::no_plugin_error();
		}

		$raw_before = self::raw_meta( $post_id, $plugin );

		$after = $before;
		foreach ( array( 'title', 'description', 'focus_keyword' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$after[ $field ] = sanitize_text_field( (string) $input[ $field ] );
			}
		}
		if ( isset( $input['canonical'] ) ) {
			$canonical = trim( (string) $input['canonical'] );
			if ( '' !== $canonical ) {
				$canonical = esc_url_raw( $canonical );
				if ( '' === $canonical ) {
					return new \WP_Error( 'crayn_safe_invalid_canonical', 'The canonical value must be a valid http/https URL.' );
				}
			}
			$after['canonical'] = $canonical;
		}
		if ( isset( $input['robots'] ) ) {
			$after['robots'] = self::robots_string( (string) $input['robots'] );
		}

		if ( $dry_run ) {
			return array(
				'dry_run'   => true,
				'before'    => array_merge( array( 'plugin' => $plugin ), $before ),
				'after'     => array_merge( array( 'plugin' => $plugin ), $after ),
				'change_id' => 0,
				'plugin'    => $plugin,
			);
		}

		if ( $after === $before ) {
			// Same answer as rankmath-update: nothing changed, nothing to log or revert.
			return array(
				'dry_run'   => false,
				'before'    => array_merge( array( 'plugin' => $plugin ), $before ),
				'after'     => array_merge( array( 'plugin' => $plugin, 'post_id' => $post_id ), $after ),
				'change_id' => 0,
				'plugin'    => $plugin,
				'message'   => 'Nothing changed.',
			);
		}

		$result = self::write_values( $post_id, $plugin, $before, $after );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$change_id = ChangeLogger::log(
			'crayn-safe/seo-update',
			'seo',
			$post_id,
			array_merge( array( 'plugin' => $plugin ), $before, array( 'raw' => $raw_before ) ),
			array_merge( array( 'plugin' => $plugin, 'post_id' => $post_id ), $after ),
			'SEO fields updated via ' . $plugin
		);
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'SEO fields of post %d were updated.', $post_id ) );
		}

		return array(
			'dry_run'   => false,
			'before'    => array_merge( array( 'plugin' => $plugin ), $before ),
			'after'     => array_merge( array( 'plugin' => $plugin, 'post_id' => $post_id ), $after ),
			'change_id' => $change_id,
			'plugin'    => $plugin,
		);
	}

	/**
	 * Normalize a robots input string to "index,nofollow" style.
	 *
	 * @param string $raw Raw robots input.
	 * @return string
	 */
	private static function robots_string( $raw ) {
		$tokens = array_filter(
			array_map( 'trim', explode( ',', strtolower( (string) $raw ) ) ),
			static function ( $token ) {
				return '' !== $token;
			}
		);
		if ( empty( $tokens ) ) {
			return 'index,follow';
		}

		$index  = in_array( 'noindex', $tokens, true ) ? 'noindex' : 'index';
		$follow = in_array( 'nofollow', $tokens, true ) ? 'nofollow' : 'follow';
		return implode( ',', array( $index, $follow ) );
	}

	/**
	 * Write one meta value; an empty string removes the row instead of storing an empty one.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return void
	 */
	private static function set_meta( $post_id, $key, $value ) {
		if ( '' === $value || array() === $value || null === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * Persist SEO values with the plugin-specific meta keys. Only fields that differ are written.
	 *
	 * @param int    $post_id Post id.
	 * @param string $plugin  Plugin slug.
	 * @param array  $before  Old values.
	 * @param array  $after   New values.
	 * @return true|\WP_Error
	 */
	private static function write_values( $post_id, $plugin, array $before, array $after ) {
		if ( 'rank-math' === $plugin ) {
			$map = array(
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
				'canonical'     => 'rank_math_canonical_url',
			);
			foreach ( $map as $field => $key ) {
				if ( $before[ $field ] !== $after[ $field ] ) {
					self::set_meta( $post_id, $key, $after[ $field ] );
				}
			}
			if ( $before['robots'] !== $after['robots'] ) {
				// Keep advanced flags (noarchive, nosnippet, ...) that are already stored.
				$existing = get_post_meta( $post_id, 'rank_math_robots', true );
				$existing = is_array( $existing ) ? $existing : array();
				$keep     = array_diff( $existing, array( 'index', 'noindex', 'follow', 'nofollow' ) );
				$flags    = array_values( array_unique( array_merge( self::robots_flags( $after['robots'] ), $keep ) ) );
				update_post_meta( $post_id, 'rank_math_robots', $flags );
			}
			return true;
		}

		if ( 'yoast' === $plugin ) {
			$map = array(
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
				'canonical'     => '_yoast_wpseo_canonical',
			);
			foreach ( $map as $field => $key ) {
				if ( $before[ $field ] !== $after[ $field ] ) {
					self::set_meta( $post_id, $key, $after[ $field ] );
				}
			}
			if ( $before['robots'] !== $after['robots'] ) {
				$tokens = explode( ',', $after['robots'] );
				// Yoast: noindex 1 = noindex, 2 = explicit index; nofollow 1 = nofollow, 0 = follow.
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', in_array( 'noindex', $tokens, true ) ? '1' : '2' );
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', in_array( 'nofollow', $tokens, true ) ? '1' : '0' );
			}
			return true;
		}

		return self::no_plugin_error();
	}

	/**
	 * Convert a robots string into Rank Math flags array.
	 *
	 * @param string $robots Robots string.
	 * @return string[]
	 */
	private static function robots_flags( $robots ) {
		$tokens = array_map( 'trim', explode( ',', (string) $robots ) );
		return array(
			in_array( 'noindex', $tokens, true ) ? 'noindex' : 'index',
			in_array( 'nofollow', $tokens, true ) ? 'nofollow' : 'follow',
		);
	}

	/**
	 * Restore SEO values from a snapshot (used by change-revert).
	 * When the snapshot carries the exact raw meta state it is restored losslessly
	 * (rows that did not exist are deleted again).
	 *
	 * @param int   $post_id Post id.
	 * @param array $before  Snapshot from the log row (with plugin key).
	 * @return true|\WP_Error
	 */
	public static function restore_snapshot( $post_id, array $before ) {
		$plugin = isset( $before['plugin'] ) ? (string) $before['plugin'] : '';
		if ( '' === $plugin ) {
			return new \WP_Error( 'crayn_safe_no_seo_snapshot', 'The change log has no SEO snapshot for this change.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot edit this post.' );
		}
		if ( $plugin !== self::detected_plugin() ) {
			return new \WP_Error( 'crayn_safe_seo_plugin_changed', 'The active SEO plugin differs from the one recorded in the snapshot; refusing to restore.' );
		}

		if ( isset( $before['raw'] ) && is_array( $before['raw'] ) ) {
			$allowed = self::meta_keys( $plugin );
			foreach ( $before['raw'] as $key => $state ) {
				if ( ! in_array( $key, $allowed, true ) || ! is_array( $state ) ) {
					continue;
				}
				if ( ! empty( $state['exists'] ) ) {
					update_post_meta( $post_id, $key, wp_slash( $state['value'] ) );
				} else {
					delete_post_meta( $post_id, $key );
				}
			}
			return true;
		}

		// Legacy snapshots without raw state.
		$current = self::robots_out( $post_id, $plugin );
		if ( null === $current ) {
			return self::no_plugin_error();
		}
		$values = array();
		foreach ( array( 'title', 'description', 'focus_keyword', 'canonical', 'robots' ) as $field ) {
			$values[ $field ] = isset( $before[ $field ] ) ? (string) $before[ $field ] : '';
		}
		return self::write_values( $post_id, $plugin, $current, $values );
	}

	/**
	 * Resolve the post id from ability input.
	 *
	 * @param mixed $input Ability input.
	 * @return int
	 */
	private static function input_post_id( $input ) {
		return ( is_array( $input ) && isset( $input['post_id'] ) ) ? (int) $input['post_id'] : 0;
	}

	/**
	 * Permission for SEO reads.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission_read( $input = array() ) {
		$post_id = self::input_post_id( $input );
		$can     = self::can_edit_post_or_missing( $post_id );
		return $can && null === Safety::guard( 'crayn-seo', false );
	}

	/**
	 * Permission for SEO writes.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission_write( $input = array() ) {
		$post_id = self::input_post_id( $input );
		$can     = self::can_edit_post_or_missing( $post_id );
		return $can && null === Safety::guard( 'crayn-seo', false );
	}
}
