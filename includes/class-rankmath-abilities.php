<?php
/**
 * Rank Math per-post parity: everything the Rank Math editor sidebar can set,
 * as safe, previewable, revertible abilities.
 *
 * Storage format notes (verified against seo-by-rank-math on a live site):
 * - Plain post meta, one key per field: rank_math_title, rank_math_description,
 *   rank_math_focus_keyword (comma separated), rank_math_pillar_content ('on'),
 *   rank_math_robots (array), rank_math_advanced_robots (array with keys
 *   max-snippet / max-video-preview / max-image-preview), rank_math_canonical_url,
 *   rank_math_breadcrumb_title, rank_math_primary_category, rank_math_facebook_*,
 *   rank_math_twitter_*  (list: includes/admin/metabox/class-screen.php get_object_values()).
 * - Sanitising mirrors includes/rest/class-sanitize.php (title/description/facebook/twitter
 *   text via wp_filter_nohtml_kses, canonical via esc_url_raw).
 * - Schema: one post meta row per schema, key "rank_math_schema_<@type>", value = array with
 *   "@type", the schema fields and a "metadata" sub-array
 *   { title, type: 'template', isPrimary, shortcode: 's-<uniqid>' }
 *   (includes/rest/class-shared.php update_schemas() lines ~190-215; importer reference
 *   includes/admin/importers/class-yoast.php add_schema_data() lines ~301-311).
 *   New schemas are stored with add_metadata() (several rows per key are allowed) and a
 *   "shortcut" meta rank_math_shortcode_schema_<shortcode> = meta_id of the schema row is added.
 *   Reads go through RankMath\Schema\DB::get_schemas() (modules/schema/class-db.php, static cache!).
 *   FAQPage layout: { @type: FAQPage, mainEntity: [ { @type: Question, name, acceptedAnswer:
 *   { @type: Answer, text } } ] } (modules/schema/blocks/faq/class-block-faq.php lines ~89-115).
 * - Redirections: RankMath\Redirections\Redirection::from()->save() / DB::update() /
 *   DB::change_status() (modules/redirections/class-redirection.php, class-db.php).
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Rank Math abilities (category craynai-seo) when Rank Math is active.
 */
class RankMathAbilities extends AbilitiesBase {

	/**
	 * Volatile Rank Math meta that is never snapshotted or reverted.
	 *
	 * @var string[]
	 */
	const EXCLUDED_META = array(
		'rank_math_seo_score',
		'rank_math_internal_links_processed',
		'rank_math_analytic_object_id',
		'rank_math_contentai_score',
	);

	/**
	 * Schema types accepted by rankmath-schema-set.
	 *
	 * @var string[]
	 */
	const SCHEMA_TYPES = array(
		'Article',
		'BlogPosting',
		'NewsArticle',
		'FAQPage',
		'HowTo',
		'Product',
		'Service',
		'LocalBusiness',
		'Event',
		'Recipe',
		'VideoObject',
		'Person',
		'Course',
		'JobPosting',
		'SoftwareApplication',
		'Book',
		'Organization',
		'Review',
		'Restaurant',
		'MusicGroup',
		'WebPage',
		'ItemList',
	);

	/**
	 * Robots directives accepted by Rank Math.
	 *
	 * @var string[]
	 */
	const ROBOTS = array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		$names = array(
			'craynai/rankmath-get',
			'craynai/rankmath-update',
			'craynai/rankmath-schema-set',
			'craynai/rankmath-schema-delete',
			'craynai/rankmath-analyze',
		);
		if ( self::redirections_available() ) {
			$names[] = 'craynai/rankmath-redirect-set';
		}
		return $names;
	}

	/**
	 * Is Rank Math the active SEO plugin?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return 'rank-math' === SeoAbilities::detected_plugin();
	}

	/**
	 * Is the Redirections module active?
	 *
	 * @return bool
	 */
	public static function redirections_available() {
		return self::is_active()
			&& class_exists( '\\RankMath\\Redirections\\DB' )
			&& class_exists( '\\RankMath\\Redirections\\Redirection' )
			&& class_exists( '\\RankMath\\Helper' )
			&& \RankMath\Helper::is_module_active( 'redirections' );
	}

	/**
	 * Register the abilities (only when Rank Math is active).
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! self::is_active() ) {
			return;
		}
		self::register_get();
		self::register_update();
		self::register_schema_set();
		self::register_schema_delete();
		self::register_analyze();
		if ( self::redirections_available() ) {
			self::register_redirect_set();
		}
	}

	// ------------------------------------------------------------------
	// Field model.
	// ------------------------------------------------------------------

	/**
	 * Flat map: input path => array( meta key, type ).
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function field_map() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array(
			'title'            => array( 'rank_math_title', 'nohtml' ),
			'description'      => array( 'rank_math_description', 'nohtml' ),
			'focus_keywords'   => array( 'rank_math_focus_keyword', 'keywords' ),
			'pillar_content'   => array( 'rank_math_pillar_content', 'pillar' ),
			'robots'           => array( 'rank_math_robots', 'robots' ),
			'advanced_robots'  => array( 'rank_math_advanced_robots', 'advrobots' ),
			'canonical_url'    => array( 'rank_math_canonical_url', 'url' ),
			'breadcrumb_title' => array( 'rank_math_breadcrumb_title', 'text' ),
			'primary_category' => array( 'rank_math_primary_category', 'term' ),
		);

		$fb = array(
			'title'                => array( 'nohtml' ),
			'description'          => array( 'nohtml' ),
			'image'                => array( 'url' ),
			'image_id'             => array( 'attachment' ),
			'enable_image_overlay' => array( 'onoff' ),
			'image_overlay'        => array( 'key' ),
			'author'               => array( 'text' ),
		);
		foreach ( $fb as $name => $def ) {
			$map[ 'facebook.' . $name ] = array( 'rank_math_facebook_' . $name, $def[0] );
		}

		$tw = array(
			'use_facebook'         => 'onoff',
			'card_type'            => 'key',
			'title'                => 'nohtml',
			'description'          => 'nohtml',
			'image'                => 'url',
			'image_id'             => 'attachment',
			'enable_image_overlay' => 'onoff',
			'image_overlay'        => 'key',
		);
		foreach ( $tw as $name => $type ) {
			$map[ 'twitter.' . $name ] = array( 'rank_math_twitter_' . $name, $type );
		}
		foreach ( array( 'url' => 'url', 'size' => 'text', 'stream' => 'url', 'stream_ctype' => 'text' ) as $name => $type ) {
			$map[ 'twitter.player.' . $name ] = array( 'rank_math_twitter_player_' . $name, $type );
		}
		$app = array(
			'description'     => 'text',
			'iphone_name'     => 'text',
			'iphone_id'       => 'text',
			'iphone_url'      => 'url',
			'ipad_name'       => 'text',
			'ipad_id'         => 'text',
			'ipad_url'        => 'url',
			'googleplay_name' => 'text',
			'googleplay_id'   => 'text',
			'googleplay_url'  => 'url',
			'country'         => 'text',
		);
		foreach ( $app as $name => $type ) {
			$map[ 'twitter.app.' . $name ] = array( 'rank_math_twitter_app_' . $name, $type );
		}

		return $map;
	}

	/**
	 * Nested tree of allowed input paths (leaf = true).
	 *
	 * @return array
	 */
	private static function allowed_tree() {
		$tree = array();
		foreach ( array_keys( self::field_map() ) as $path ) {
			$parts = explode( '.', $path );
			$node  = &$tree;
			foreach ( $parts as $i => $part ) {
				if ( $i === count( $parts ) - 1 ) {
					$node[ $part ] = true;
				} else {
					if ( ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
						$node[ $part ] = array();
					}
					$node = &$node[ $part ];
				}
			}
			unset( $node );
		}
		return $tree;
	}

	/**
	 * Check that $fields only contains known keys.
	 *
	 * @param array  $fields Input.
	 * @param array  $tree   Allowed tree.
	 * @param string $prefix Path prefix for messages.
	 * @return \WP_Error|null
	 */
	private static function check_keys( array $fields, array $tree, $prefix = '' ) {
		foreach ( $fields as $key => $value ) {
			if ( ! isset( $tree[ $key ] ) ) {
				return new \WP_Error( 'craynai_unknown_field', sprintf( 'Unknown Rank Math field "%s%s".', $prefix, $key ) );
			}
			if ( is_array( $tree[ $key ] ) ) {
				if ( ! is_array( $value ) ) {
					return new \WP_Error( 'craynai_bad_field', sprintf( 'Field "%s%s" must be an object.', $prefix, $key ) );
				}
				$err = self::check_keys( $value, $tree[ $key ], $prefix . $key . '.' );
				if ( $err ) {
					return $err;
				}
			}
		}
		return null;
	}

	/**
	 * Look a dotted path up in the input.
	 *
	 * @param array  $fields Input.
	 * @param string $path   Dotted path.
	 * @return array{0: bool, 1: mixed}
	 */
	private static function lookup( array $fields, $path ) {
		$node = $fields;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return array( false, null );
			}
			$node = $node[ $part ];
		}
		return array( true, $node );
	}

	/**
	 * Convert one input value into a meta operation.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Input value.
	 * @param array  $raw   Current raw meta map (for merges).
	 * @param string $key   Meta key.
	 * @param string $path  Input path (messages).
	 * @return array|\WP_Error array( 'set', value ) | array( 'delete' ) | WP_Error
	 */
	private static function convert( $type, $value, array $raw, $key, $path ) {
		$del = array( 'delete' );
		switch ( $type ) {
			case 'nohtml':
				// wp_filter_nohtml_kses() takes and returns slashed data; commit() slashes once more.
				$v = trim( wp_unslash( wp_filter_nohtml_kses( wp_slash( (string) $value ) ) ) );
				return '' === $v ? $del : array( 'set', $v );
			case 'text':
				$v = trim( sanitize_text_field( (string) $value ) );
				return '' === $v ? $del : array( 'set', $v );
			case 'key':
				$v = sanitize_key( (string) $value );
				return '' === $v ? $del : array( 'set', $v );
			case 'url':
				$v = trim( (string) $value );
				if ( '' === $v ) {
					return $del;
				}
				$clean = esc_url_raw( $v );
				if ( '' === $clean ) {
					return new \WP_Error( 'craynai_bad_url', sprintf( 'Field "%s" must be a valid URL.', $path ) );
				}
				return array( 'set', $clean );
			case 'term':
				$id = absint( $value );
				if ( 0 === $id ) {
					return $del;
				}
				$term = get_term( $id );
				if ( ! $term || is_wp_error( $term ) ) {
					return new \WP_Error( 'craynai_bad_term', sprintf( 'Field "%s": term %d does not exist.', $path, $id ) );
				}
				return array( 'set', (string) $id );
			case 'attachment':
				$id = absint( $value );
				if ( 0 === $id ) {
					return $del;
				}
				if ( 'attachment' !== get_post_type( $id ) ) {
					return new \WP_Error( 'craynai_bad_attachment', sprintf( 'Field "%s": %d is not a media attachment.', $path, $id ) );
				}
				return array( 'set', (string) $id );
			case 'onoff':
				if ( null === $value || '' === $value ) {
					return $del;
				}
				return array( 'set', filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 'on' : 'off' );
			case 'pillar':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? array( 'set', 'on' ) : $del;
			case 'keywords':
				$list = is_array( $value ) ? $value : explode( ',', (string) $value );
				$out  = array();
				foreach ( $list as $kw ) {
					$kw = trim( sanitize_text_field( (string) $kw ) );
					if ( '' !== $kw && ! in_array( $kw, $out, true ) ) {
						$out[] = $kw;
					}
				}
				return empty( $out ) ? $del : array( 'set', implode( ',', $out ) );
			case 'robots':
				$list = is_array( $value ) ? $value : explode( ',', (string) $value );
				$out  = array();
				foreach ( $list as $token ) {
					$token = strtolower( trim( (string) $token ) );
					if ( '' === $token ) {
						continue;
					}
					if ( ! in_array( $token, self::ROBOTS, true ) ) {
						return new \WP_Error( 'craynai_bad_robots', sprintf( 'Unknown robots directive "%s". Allowed: %s.', $token, implode( ', ', self::ROBOTS ) ) );
					}
					$out[] = $token;
				}
				if ( empty( $out ) ) {
					return $del;
				}
				if ( in_array( 'noindex', $out, true ) ) {
					$out = array_diff( $out, array( 'index' ) );
				} elseif ( ! in_array( 'index', $out, true ) ) {
					array_unshift( $out, 'index' );
				}
				return array( 'set', array_values( array_unique( $out ) ) );
			case 'advrobots':
				if ( ! is_array( $value ) ) {
					return new \WP_Error( 'craynai_bad_field', sprintf( 'Field "%s" must be an object.', $path ) );
				}
				if ( empty( $value ) ) {
					return $del;
				}
				$current = ( isset( $raw[ $key ][0] ) && is_array( $raw[ $key ][0] ) ) ? $raw[ $key ][0] : array();
				$aliases = array(
					'max_snippet'       => 'max-snippet',
					'max_video_preview' => 'max-video-preview',
					'max_image_preview' => 'max-image-preview',
				);
				foreach ( $value as $k => $v ) {
					$k = isset( $aliases[ $k ] ) ? $aliases[ $k ] : $k;
					if ( ! in_array( $k, array_values( $aliases ), true ) ) {
						return new \WP_Error( 'craynai_bad_field', sprintf( 'Unknown advanced_robots key "%s".', $k ) );
					}
					if ( 'max-image-preview' === $k ) {
						if ( ! in_array( (string) $v, array( 'none', 'standard', 'large' ), true ) ) {
							return new \WP_Error( 'craynai_bad_field', 'max-image-preview must be none, standard or large.' );
						}
						$current[ $k ] = (string) $v;
					} else {
						$current[ $k ] = (string) (int) $v;
					}
				}
				return array( 'set', $current );
		}
		return new \WP_Error( 'craynai_internal', 'Unhandled field type ' . $type );
	}

	/**
	 * Turn input fields into meta operations (pure: reads nothing but term/attachment lookups).
	 *
	 * @param array $fields Rank Math fields.
	 * @param array $raw    Current raw meta map.
	 * @return array{ops: array, changed: string[]}|\WP_Error
	 */
	public static function plan( array $fields, array $raw ) {
		$err = self::check_keys( $fields, self::allowed_tree() );
		if ( $err ) {
			return $err;
		}

		$ops     = array();
		$changed = array();
		foreach ( self::field_map() as $path => $def ) {
			list( $found, $value ) = self::lookup( $fields, $path );
			if ( ! $found ) {
				continue;
			}
			$key = $def[0];
			$res = self::convert( $def[1], $value, $raw, $key, $path );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$exists = isset( $raw[ $key ] );
			if ( 'delete' === $res[0] ) {
				if ( ! $exists ) {
					continue;
				}
				$ops[]     = array( 'delete', $key );
				$changed[] = $path;
			} else {
				if ( $exists && 1 === count( $raw[ $key ] ) && $raw[ $key ][0] === $res[1] ) {
					continue;
				}
				$ops[]     = array( 'set', $key, $res[1] );
				$changed[] = $path;
			}
		}
		return array( 'ops' => $ops, 'changed' => $changed );
	}

	/**
	 * Apply operations to a raw map without touching the database (dry run).
	 *
	 * @param array $raw Raw map.
	 * @param array $ops Operations.
	 * @return array
	 */
	public static function simulate( array $raw, array $ops ) {
		foreach ( $ops as $op ) {
			if ( 'delete' === $op[0] ) {
				unset( $raw[ $op[1] ] );
			} else {
				$raw[ $op[1] ] = array( $op[2] );
			}
		}
		return $raw;
	}

	/**
	 * Write operations to post meta (wp_slash because the meta API unslashes).
	 *
	 * @param int   $post_id Post id.
	 * @param array $ops     Operations from plan().
	 * @return void
	 */
	public static function commit( $post_id, array $ops ) {
		foreach ( $ops as $op ) {
			if ( 'delete' === $op[0] ) {
				delete_post_meta( $post_id, $op[1] );
			} else {
				update_post_meta( $post_id, $op[1], wp_slash( $op[2] ) );
			}
		}
	}

	// ------------------------------------------------------------------
	// Raw snapshots and revert.
	// ------------------------------------------------------------------

	/**
	 * All rank_math_* post meta as key => list of values (meta id order).
	 *
	 * @param int $post_id Post id.
	 * @return array<string, array>
	 */
	public static function raw_snapshot( $post_id ) {
		global $wpdb;

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s ORDER BY meta_id ASC",
				$post_id,
				$wpdb->esc_like( 'rank_math_' ) . '%'
			),
			ARRAY_A
		);
		$raw = array();
		foreach ( (array) $rows as $row ) {
			if ( in_array( $row['meta_key'], self::EXCLUDED_META, true ) ) {
				continue;
			}
			$raw[ $row['meta_key'] ][] = maybe_unserialize( $row['meta_value'] );
		}
		return $raw;
	}

	/**
	 * Keys whose rows differ between two raw maps.
	 *
	 * @param array $before Raw before.
	 * @param array $after  Raw after.
	 * @return string[]
	 */
	public static function diff_keys( array $before, array $after ) {
		$keys = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$b = isset( $before[ $key ] ) ? wp_json_encode( $before[ $key ] ) : null;
			$a = isset( $after[ $key ] ) ? wp_json_encode( $after[ $key ] ) : null;
			if ( $b !== $a ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Subset of a raw map.
	 *
	 * @param array    $raw  Raw map.
	 * @param string[] $keys Keys.
	 * @return array
	 */
	public static function subset( array $raw, array $keys ) {
		$out = array();
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$out[ $key ] = $raw[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Restore the given keys to the "before" raw state (rows that did not exist are deleted).
	 *
	 * @param int      $post_id Post id.
	 * @param array    $before  Raw before (may lack keys).
	 * @param string[] $keys    Keys to restore.
	 * @return void
	 */
	public static function restore_raw( $post_id, array $before, array $keys ) {
		foreach ( $keys as $key ) {
			if ( 0 !== strpos( $key, 'rank_math_' ) || in_array( $key, self::EXCLUDED_META, true ) ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			if ( isset( $before[ $key ] ) ) {
				foreach ( $before[ $key ] as $value ) {
					add_post_meta( $post_id, $key, wp_slash( $value ) );
				}
			}
		}
		self::fix_schema_shortcuts( $post_id );
	}

	/**
	 * Re-point rank_math_shortcode_schema_<id> shortcuts at the current schema meta ids
	 * (meta ids change when a schema row is re-created).
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private static function fix_schema_shortcuts( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
				$post_id,
				$wpdb->esc_like( 'rank_math_schema_' ) . '%'
			),
			ARRAY_A
		);
		$by_shortcode = array();
		foreach ( (array) $rows as $row ) {
			$schema = maybe_unserialize( $row['meta_value'] );
			if ( is_array( $schema ) && ! empty( $schema['metadata']['shortcode'] ) ) {
				$by_shortcode[ (string) $schema['metadata']['shortcode'] ] = (int) $row['meta_id'];
			}
		}
		foreach ( $by_shortcode as $shortcode => $meta_id ) {
			$shortcut = 'rank_math_shortcode_schema_' . $shortcode;
			if ( metadata_exists( 'post', $post_id, $shortcut ) ) {
				update_post_meta( $post_id, $shortcut, $meta_id );
			}
		}
	}

	/**
	 * Revert a logged rankmath-* change on its post.
	 *
	 * @param array $before Decoded before_data.
	 * @param array $after  Decoded after_data.
	 * @return string|\WP_Error
	 */
	public static function revert_change( array $before, array $after ) {
		$post_id = isset( $after['post_id'] ) ? (int) $after['post_id'] : ( isset( $before['post_id'] ) ? (int) $before['post_id'] : 0 );
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new \WP_Error( 'craynai_object_gone', 'The post of this Rank Math change no longer exists.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'You cannot edit this post.' );
		}
		if ( ! isset( $before['raw'] ) || ! is_array( $before['raw'] ) || empty( $before['keys'] ) ) {
			return new \WP_Error( 'craynai_no_snapshot', 'The change log has no Rank Math snapshot for this change.' );
		}
		self::restore_raw( $post_id, $before['raw'], (array) $before['keys'] );
		return sprintf( 'Rank Math meta of post %d restored (%s).', $post_id, implode( ', ', (array) $before['keys'] ) );
	}

	/**
	 * Build log payloads for a before/after raw pair.
	 *
	 * @param int   $post_id    Post id.
	 * @param array $raw_before Raw before.
	 * @param array $raw_after  Raw after.
	 * @return array{0: array, 1: array}
	 */
	private static function log_payloads( $post_id, array $raw_before, array $raw_after ) {
		$keys = self::diff_keys( $raw_before, $raw_after );
		return array(
			array( 'post_id' => $post_id, 'keys' => $keys, 'raw' => self::subset( $raw_before, $keys ) ),
			array( 'post_id' => $post_id, 'keys' => $keys, 'raw' => self::subset( $raw_after, $keys ) ),
		);
	}

	// ------------------------------------------------------------------
	// Normalised view.
	// ------------------------------------------------------------------

	/**
	 * First value of a raw key.
	 *
	 * @param array  $raw Raw map.
	 * @param string $key Meta key.
	 * @return mixed
	 */
	private static function val( array $raw, $key ) {
		return isset( $raw[ $key ][0] ) ? $raw[ $key ][0] : '';
	}

	/**
	 * Normalise a raw map into the ability field model.
	 *
	 * @param array $raw Raw map.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw ) {
		$kw = trim( (string) self::val( $raw, 'rank_math_focus_keyword' ) );
		$ro = self::val( $raw, 'rank_math_robots' );
		$ad = self::val( $raw, 'rank_math_advanced_robots' );

		$out = array(
			'title'            => (string) self::val( $raw, 'rank_math_title' ),
			'description'      => (string) self::val( $raw, 'rank_math_description' ),
			'focus_keywords'   => '' === $kw ? array() : array_values( array_filter( array_map( 'trim', explode( ',', $kw ) ), 'strlen' ) ),
			'pillar_content'   => 'on' === self::val( $raw, 'rank_math_pillar_content' ),
			'robots'           => is_array( $ro ) ? array_values( $ro ) : array(),
			'advanced_robots'  => is_array( $ad ) ? $ad : array(),
			'canonical_url'    => (string) self::val( $raw, 'rank_math_canonical_url' ),
			'breadcrumb_title' => (string) self::val( $raw, 'rank_math_breadcrumb_title' ),
			'primary_category' => (int) self::val( $raw, 'rank_math_primary_category' ),
		);

		$onoff = function ( $key ) use ( $raw ) {
			$v = self::val( $raw, $key );
			return ! ( '' === $v || 'off' === $v );
		};

		$fb = array();
		foreach ( array( 'title', 'description', 'image', 'author', 'image_overlay' ) as $name ) {
			$fb[ $name ] = (string) self::val( $raw, 'rank_math_facebook_' . $name );
		}
		$fb['image_id']             = (int) self::val( $raw, 'rank_math_facebook_image_id' );
		$fb['enable_image_overlay'] = $onoff( 'rank_math_facebook_enable_image_overlay' );
		$out['facebook']            = $fb;

		$tw = array();
		foreach ( array( 'card_type', 'title', 'description', 'image', 'image_overlay' ) as $name ) {
			$tw[ $name ] = (string) self::val( $raw, 'rank_math_twitter_' . $name );
		}
		$tw['use_facebook']         = 'off' !== self::val( $raw, 'rank_math_twitter_use_facebook' ); // Rank Math default: on.
		$tw['image_id']             = (int) self::val( $raw, 'rank_math_twitter_image_id' );
		$tw['enable_image_overlay'] = $onoff( 'rank_math_twitter_enable_image_overlay' );
		$player                     = array();
		foreach ( array( 'url', 'size', 'stream', 'stream_ctype' ) as $name ) {
			$player[ $name ] = (string) self::val( $raw, 'rank_math_twitter_player_' . $name );
		}
		$tw['player'] = $player;
		$app          = array();
		foreach ( array( 'description', 'iphone_name', 'iphone_id', 'iphone_url', 'ipad_name', 'ipad_id', 'ipad_url', 'googleplay_name', 'googleplay_id', 'googleplay_url', 'country' ) as $name ) {
			$app[ $name ] = (string) self::val( $raw, 'rank_math_twitter_app_' . $name );
		}
		$tw['app']      = $app;
		$out['twitter'] = $tw;

		$out['schemas'] = self::schemas_from_raw( $raw );
		return $out;
	}

	/**
	 * Decode schemas from a raw map.
	 *
	 * @param array $raw Raw map.
	 * @return array<int, array<string, mixed>>
	 */
	private static function schemas_from_raw( array $raw ) {
		$list = array();
		foreach ( $raw as $key => $values ) {
			if ( 0 !== strpos( $key, 'rank_math_schema_' ) ) {
				continue;
			}
			foreach ( $values as $schema ) {
				if ( ! is_array( $schema ) ) {
					continue;
				}
				$list[] = array(
					'meta_key' => $key,
					'type'     => isset( $schema['@type'] ) ? $schema['@type'] : substr( $key, strlen( 'rank_math_schema_' ) ),
					'metadata' => isset( $schema['metadata'] ) ? $schema['metadata'] : array(),
					'data'     => $schema,
				);
			}
		}
		return $list;
	}

	/**
	 * Schemas of a post including meta ids, read straight from the database.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function schemas_with_ids( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s ORDER BY meta_id ASC",
				$post_id,
				$wpdb->esc_like( 'rank_math_schema_' ) . '%'
			),
			ARRAY_A
		);
		$list = array();
		foreach ( (array) $rows as $row ) {
			$schema = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $schema ) ) {
				continue;
			}
			$list[] = array(
				'meta_id'  => (int) $row['meta_id'],
				'meta_key' => $row['meta_key'],
				'type'     => isset( $schema['@type'] ) ? $schema['@type'] : '',
				'metadata' => isset( $schema['metadata'] ) ? $schema['metadata'] : array(),
				'data'     => $schema,
			);
		}
		return $list;
	}

	/**
	 * Redirect source pattern of a post: its permalink path relative to the site root ('' when plain permalinks).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function post_path( $post_id ) {
		return self::normalize_source( (string) get_permalink( $post_id ) );
	}

	/**
	 * Turn a URL or path into a Rank Math source pattern (relative to the site root, no leading slash).
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	private static function normalize_source( $url ) {
		$url = trim( $url );
		if ( preg_match( '#^https?://#i', $url ) ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$qs   = (string) wp_parse_url( $url, PHP_URL_QUERY );
			$url  = $path . ( '' !== $qs ? '?' . $qs : '' );
			// Plain permalinks (?p=ID) have no redirectable path.
			if ( '' === trim( $path, '/' ) || trim( $path, '/' ) === trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) ) {
				return '';
			}
		}
		$path      = trim( $url, '/' );
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) + 1 );
		}
		return $path;
	}

	/**
	 * Redirection(s) of a post from the Redirections module (null when unavailable).
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	private static function redirection_of( $post_id ) {
		if ( ! self::redirections_available() ) {
			return null;
		}
		$cache = \RankMath\Redirections\Cache::get_by_object_id( $post_id, 'post' );
		if ( $cache && ! empty( $cache->redirection_id ) ) {
			$row = \RankMath\Redirections\DB::get_redirection_by_id( (int) $cache->redirection_id );
			if ( $row ) {
				$row['sources'] = maybe_unserialize( $row['sources'] );
				return $row;
			}
		}
		// Fallback: a redirect whose source equals the post path.
		$path = self::post_path( $post_id );
		if ( '' !== $path ) {
			$found = \RankMath\Redirections\DB::get_redirections( array( 'limit' => 20, 'paged' => 1, 'search' => $path, 'status' => 'all' ) );
			foreach ( ( isset( $found['redirections'] ) ? $found['redirections'] : array() ) as $row ) {
				$sources = maybe_unserialize( $row['sources'] );
				foreach ( (array) $sources as $source ) {
					if ( isset( $source['pattern'] ) && trim( $source['pattern'], '/' ) === $path ) {
						$row['sources'] = $sources;
						return $row;
					}
				}
			}
		}
		return null;
	}

	// ------------------------------------------------------------------
	// Shared permission helpers.
	// ------------------------------------------------------------------

	/**
	 * Permission: edit_post on the given post_id + category gate.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	public static function permission_post( $input = array() ) {
		$post_id = ( is_array( $input ) && isset( $input['post_id'] ) ) ? (int) $input['post_id'] : 0;
		$can     = self::can_edit_post_or_missing( $post_id );
		return $can && null === Safety::guard( 'craynai-seo', false );
	}

	/**
	 * Common preflight for post-bound abilities.
	 *
	 * @param mixed $input    Ability input.
	 * @param bool  $write    Is this a write?
	 * @param bool  $dry_run  Dry run flag.
	 * @return \WP_Post|\WP_Error
	 */
	private static function preflight( $input, $write, $dry_run ) {
		$guard = Safety::guard( 'craynai-seo', $write, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! self::is_active() ) {
			return new \WP_Error( 'craynai_no_rankmath', 'Rank Math is not active.' );
		}
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'craynai_post_not_found', sprintf( 'Post %d does not exist.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'You cannot edit this post.' );
		}
		return $post;
	}

	// ------------------------------------------------------------------
	// Input schema.
	// ------------------------------------------------------------------

	/**
	 * Property schemas for all editable Rank Math fields (shared with post-create / post-update).
	 *
	 * @return array<string, mixed>
	 */
	public static function fields_properties() {
		$str = static function ( $desc ) {
			return array( 'type' => 'string', 'description' => $desc );
		};
		$bool = static function ( $desc ) {
			return array( 'type' => 'boolean', 'description' => $desc );
		};
		$int = static function ( $desc ) {
			return array( 'type' => 'integer', 'description' => $desc );
		};

		return array(
			'title'            => $str( 'SEO title. Rank Math variables such as %title% %sep% %sitename% are allowed. Empty string deletes the value.' ),
			'description'      => $str( 'Meta description. Empty string deletes the value.' ),
			'focus_keywords'   => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Focus keywords; the first one is the primary keyword. Empty array deletes the value.',
			),
			'pillar_content'   => $bool( 'Mark as pillar content (false removes the flag).' ),
			'robots'           => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string', 'enum' => self::ROBOTS ),
				'description' => 'Robots directives: index or noindex, nofollow, noarchive, noimageindex, nosnippet. Empty array deletes the value (global defaults apply).',
			),
			'advanced_robots'  => array(
				'type'                 => 'object',
				'description'          => 'Advanced robots meta. Empty object deletes the value.',
				'properties'           => array(
					'max_snippet'       => $int( 'Max snippet length; -1 = no limit, 0 = none.' ),
					'max_video_preview' => $int( 'Max video preview seconds; -1 = no limit.' ),
					'max_image_preview' => array( 'type' => 'string', 'enum' => array( 'none', 'standard', 'large' ), 'description' => 'Max image preview size.' ),
				),
				'additionalProperties' => false,
			),
			'canonical_url'    => $str( 'Canonical URL. Empty string deletes the value.' ),
			'breadcrumb_title' => $str( 'Breadcrumb title.' ),
			'primary_category' => $int( 'Primary category term id (0 deletes the value).' ),
			'facebook'         => array(
				'type'                 => 'object',
				'description'          => 'Facebook / Open Graph tab.',
				'properties'           => array(
					'title'                => $str( 'OG title.' ),
					'description'          => $str( 'OG description.' ),
					'image'                => $str( 'OG image URL.' ),
					'image_id'             => $int( 'OG image attachment id.' ),
					'enable_image_overlay' => $bool( 'Add a thumbnail overlay icon.' ),
					'image_overlay'        => $str( 'Overlay icon key (for example play, gif).' ),
					'author'               => $str( 'Facebook author URL.' ),
				),
				'additionalProperties' => false,
			),
			'twitter'          => array(
				'type'                 => 'object',
				'description'          => 'Twitter / X tab.',
				'properties'           => array(
					'use_facebook'         => $bool( 'Use the Facebook data for Twitter (Rank Math default: true).' ),
					'card_type'            => $str( 'Card type key, for example summary_large_image or summary_card.' ),
					'title'                => $str( 'Twitter title.' ),
					'description'          => $str( 'Twitter description.' ),
					'image'                => $str( 'Twitter image URL.' ),
					'image_id'             => $int( 'Twitter image attachment id.' ),
					'enable_image_overlay' => $bool( 'Add a thumbnail overlay icon.' ),
					'image_overlay'        => $str( 'Overlay icon key.' ),
					'player'               => array(
						'type'                 => 'object',
						'properties'           => array(
							'url'          => $str( 'Player URL.' ),
							'size'         => $str( 'Player size, for example 640x360.' ),
							'stream'       => $str( 'Stream URL.' ),
							'stream_ctype' => $str( 'Stream content type.' ),
						),
						'additionalProperties' => false,
					),
					'app'                  => array(
						'type'                 => 'object',
						'properties'           => array(
							'description'     => $str( 'App description.' ),
							'iphone_name'     => $str( 'iPhone app name.' ),
							'iphone_id'       => $str( 'iPhone app id.' ),
							'iphone_url'      => $str( 'iPhone app URL.' ),
							'ipad_name'       => $str( 'iPad app name.' ),
							'ipad_id'         => $str( 'iPad app id.' ),
							'ipad_url'        => $str( 'iPad app URL.' ),
							'googleplay_name' => $str( 'Google Play app name.' ),
							'googleplay_id'   => $str( 'Google Play app id.' ),
							'googleplay_url'  => $str( 'Google Play app URL.' ),
							'country'         => $str( 'App store country code.' ),
						),
						'additionalProperties' => false,
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Schema of the optional "rankmath" input object of post-create / post-update.
	 *
	 * @return array<string, mixed>
	 */
	public static function nested_input_schema() {
		return array(
			'type'                 => 'object',
			'description'          => 'Optional Rank Math SEO fields written together with the post in one logged change (same fields as rankmath-update). Empty value deletes a field.',
			'properties'           => self::fields_properties(),
			'additionalProperties' => false,
		);
	}

	// ------------------------------------------------------------------
	// rankmath-get
	// ------------------------------------------------------------------

	/**
	 * Register rankmath-get.
	 *
	 * @return void
	 */
	private static function register_get() {
		wp_register_ability(
			'craynai/rankmath-get',
			array(
				'label'               => 'Get Rank Math data',
				'description'         => 'Read ALL Rank Math SEO data of a post: title, description, focus keywords, pillar flag, robots, advanced robots, canonical, breadcrumb title, primary category, Facebook and Twitter tabs, SEO score, schemas (decoded), the redirection of the post (Redirections module) and a raw map of every rank_math_* meta with exists flags.',
				'category'            => 'craynai-seo',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array( 'type' => 'integer', 'description' => 'Post ID.' ),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'seo'         => array( 'type' => 'object', 'description' => 'Normalised Rank Math fields (same shape rankmath-update accepts).' ),
						'seo_score'   => array( 'type' => 'integer' ),
						'schemas'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'redirection' => array( 'type' => array( 'object', 'null' ) ),
						'raw'         => array( 'type' => 'object', 'description' => 'Every rank_math_* meta key: { exists, values }.' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_get' ),
				'permission_callback' => array( self::class, 'permission_post' ),
				'meta'                => Safety::meta( Safety::annotations( true, false, true, 'Get Rank Math Data' ) ),
			)
		);
	}

	/**
	 * Execute rankmath-get.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function execute_get( $input ) {
		$post = self::preflight( $input, false, false );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$post_id = (int) $post->ID;
		$raw     = self::raw_snapshot( $post_id );
		$seo     = self::normalize( $raw );
		unset( $seo['schemas'] );

		$map = array();
		foreach ( self::field_map() as $def ) {
			$map[ $def[0] ] = array( 'exists' => false, 'values' => array() );
		}
		foreach ( $raw as $key => $values ) {
			$map[ $key ] = array( 'exists' => true, 'values' => $values );
		}

		return array(
			'post_id'     => $post_id,
			'seo'         => $seo,
			'seo_score'   => (int) get_post_meta( $post_id, 'rank_math_seo_score', true ),
			'schemas'     => self::schemas_with_ids( $post_id ),
			'redirection' => self::redirection_of( $post_id ),
			'raw'         => $map,
		);
	}

	// ------------------------------------------------------------------
	// rankmath-update
	// ------------------------------------------------------------------

	/**
	 * Register rankmath-update.
	 *
	 * @return void
	 */
	private static function register_update() {
		wp_register_ability(
			'craynai/rankmath-update',
			array(
				'label'               => 'Update Rank Math data',
				'description'         => 'Write any subset of the Rank Math editor fields of a post (title, description, focus_keywords, pillar_content, robots, advanced_robots, canonical_url, breadcrumb_title, primary_category, facebook{}, twitter{}). Only provided fields are written; an empty value deletes the meta. Supports dry_run ({before, after}); every real write is logged and revertible with change-revert.',
				'category'            => 'craynai-seo',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array( 'post_id' => array( 'type' => 'integer', 'description' => 'Post ID.' ) ),
						self::fields_properties(),
						array( 'dry_run' => Safety::dry_run_property() )
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::change_output_schema(
					array(
						'post_id' => array( 'type' => 'integer' ),
						'changed' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Field paths that change (or changed).' ),
					)
				),
				'execute_callback'    => array( self::class, 'execute_update' ),
				'permission_callback' => array( self::class, 'permission_post' ),
				'meta'                => Safety::meta( Safety::annotations( false, false, false, 'Update Rank Math Data' ) ),
			)
		);
	}

	/**
	 * Execute rankmath-update.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function execute_update( $input ) {
		$dry_run = Safety::dry_run( $input );
		$post    = self::preflight( $input, true, $dry_run );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$post_id = (int) $post->ID;

		$fields = $input;
		unset( $fields['post_id'], $fields['dry_run'] );

		$raw_before = self::raw_snapshot( $post_id );
		$plan       = self::plan( $fields, $raw_before );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$norm_before = self::normalize( $raw_before );
		$norm_after  = self::normalize( self::simulate( $raw_before, $plan['ops'] ) );

		if ( $dry_run || empty( $plan['ops'] ) ) {
			return array(
				'dry_run'   => $dry_run,
				'before'    => $norm_before,
				'after'     => $norm_after,
				'change_id' => 0,
				'post_id'   => $post_id,
				'changed'   => $plan['changed'],
			);
		}

		self::commit( $post_id, $plan['ops'] );
		$raw_after = self::raw_snapshot( $post_id );

		list( $b, $a ) = self::log_payloads( $post_id, $raw_before, $raw_after );
		$change_id     = ChangeLogger::log( 'craynai/rankmath-update', 'rankmath', $post_id, $b, $a, 'Rank Math fields updated: ' . implode( ', ', $plan['changed'] ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Rank Math fields of post %d were updated.', $post_id ) );
		}

		return array(
			'dry_run'   => false,
			'before'    => $norm_before,
			'after'     => self::normalize( $raw_after ),
			'change_id' => $change_id,
			'post_id'   => $post_id,
			'changed'   => $plan['changed'],
		);
	}

	// ------------------------------------------------------------------
	// Schema.
	// ------------------------------------------------------------------

	/**
	 * Register rankmath-schema-set.
	 *
	 * @return void
	 */
	private static function register_schema_set() {
		wp_register_ability(
			'craynai/rankmath-schema-set',
			array(
				'label'               => 'Set Rank Math schema',
				'description'         => 'Add a schema (JSON-LD) to a post in Rank Math\'s own storage format (post meta rank_math_schema_<Type> with the editor "metadata" block). Supported: ' . implode( ', ', self::SCHEMA_TYPES ) . '. "data" holds the schema.org properties (Rank Math variables like %seo_title% work). FAQPage example: {"mainEntity":[{"@type":"Question","name":"Q?","acceptedAnswer":{"@type":"Answer","text":"A."}}]}. With replace_existing=true all existing schemas of the same type are replaced. Supports dry_run; logged and revertible.',
				'category'            => 'craynai-seo',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'          => array( 'type' => 'integer', 'description' => 'Post ID.' ),
						'schema_type'      => array( 'type' => 'string', 'enum' => self::SCHEMA_TYPES, 'description' => 'schema.org @type.' ),
						'data'             => array( 'type' => 'object', 'description' => 'schema.org properties of the schema (without @type / metadata).' ),
						'replace_existing' => array( 'type' => 'boolean', 'description' => 'Replace existing schemas of the same type instead of adding another. Default false.', 'default' => false ),
						'is_primary'       => array( 'type' => 'boolean', 'description' => 'Mark as the primary schema of the page. Default true for Article / BlogPosting / NewsArticle, otherwise false.' ),
						'dry_run'          => Safety::dry_run_property(),
					),
					'required'             => array( 'post_id', 'schema_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::change_output_schema(
					array(
						'post_id'  => array( 'type' => 'integer' ),
						'meta_key' => array( 'type' => 'string' ),
						'meta_id'  => array( 'type' => 'integer', 'description' => 'Meta id of the new schema row (0 on dry run).' ),
					)
				),
				'execute_callback'    => array( self::class, 'execute_schema_set' ),
				'permission_callback' => array( self::class, 'permission_post' ),
				'meta'                => Safety::meta( Safety::annotations( false, false, false, 'Set Rank Math Schema' ) ),
			)
		);
	}

	/**
	 * Delete all rows of a schema meta key (and their shortcut metas).
	 *
	 * @param int      $post_id Post id.
	 * @param string   $key     Schema meta key.
	 * @param int|null $only    Only this meta id.
	 * @return int Deleted rows.
	 */
	private static function delete_schema_rows( $post_id, $key, $only = null ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ), ARRAY_A );
		$deleted = 0;
		foreach ( (array) $rows as $row ) {
			if ( null !== $only && (int) $row['meta_id'] !== (int) $only ) {
				continue;
			}
			$schema = maybe_unserialize( $row['meta_value'] );
			if ( is_array( $schema ) && ! empty( $schema['metadata']['shortcode'] ) ) {
				delete_post_meta( $post_id, 'rank_math_shortcode_schema_' . $schema['metadata']['shortcode'] );
			}
			if ( delete_metadata_by_mid( 'post', (int) $row['meta_id'] ) ) {
				$deleted++;
			}
		}
		return $deleted;
	}

	/**
	 * Sanitise a schema exactly like Rank Math's update_schemas() REST handler.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	private static function sanitize_schema( array $schema ) {
		if ( class_exists( '\\RankMath\\Rest\\Sanitize' ) ) {
			$schema = \RankMath\Rest\Sanitize::get()->sanitize( 'rank_math_schema', $schema );
		}
		if ( isset( $schema['@type'] ) ) {
			$schema['@type'] = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $schema['@type'] );
		}
		return wp_kses_post_deep( $schema );
	}

	/**
	 * Execute rankmath-schema-set.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function execute_schema_set( $input ) {
		$dry_run = Safety::dry_run( $input );
		$post    = self::preflight( $input, true, $dry_run );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$post_id = (int) $post->ID;

		$type = isset( $input['schema_type'] ) ? (string) $input['schema_type'] : '';
		if ( ! in_array( $type, self::SCHEMA_TYPES, true ) ) {
			return new \WP_Error( 'craynai_bad_schema_type', sprintf( 'Unsupported schema type "%s". Allowed: %s.', $type, implode( ', ', self::SCHEMA_TYPES ) ) );
		}
		$data = ( isset( $input['data'] ) && is_array( $input['data'] ) ) ? $input['data'] : array();
		unset( $data['@type'], $data['metadata'], $data['@context'] );

		$article   = in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle' ), true );
		$primary   = isset( $input['is_primary'] ) ? (bool) $input['is_primary'] : $article;
		$replace   = ! empty( $input['replace_existing'] );
		$meta_key  = 'rank_math_schema_' . $type;
		$title     = ( class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'sanitize_schema_title' ) )
			? \RankMath\Helper::sanitize_schema_title( $type, false )
			: $type;
		$shortcode = uniqid( 's-' );

		$schema             = self::sanitize_schema( array_merge( array( '@type' => $type ), $data ) );
		$schema['@type']    = $type;
		$schema['metadata'] = array(
			'title'     => $title,
			'type'      => 'template',
			'shortcode' => $shortcode,
		);
		if ( $primary ) {
			$schema['metadata']['isPrimary'] = 1;
		}

		$raw_before = self::raw_snapshot( $post_id );

		if ( $dry_run ) {
			$planned = $raw_before;
			if ( $replace ) {
				unset( $planned[ $meta_key ] );
			}
			$planned[ $meta_key ][] = $schema;
			return array(
				'dry_run'   => true,
				'before'    => array( 'schemas' => self::schemas_from_raw( $raw_before ) ),
				'after'     => array( 'schemas' => self::schemas_from_raw( $planned ) ),
				'change_id' => 0,
				'post_id'   => $post_id,
				'meta_key'  => $meta_key,
				'meta_id'   => 0,
			);
		}

		if ( $replace ) {
			self::delete_schema_rows( $post_id, $meta_key );
		}
		// Same calls as Rank Math's editor (rest/class-shared.php update_schemas): add + shortcut meta.
		$meta_id = add_metadata( 'post', $post_id, $meta_key, wp_slash( $schema ) );
		if ( ! $meta_id ) {
			return new \WP_Error( 'craynai_schema_write_failed', 'The schema could not be stored.' );
		}
		add_metadata( 'post', $post_id, 'rank_math_shortcode_schema_' . $shortcode, (int) $meta_id );

		$raw_after     = self::raw_snapshot( $post_id );
		list( $b, $a ) = self::log_payloads( $post_id, $raw_before, $raw_after );
		$change_id     = ChangeLogger::log( 'craynai/rankmath-schema-set', 'rankmath', $post_id, $b, $a, sprintf( '%s schema %s on post %d', $type, $replace ? 'replaced' : 'added', $post_id ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'A %s schema was stored on post %d.', $type, $post_id ) );
		}

		return array(
			'dry_run'   => false,
			'before'    => array( 'schemas' => self::schemas_from_raw( $raw_before ) ),
			'after'     => array( 'schemas' => self::schemas_from_raw( $raw_after ) ),
			'change_id' => $change_id,
			'post_id'   => $post_id,
			'meta_key'  => $meta_key,
			'meta_id'   => (int) $meta_id,
		);
	}

	/**
	 * Register rankmath-schema-delete.
	 *
	 * @return void
	 */
	private static function register_schema_delete() {
		wp_register_ability(
			'craynai/rankmath-schema-delete',
			array(
				'label'               => 'Delete Rank Math schema',
				'description'         => 'Remove a Rank Math schema from a post by its meta key (for example rank_math_schema_FAQPage; get keys and meta ids from rankmath-get). Pass meta_id to remove only one of several schemas stored under the same key. Revertible: the schema is restored from the change log.',
				'category'            => 'craynai-seo',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array( 'type' => 'integer', 'description' => 'Post ID.' ),
						'meta_key' => array( 'type' => 'string', 'description' => 'Schema meta key, rank_math_schema_<Type>.' ),
						'meta_id'  => array( 'type' => 'integer', 'description' => 'Optional meta id: remove only this row.' ),
						'dry_run'  => Safety::dry_run_property(),
					),
					'required'             => array( 'post_id', 'meta_key' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::change_output_schema(
					array(
						'post_id' => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'integer', 'description' => 'Number of schema rows removed.' ),
					)
				),
				'execute_callback'    => array( self::class, 'execute_schema_delete' ),
				'permission_callback' => array( self::class, 'permission_post' ),
				'meta'                => Safety::meta( Safety::annotations( false, true, false, 'Delete Rank Math Schema' ) ),
			)
		);
	}

	/**
	 * Execute rankmath-schema-delete.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function execute_schema_delete( $input ) {
		$dry_run = Safety::dry_run( $input );
		$post    = self::preflight( $input, true, $dry_run );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$post_id  = (int) $post->ID;
		$meta_key = isset( $input['meta_key'] ) ? (string) $input['meta_key'] : '';
		if ( 0 !== strpos( $meta_key, 'rank_math_schema_' ) || strlen( $meta_key ) <= strlen( 'rank_math_schema_' ) ) {
			return new \WP_Error( 'craynai_bad_meta_key', 'meta_key must look like rank_math_schema_<Type>.' );
		}
		$only = isset( $input['meta_id'] ) ? (int) $input['meta_id'] : null;

		$raw_before = self::raw_snapshot( $post_id );
		if ( empty( $raw_before[ $meta_key ] ) ) {
			return new \WP_Error( 'craynai_schema_not_found', sprintf( 'Post %d has no "%s" schema.', $post_id, $meta_key ) );
		}

		if ( $dry_run ) {
			global $wpdb;
			$planned = $raw_before;
			$count   = count( $raw_before[ $meta_key ] );
			unset( $planned[ $meta_key ] );
			if ( null !== $only ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$ids      = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC", $post_id, $meta_key ) ) );
				$position = array_search( $only, $ids, true );
				if ( false === $position ) {
					return new \WP_Error( 'craynai_schema_not_found', 'No matching schema row would be removed (check meta_id).' );
				}
				$left = $raw_before[ $meta_key ];
				array_splice( $left, (int) $position, 1 );
				if ( $left ) {
					$planned[ $meta_key ] = $left;
				}
				$count = 1;
			}
			return array(
				'dry_run'   => true,
				'before'    => array( 'schemas' => self::schemas_from_raw( $raw_before ) ),
				'after'     => array( 'schemas' => self::schemas_from_raw( $planned ) ),
				'change_id' => 0,
				'post_id'   => $post_id,
				'deleted'   => $count,
			);
		}

		$deleted = self::delete_schema_rows( $post_id, $meta_key, $only );
		if ( 0 === $deleted ) {
			return new \WP_Error( 'craynai_schema_not_found', 'No matching schema row was removed (check meta_id).' );
		}

		$raw_after     = self::raw_snapshot( $post_id );
		list( $b, $a ) = self::log_payloads( $post_id, $raw_before, $raw_after );
		$change_id     = ChangeLogger::log( 'craynai/rankmath-schema-delete', 'rankmath', $post_id, $b, $a, sprintf( '%d "%s" schema row(s) removed from post %d', $deleted, $meta_key, $post_id ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Schema rows were removed from post %d.', $post_id ) );
		}

		return array(
			'dry_run'   => false,
			'before'    => array( 'schemas' => self::schemas_from_raw( $raw_before ) ),
			'after'     => array( 'schemas' => self::schemas_from_raw( $raw_after ) ),
			'change_id' => $change_id,
			'post_id'   => $post_id,
			'deleted'   => $deleted,
		);
	}

	// ------------------------------------------------------------------
	// Redirection.
	// ------------------------------------------------------------------

	/**
	 * Register rankmath-redirect-set.
	 *
	 * @return void
	 */
	private static function register_redirect_set() {
		wp_register_ability(
			'craynai/rankmath-redirect-set',
			array(
				'label'               => 'Set Rank Math redirect',
				'description'         => 'Create or update a Rank Math redirection for the URL of a post (Redirections module). header_code 301/302/307 need url_to; 410/451 do not. Supports dry_run; logged and revertible (a created redirect is moved to the Rank Math trash, an updated one gets its old values back).',
				'category'            => 'craynai-seo',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array( 'type' => 'integer', 'description' => 'Post whose URL is redirected (the source).' ),
						'url_to'      => array( 'type' => 'string', 'description' => 'Destination URL or path (not needed for 410 / 451).' ),
						'source'      => array( 'type' => 'string', 'description' => 'Optional source path or URL. Defaults to the permalink path of the post (required with plain permalinks).' ),
						'header_code' => array( 'type' => 'integer', 'enum' => array( 301, 302, 307, 410, 451 ), 'default' => 301, 'description' => 'HTTP status of the redirect.' ),
						'status'      => array( 'type' => 'string', 'enum' => array( 'active', 'inactive', 'trashed' ), 'default' => 'active', 'description' => 'Redirect status ("trashed" switches an existing redirect off and moves it to the trash).' ),
						'dry_run'     => Safety::dry_run_property(),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::change_output_schema(
					array(
						'post_id'        => array( 'type' => 'integer' ),
						'redirection_id' => array( 'type' => 'integer' ),
					)
				),
				'execute_callback'    => array( self::class, 'execute_redirect_set' ),
				'permission_callback' => array( self::class, 'permission_post' ),
				'meta'                => Safety::meta( Safety::annotations( false, false, false, 'Set Rank Math Redirect' ) ),
			)
		);
	}

	/**
	 * Execute rankmath-redirect-set.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function execute_redirect_set( $input ) {
		$dry_run = Safety::dry_run( $input );
		$post    = self::preflight( $input, true, $dry_run );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! self::redirections_available() ) {
			return new \WP_Error( 'craynai_redirections_inactive', 'The Rank Math Redirections module is not active.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'Managing redirects requires the manage_options capability.' );
		}
		$post_id = (int) $post->ID;

		$code   = isset( $input['header_code'] ) ? (int) $input['header_code'] : 301;
		$status = isset( $input['status'] ) ? (string) $input['status'] : 'active';
		if ( ! in_array( $code, array( 301, 302, 307, 410, 451 ), true ) ) {
			return new \WP_Error( 'craynai_bad_header_code', 'header_code must be 301, 302, 307, 410 or 451.' );
		}
		$url_to = isset( $input['url_to'] ) ? trim( (string) $input['url_to'] ) : '';
		if ( in_array( $code, array( 301, 302, 307 ), true ) ) {
			if ( '' === $url_to ) {
				return new \WP_Error( 'craynai_url_to_required', 'url_to is required for 301/302/307 redirects.' );
			}
			if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url_to ) && ! preg_match( '#^https?://#i', $url_to ) ) {
				return new \WP_Error( 'craynai_bad_url', 'url_to must be an http(s) URL or a path.' );
			}
		} else {
			$url_to = '';
		}

		$permalink = get_permalink( $post_id );
		$path      = ( isset( $input['source'] ) && '' !== trim( (string) $input['source'] ) )
			? self::normalize_source( (string) $input['source'] )
			: self::post_path( $post_id );
		if ( '' === $path ) {
			return new \WP_Error( 'craynai_no_source', 'The post has no redirectable URL (plain permalink or front page).' );
		}

		$now  = current_time( 'mysql' );
		$data = array(
			'sources'     => array( array( 'pattern' => $path, 'comparison' => 'exact' ) ),
			'url_to'      => $url_to,
			'header_code' => (string) $code,
			'status'      => $status,
			'created'     => $now,
			'updated'     => $now,
		);
		$obj  = \RankMath\Redirections\Redirection::from( $data );
		if ( ! $obj->has_sources() ) {
			return new \WP_Error( 'craynai_no_source', 'Rank Math rejected the source pattern of this post.' );
		}
		$destination = (string) $obj->url_to;
		if ( '' !== $destination && ( trim( $destination, '/' ) === trim( $permalink, '/' ) || trim( $destination, '/' ) === trim( home_url( $path ), '/' ) ) ) {
			return new \WP_Error( 'craynai_redirect_loop', 'The destination equals the source URL (redirect loop).' );
		}

		$existing = \RankMath\Redirections\DB::match_redirections_source( maybe_serialize( $obj->sources ) );
		$before   = null;
		if ( ! empty( $existing ) && isset( $existing[0]['id'] ) ) {
			$before = \RankMath\Redirections\DB::get_redirection_by_id( (int) $existing[0]['id'] );
			if ( $before ) {
				$before['sources'] = maybe_unserialize( $before['sources'] );
			}
		}
		$planned = array(
			'id'          => $before ? (int) $before['id'] : 0,
			'sources'     => $obj->sources,
			'url_to'      => $destination,
			'header_code' => (string) $code,
			'status'      => $status,
		);

		if ( $dry_run ) {
			return array(
				'dry_run'        => true,
				'before'         => $before,
				'after'          => $planned,
				'change_id'      => 0,
				'post_id'        => $post_id,
				'redirection_id' => 0,
			);
		}

		if ( $obj->is_infinite_loop() ) {
			return new \WP_Error( 'craynai_redirect_loop', 'Rank Math detected a redirect loop; nothing was written.' );
		}
		$id = $obj->save();
		if ( ! $id ) {
			return new \WP_Error( 'craynai_redirect_failed', 'Rank Math could not save the redirection.' );
		}
		$after = \RankMath\Redirections\DB::get_redirection_by_id( (int) $id );
		if ( $after ) {
			$after['sources'] = maybe_unserialize( $after['sources'] );
		}

		$after_log            = is_array( $after ) ? $after : $planned;
		$after_log['post_id'] = $post_id;
		$change_id            = ChangeLogger::log( 'craynai/rankmath-redirect-set', 'redirection', (int) $id, $before, $after_log, sprintf( '%s redirect %s for post %d (%s)', $code, $before ? 'updated' : 'created', $post_id, $path ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Redirection %d was saved.', (int) $id ) );
		}

		return array(
			'dry_run'        => false,
			'before'         => $before,
			'after'          => $after_log,
			'change_id'      => $change_id,
			'post_id'        => $post_id,
			'redirection_id' => (int) $id,
		);
	}

	/**
	 * Revert a rankmath-redirect-set change.
	 *
	 * @param int        $redirection_id Redirection id.
	 * @param array|null $before         Row before (null when it was created by the change).
	 * @param array      $after          Row after (with post_id).
	 * @return string|\WP_Error
	 */
	public static function revert_redirect( $redirection_id, $before, array $after ) {
		if ( ! self::redirections_available() ) {
			return new \WP_Error( 'craynai_redirections_inactive', 'The Rank Math Redirections module is not active.' );
		}
		$post_id = isset( $after['post_id'] ) ? (int) $after['post_id'] : 0;
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'You cannot edit the post of this redirect.' );
		}
		$redirection_id = (int) $redirection_id;
		if ( $redirection_id <= 0 || ! \RankMath\Redirections\DB::get_redirection_by_id( $redirection_id ) ) {
			return new \WP_Error( 'craynai_object_gone', 'The redirection no longer exists.' );
		}

		if ( empty( $before ) ) {
			\RankMath\Redirections\Cache::purge( $redirection_id );
			\RankMath\Redirections\DB::change_status( $redirection_id, 'trashed' );
			return sprintf( 'Redirection %d moved to the Rank Math trash.', $redirection_id );
		}

		\RankMath\Redirections\DB::update(
			array(
				'id'          => $redirection_id,
				'sources'     => isset( $before['sources'] ) ? maybe_unserialize( $before['sources'] ) : array(),
				'url_to'      => isset( $before['url_to'] ) ? $before['url_to'] : '',
				'header_code' => isset( $before['header_code'] ) ? $before['header_code'] : '301',
				'status'      => isset( $before['status'] ) ? $before['status'] : 'active',
			)
		);
		return sprintf( 'Redirection %d restored to its previous values.', $redirection_id );
	}

	// ------------------------------------------------------------------
	// Analyze.
	// ------------------------------------------------------------------

	/**
	 * Register rankmath-analyze.
	 *
	 * @return void
	 */
	private static function register_analyze() {
		wp_register_ability(
			'craynai/rankmath-analyze',
			array(
				'label'               => 'Analyze post with Rank Math',
				'description'         => 'Read only. Wraps Rank Math\'s own rank-math/analyze-post-content ability (content, metadata and the on-page test list) and adds the stored Rank Math SEO score of the post.',
				'category'            => 'craynai-seo',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array( 'type' => 'integer', 'description' => 'Post ID.' ),
						'keyword' => array( 'type' => 'string', 'description' => 'Optional focus keyword overriding the saved one.' ),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'seo_score' => array( 'type' => 'integer' ),
						'analysis'  => array( 'type' => 'object', 'description' => 'Result of rank-math/analyze-post-content.' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_analyze' ),
				'permission_callback' => array( self::class, 'permission_post' ),
				'meta'                => Safety::meta( Safety::annotations( true, false, true, 'Analyze Post With Rank Math' ) ),
			)
		);
	}

	/**
	 * Execute rankmath-analyze.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function execute_analyze( $input ) {
		$post = self::preflight( $input, false, false );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'rank-math/analyze-post-content' ) : null;
		if ( ! $ability ) {
			return new \WP_Error( 'craynai_ability_missing', 'This Rank Math version does not provide rank-math/analyze-post-content.' );
		}
		$args = array( 'post_id' => (int) $post->ID );
		if ( isset( $input['keyword'] ) && '' !== (string) $input['keyword'] ) {
			$args['keyword'] = (string) $input['keyword'];
		}
		$result = $ability->execute( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'post_id'   => (int) $post->ID,
			'seo_score' => (int) get_post_meta( $post->ID, 'rank_math_seo_score', true ),
			'analysis'  => is_array( $result ) ? $result : array( 'result' => $result ),
		);
	}
}
