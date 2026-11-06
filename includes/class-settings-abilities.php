<?php
/**
 * Site settings abilities: read and change a fixed allow-list of WordPress options.
 *
 * Allow-list only: an option name that is not in the list is refused and never
 * written, whatever the input says. A deny-list (siteurl, home, admin_email,
 * keys, passwords, roles, active plugins, theme ...) always wins, also over the
 * "crayn_ai_builder_mcp_settings_allowlist" filter. Updates are atomic (all values are
 * validated first), logged with the old value of every option and reverted
 * through SettingsAbilities::revert().
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers "craynai/settings-get" and "craynai/settings-update".
 */
class SettingsAbilities extends AbilitiesBase {

	/**
	 * Options that need the rewrite rules flushed after a write and after a revert.
	 *
	 * @var string[]
	 */
	const REWRITE_OPTIONS = array( 'permalink_structure', 'category_base', 'tag_base' );

	/**
	 * Option names that are never changeable (exact, lower-case). The role option
	 * ("{prefix}user_roles") and the patterns in is_denied() come on top.
	 *
	 * @var string[]
	 */
	const DENIED_NAMES = array(
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'active_plugins',
		'template',
		'stylesheet',
	);

	/**
	 * Human readable form of the deny-list for refusal messages.
	 */
	const DENIED_LABEL = 'siteurl, home, admin_email, new_admin_email, users_can_register, default_role, active_plugins, template, stylesheet, <prefix>user_roles, auth_*, secret*, *_key, *_salt, *_token, *password*';

	/**
	 * Option names owned by WordPress core (populate_options() in wp-admin/includes/schema.php
	 * plus the options core writes elsewhere). The filter can never add or alter these.
	 *
	 * @var string[]
	 */
	const CORE_OPTION_NAMES = array(
		'siteurl', 'home', 'blogname', 'blogdescription', 'users_can_register', 'admin_email', 'start_of_week', 'use_balanceTags', 'use_smilies',
		'require_name_email', 'comments_notify', 'posts_per_rss', 'rss_use_excerpt', 'mailserver_url', 'mailserver_login', 'mailserver_pass',
		'mailserver_port', 'default_category', 'default_comment_status', 'default_ping_status', 'default_pingback_flag', 'posts_per_page',
		'date_format', 'time_format', 'links_updated_date_format', 'comment_moderation', 'moderation_notify', 'permalink_structure',
		'rewrite_rules', 'hack_file', 'blog_charset', 'moderation_keys', 'active_plugins', 'category_base', 'ping_sites', 'comment_max_links',
		'gmt_offset', 'default_email_category', 'recently_edited', 'template', 'stylesheet', 'comment_registration', 'html_type', 'use_trackback',
		'default_role', 'db_version', 'uploads_use_yearmonth_folders', 'upload_path', 'blog_public', 'default_link_category', 'show_on_front',
		'tag_base', 'show_avatars', 'avatar_rating', 'upload_url_path', 'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w',
		'medium_size_h', 'avatar_default', 'large_size_w', 'large_size_h', 'image_default_link_type', 'image_default_size', 'image_default_align',
		'close_comments_for_old_posts', 'close_comments_days_old', 'thread_comments', 'thread_comments_depth', 'page_comments', 'comments_per_page',
		'default_comments_page', 'comment_order', 'sticky_posts', 'widget_categories', 'widget_text', 'widget_rss', 'uninstall_plugins',
		'timezone_string', 'page_for_posts', 'page_on_front', 'default_post_format', 'link_manager_enabled', 'finished_splitting_shared_terms',
		'site_icon', 'medium_large_size_w', 'medium_large_size_h', 'wp_page_for_privacy_policy', 'show_comments_cookies_opt_in',
		'admin_email_lifespan', 'disallowed_keys', 'comment_previously_approved', 'auto_plugin_theme_update_emails', 'auto_update_core_dev',
		'auto_update_core_minor', 'auto_update_core_major', 'wp_force_deactivated_plugins', 'wp_attachment_pages_enabled', 'wp_notes_notify',
		'WPLANG', 'fresh_site', 'cron', 'current_theme', 'sidebars_widgets', 'nav_menu_options', 'recently_activated', 'theme_switched',
		'can_compress_scripts', 'initial_db_version', 'new_admin_email', 'blog_upload_space', 'recovery_keys', 'user_count',
	);

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		return array(
			'craynai/settings-get',
			'craynai/settings-update',
		);
	}

	/**
	 * Register both abilities.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_ability(
			'craynai/settings-get',
			array(
				'label'               => 'Get site settings',
				'description'         => 'Read site settings from a fixed allow-list (title, tagline, timezone, date/time format, language, reading, comments, permalinks, media sizes ...). Without "names" every allowed option is returned with its value and its type/limits; with "names" only those. Options outside the allow-list (and the deny-listed secrets, URLs and roles) are refused.',
				'category'            => 'craynai-settings',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'names' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Option names to read, e.g. ["blogname","posts_per_page"]. Omit for all allowed options.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total' => array( 'type' => 'integer' ),
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array( 'type' => 'string' ),
									'value'       => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'null' ), 'description' => 'Current value (null when the option does not exist).' ),
									'exists'      => array( 'type' => 'boolean' ),
									'type'        => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'min'         => array( 'type' => 'number' ),
									'max'         => array( 'type' => 'number' ),
									'enum'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
									'max_length'  => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute_settings_get' ),
				'permission_callback' => array( self::class, 'permission' ),
				'meta'                => Safety::meta( Safety::annotations( true, false, true, 'Get Site Settings' ) ),
			)
		);

		wp_register_ability(
			'craynai/settings-update',
			array(
				'label'               => 'Update site settings',
				'description'         => 'Change several allow-listed site settings in one atomic call: every value is validated first and one bad value refuses the whole call. The old value of every option is logged and reverted together with change-revert. Setting blog_public to 0 (hide from search engines) or changing permalink_structure returns a "warning"; permalink_structure/category_base/tag_base flush the rewrite rules. Options that are not allow-listed are refused, never written. Accepts dry_run; logged with change_id.',
				'category'            => 'craynai-settings',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'values'  => array(
							'type'        => 'object',
							'description' => 'Map option name => new value, e.g. {"blogdescription":"New tagline","posts_per_page":12,"blog_public":1}. Use settings-get for the names, types and limits.',
						),
						'dry_run' => Safety::dry_run_property(),
					),
					'required'   => array( 'values' ),
				),
				'output_schema'       => self::change_output_schema(
					array(
						'warning'   => array( 'type' => 'string', 'description' => 'Present when a change needs attention (hiding the site from search engines, new permalink structure ...).' ),
						'unchanged' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Options that already had the requested value.' ),
					)
				),
				'execute_callback'    => array( self::class, 'execute_settings_update' ),
				'permission_callback' => array( self::class, 'permission' ),
				'meta'                => Safety::meta( Safety::annotations( false, false, true, 'Update Site Settings' ) ),
			)
		);
	}

	/**
	 * Permission for both abilities.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission( $input = array() ) {
		return current_user_can( 'manage_options' ) && null === Safety::guard( 'craynai-settings', false );
	}

	// ---------------------------------------------------------------------
	// Allow-list and deny-list
	// ---------------------------------------------------------------------

	/**
	 * The built-in allow-list: option => spec.
	 * Spec keys: type (string|int|flag|enum|number), check (special validator),
	 * min/max (int), min_len/max_len (string), enum (values), desc.
	 *
	 * @return array<string,array>
	 */
	private static function core_allowlist() {
		$flag = 'flag';
		return array(
			'blogname'                     => array( 'type' => 'string', 'min_len' => 1, 'max_len' => 255, 'desc' => 'Site title.' ),
			'blogdescription'              => array( 'type' => 'string', 'max_len' => 500, 'desc' => 'Tagline (may be empty).' ),
			'timezone_string'              => array( 'type' => 'string', 'check' => 'timezone', 'desc' => 'PHP timezone name such as "Europe/Istanbul", or empty to use gmt_offset.' ),
			'gmt_offset'                   => array( 'type' => 'number', 'check' => 'gmt_offset', 'min' => -12, 'max' => 14, 'desc' => 'UTC offset in hours (e.g. 3 or 5.5); ignored while timezone_string is set.' ),
			'date_format'                  => array( 'type' => 'string', 'check' => 'format', 'min_len' => 1, 'max_len' => 60, 'desc' => 'PHP date format, e.g. "j F Y".' ),
			'time_format'                  => array( 'type' => 'string', 'check' => 'format', 'min_len' => 1, 'max_len' => 60, 'desc' => 'PHP time format, e.g. "H:i".' ),
			'start_of_week'                => array( 'type' => 'int', 'min' => 0, 'max' => 6, 'desc' => 'First day of the week: 0 Sunday ... 6 Saturday.' ),
			'WPLANG'                       => array( 'type' => 'string', 'check' => 'wplang', 'desc' => 'Site language: an INSTALLED locale such as "tr_TR", or empty / "en_US" for English (US). Language packs are not downloaded.' ),
			'posts_per_page'               => array( 'type' => 'int', 'min' => 1, 'max' => 1000, 'desc' => 'Blog pages show at most this many posts.' ),
			'posts_per_rss'                => array( 'type' => 'int', 'min' => 1, 'max' => 100, 'desc' => 'Syndication feeds show the most recent items.' ),
			'rss_use_excerpt'              => array( 'type' => $flag, 'desc' => 'Feeds show a summary (1) or the full text (0).' ),
			'show_on_front'                => array( 'type' => 'enum', 'enum' => array( 'posts', 'page' ), 'desc' => 'Front page shows the latest posts or a static page.' ),
			'page_on_front'                => array( 'type' => 'int', 'check' => 'page', 'min' => 0, 'desc' => 'ID of the static front page (a published page), 0 = none.' ),
			'page_for_posts'               => array( 'type' => 'int', 'check' => 'page', 'min' => 0, 'desc' => 'ID of the posts page (a published page), 0 = none.' ),
			'blog_public'                  => array( 'type' => $flag, 'desc' => '1 = visible to search engines, 0 = discourage search engines from indexing the site.' ),
			'default_comment_status'       => array( 'type' => 'enum', 'enum' => array( 'open', 'closed' ), 'desc' => 'Allow comments on new posts.' ),
			'default_ping_status'          => array( 'type' => 'enum', 'enum' => array( 'open', 'closed' ), 'desc' => 'Allow pingbacks/trackbacks on new posts.' ),
			'comment_moderation'           => array( 'type' => $flag, 'desc' => 'Every comment must be approved manually.' ),
			'comments_notify'              => array( 'type' => $flag, 'desc' => 'Email the admin when a comment is posted.' ),
			'moderation_notify'            => array( 'type' => $flag, 'desc' => 'Email the admin when a comment is held for moderation.' ),
			'require_name_email'           => array( 'type' => $flag, 'desc' => 'Comment authors must fill in name and email.' ),
			'comment_registration'         => array( 'type' => $flag, 'desc' => 'Users must be logged in to comment.' ),
			'close_comments_for_old_posts' => array( 'type' => $flag, 'desc' => 'Close comments on old posts automatically.' ),
			'close_comments_days_old'      => array( 'type' => 'int', 'min' => 0, 'max' => 36500, 'desc' => 'Days after which comments close (with close_comments_for_old_posts).' ),
			'thread_comments'              => array( 'type' => $flag, 'desc' => 'Enable threaded (nested) comments.' ),
			'thread_comments_depth'        => array( 'type' => 'int', 'min' => 2, 'max' => 10, 'desc' => 'Nesting depth of threaded comments.' ),
			'page_comments'                => array( 'type' => $flag, 'desc' => 'Break comments into pages.' ),
			'comments_per_page'            => array( 'type' => 'int', 'min' => 1, 'max' => 1000, 'desc' => 'Top-level comments per page.' ),
			'permalink_structure'          => array( 'type' => 'string', 'check' => 'permalink', 'max_len' => 200, 'desc' => 'Empty = plain (?p=123), otherwise a structure with tags, e.g. "/%postname%/". It must contain %postname% or %post_id%. Flushes the rewrite rules.' ),
			'category_base'                => array( 'type' => 'string', 'check' => 'base', 'max_len' => 100, 'desc' => 'Category URL base (empty = "category"). Flushes the rewrite rules.' ),
			'tag_base'                     => array( 'type' => 'string', 'check' => 'base', 'max_len' => 100, 'desc' => 'Tag URL base (empty = "tag"). Flushes the rewrite rules.' ),
			'thumbnail_size_w'             => array( 'type' => 'int', 'min' => 0, 'max' => 10000, 'desc' => 'Thumbnail width in pixels.' ),
			'thumbnail_size_h'             => array( 'type' => 'int', 'min' => 0, 'max' => 10000, 'desc' => 'Thumbnail height in pixels.' ),
			'medium_size_w'                => array( 'type' => 'int', 'min' => 0, 'max' => 10000, 'desc' => 'Medium image max width.' ),
			'medium_size_h'                => array( 'type' => 'int', 'min' => 0, 'max' => 10000, 'desc' => 'Medium image max height.' ),
			'large_size_w'                 => array( 'type' => 'int', 'min' => 0, 'max' => 10000, 'desc' => 'Large image max width.' ),
			'large_size_h'                 => array( 'type' => 'int', 'min' => 0, 'max' => 10000, 'desc' => 'Large image max height.' ),
			'uploads_use_yearmonth_folders' => array( 'type' => $flag, 'desc' => 'Organize uploads into month- and year-based folders.' ),
			'default_category'             => array( 'type' => 'int', 'check' => 'category', 'min' => 1, 'desc' => 'ID of the default post category (must exist).' ),
			'default_post_format'          => array( 'type' => 'string', 'check' => 'post_format', 'desc' => 'Default post format: "0" (standard) or a format slug such as "aside".' ),
		);
	}

	/**
	 * The effective allow-list: the built-in one after the
	 * "crayn_ai_builder_mcp_settings_allowlist" filter. The filter receives name => spec and may
	 * (a) remove entries, (b) add entries for options that WordPress core does not own, with a
	 * simple spec (type string|int|flag|enum|number + limits). It can never change a core
	 * entry's spec, and the deny-list always wins.
	 *
	 * @return array<string,array>
	 */
	public static function allowlist() {
		$core     = self::core_allowlist();
		$filtered = apply_filters( 'crayn_ai_builder_mcp_settings_allowlist', $core );
		if ( ! is_array( $filtered ) ) {
			$filtered = $core;
		}

		$out = array();
		foreach ( $filtered as $key => $value ) {
			if ( is_int( $key ) && is_string( $value ) ) {
				$name = $value;
				$spec = null; // List form: a bare name.
			} else {
				$name = (string) $key;
				$spec = is_array( $value ) ? $value : null;
			}
			if ( self::is_denied( $name ) ) {
				continue;
			}
			if ( isset( $core[ $name ] ) ) {
				$out[ $name ] = $core[ $name ]; // The built-in spec stands; the filter could only keep or drop it.
				continue;
			}
			if ( null === $spec || ! self::addable_name( $name ) || ! self::valid_added_spec( $spec ) ) {
				continue;
			}
			$spec['added'] = true;
			$out[ $name ]  = $spec;
		}
		return $out;
	}

	/**
	 * Names a filter may add: not core-owned, no internal/transient/derived options.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function addable_name( $name ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $name ) ) {
			return false;
		}
		// Option names are case-insensitive in most databases: BLOG_PUBLIC would write blog_public.
		if ( in_array( strtolower( $name ), array_map( 'strtolower', self::CORE_OPTION_NAMES ), true ) ) {
			return false;
		}
		return 1 !== preg_match( '/^(_|wp_|widget_|theme_mods_|transient|site_transient|nav_menu|db_|sidebars_|rewrite_|cron$|recently_|auto_update|active_|uninstall_)/i', $name );
	}

	/**
	 * Minimal validation of a spec added through the filter.
	 *
	 * @param array $spec Spec.
	 * @return bool
	 */
	private static function valid_added_spec( array $spec ) {
		$type = isset( $spec['type'] ) ? $spec['type'] : '';
		if ( ! in_array( $type, array( 'string', 'int', 'flag', 'enum', 'number' ), true ) ) {
			return false;
		}
		if ( 'enum' === $type && ( empty( $spec['enum'] ) || ! is_array( $spec['enum'] ) ) ) {
			return false;
		}
		return ! isset( $spec['check'] ); // Special validators are for built-in entries only.
	}

	/**
	 * Is an option name on the deny-list (always refused)?
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_denied( $name ) {
		$lower = strtolower( (string) $name );
		if ( in_array( $lower, self::DENIED_NAMES, true ) ) {
			return true;
		}
		global $wpdb;
		if ( isset( $wpdb->prefix ) && $lower === strtolower( $wpdb->prefix . 'user_roles' ) ) {
			return true;
		}
		return 1 === preg_match( '/(^auth_|^secret|_key$|_salt$|_token$|password|user_roles$)/i', $lower );
	}

	/**
	 * Refusal for a name that is not usable, or null.
	 *
	 * @param string $name  Option name.
	 * @param array  $allow Allow-list.
	 * @return \WP_Error|null
	 */
	private static function access_error( $name, array $allow ) {
		if ( self::is_denied( $name ) ) {
			return new \WP_Error(
				'craynai_option_denied',
				sprintf( 'Option "%s" can never be changed with this tool. Always denied: %s.', $name, self::DENIED_LABEL )
			);
		}
		if ( ! isset( $allow[ $name ] ) ) {
			return new \WP_Error(
				'craynai_option_not_allowed',
				sprintf( 'Option "%s" is not in the allow-list, so it was not touched. Allowed options: %s. Always denied: %s.', $name, implode( ', ', array_keys( $allow ) ), self::DENIED_LABEL )
			);
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Reading
	// ---------------------------------------------------------------------

	/**
	 * Current state of an option: { exists, value (string|null) }.
	 *
	 * @param string $name Option name.
	 * @return array{exists:bool,value:?string}
	 */
	private static function snapshot_option( $name ) {
		$missing = '__craynai_missing__' . wp_generate_password( 12, false );
		$value   = get_option( $name, $missing );
		if ( $value === $missing ) {
			return array( 'exists' => false, 'value' => null );
		}
		return array( 'exists' => true, 'value' => is_scalar( $value ) ? (string) $value : maybe_serialize( $value ) );
	}

	/**
	 * Typed value for output.
	 *
	 * @param array $spec Spec.
	 * @param array $snap Snapshot.
	 * @return mixed
	 */
	private static function typed( array $spec, array $snap ) {
		if ( ! $snap['exists'] ) {
			return null;
		}
		$type = isset( $spec['type'] ) ? $spec['type'] : 'string';
		if ( 'int' === $type || 'flag' === $type ) {
			return is_numeric( $snap['value'] ) ? (int) $snap['value'] : $snap['value'];
		}
		return $snap['value'];
	}

	/**
	 * Execute settings-get.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_settings_get( $input ) {
		$guard = Safety::guard( 'craynai-settings', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'Reading site settings requires the manage_options capability.' );
		}
		$allow = self::allowlist();
		$names = ( isset( $input['names'] ) && is_array( $input['names'] ) && array() !== $input['names'] ) ? array_map( 'strval', $input['names'] ) : array_keys( $allow );

		$items = array();
		foreach ( $names as $name ) {
			$error = self::access_error( $name, $allow );
			if ( null !== $error ) {
				return $error;
			}
			$spec = $allow[ $name ];
			$snap = self::snapshot_option( $name );
			$row  = array(
				'name'        => $name,
				'value'       => self::typed( $spec, $snap ),
				'exists'      => $snap['exists'],
				'type'        => isset( $spec['type'] ) ? ( 'flag' === $spec['type'] ? 'flag (0 or 1)' : $spec['type'] ) : 'string',
				'description' => isset( $spec['desc'] ) ? (string) $spec['desc'] : '',
			);
			foreach ( array( 'min', 'max' ) as $limit ) {
				if ( isset( $spec[ $limit ] ) ) {
					$row[ $limit ] = $spec[ $limit ];
				}
			}
			if ( isset( $spec['enum'] ) ) {
				$row['enum'] = array_values( array_map( 'strval', $spec['enum'] ) );
			}
			if ( isset( $spec['max_len'] ) ) {
				$row['max_length'] = (int) $spec['max_len'];
			}
			$items[] = $row;
		}
		return array(
			'total' => count( $items ),
			'items' => $items,
		);
	}

	// ---------------------------------------------------------------------
	// Validation
	// ---------------------------------------------------------------------

	/**
	 * Validate and normalize one value.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Raw input value.
	 * @param array  $spec  Spec.
	 * @return string|\WP_Error Normalized value as stored (string), or an error.
	 */
	private static function validate_value( $name, $value, array $spec ) {
		$bad  = static function ( $message ) use ( $name ) {
			return new \WP_Error( 'craynai_settings_invalid', sprintf( '%s: %s', $name, $message ) );
		};
		$type = isset( $spec['type'] ) ? $spec['type'] : 'string';

		if ( is_array( $value ) || is_object( $value ) || null === $value ) {
			return $bad( 'the value must be a single string, number or boolean.' );
		}

		switch ( $type ) {
			case 'flag':
				if ( true === $value || 1 === $value || '1' === $value ) {
					return '1';
				}
				if ( false === $value || 0 === $value || '0' === $value ) {
					return '0';
				}
				return $bad( 'must be 0 or 1 (true/false are accepted).' );

			case 'int':
				$is_whole = is_int( $value )
					|| ( is_float( $value ) && floor( $value ) === $value )
					|| ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,9}$/', $value ) );
				if ( is_bool( $value ) || ! $is_whole ) {
					return $bad( 'must be a whole number.' );
				}
				$int = (int) $value;
				if ( isset( $spec['min'] ) && $int < $spec['min'] ) {
					return $bad( sprintf( 'must be at least %d.', $spec['min'] ) );
				}
				if ( isset( $spec['max'] ) && $int > $spec['max'] ) {
					return $bad( sprintf( 'must be at most %d.', $spec['max'] ) );
				}
				$check = isset( $spec['check'] ) ? $spec['check'] : '';
				if ( 'page' === $check && $int > 0 ) {
					$page = get_post( $int );
					if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
						return $bad( sprintf( 'ID %d is not an existing published page.', $int ) );
					}
				}
				if ( 'category' === $check && ! term_exists( $int, 'category' ) ) {
					return $bad( sprintf( 'ID %d is not an existing category.', $int ) );
				}
				return (string) $int;

			case 'number':
				if ( is_bool( $value ) || ! is_numeric( $value ) ) {
					return $bad( 'must be a number.' );
				}
				$num = (float) $value;
				if ( isset( $spec['min'] ) && $num < $spec['min'] ) {
					return $bad( sprintf( 'must be at least %s.', $spec['min'] ) );
				}
				if ( isset( $spec['max'] ) && $num > $spec['max'] ) {
					return $bad( sprintf( 'must be at most %s.', $spec['max'] ) );
				}
				$text = rtrim( rtrim( sprintf( '%.2F', $num ), '0' ), '.' );
				return ( '-0' === $text || '' === $text ) ? '0' : $text;

			case 'enum':
				$text = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
				if ( ! in_array( $text, array_map( 'strval', $spec['enum'] ), true ) ) {
					return $bad( sprintf( 'must be one of: %s.', implode( ', ', array_map( 'strval', $spec['enum'] ) ) ) );
				}
				return $text;

			default: // string.
				if ( is_bool( $value ) ) {
					return $bad( 'must be text.' );
				}
				$text = (string) $value;
				if ( 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text ) ) {
					return $bad( 'contains control characters.' );
				}
				if ( 1 !== preg_match( '//u', $text ) ) {
					return $bad( 'is not valid UTF-8.' );
				}
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
				if ( isset( $spec['min_len'] ) && $len < $spec['min_len'] ) {
					return $bad( sprintf( 'must have at least %d character(s).', $spec['min_len'] ) );
				}
				if ( isset( $spec['max_len'] ) && $len > $spec['max_len'] ) {
					return $bad( sprintf( 'must have at most %d characters.', $spec['max_len'] ) );
				}
				return self::validate_string_check( $name, $text, $spec );
		}
	}

	/**
	 * Special validators for string options.
	 *
	 * @param string $name Option name.
	 * @param string $text Text.
	 * @param array  $spec Spec.
	 * @return string|\WP_Error
	 */
	private static function validate_string_check( $name, $text, array $spec ) {
		$bad   = static function ( $message ) use ( $name ) {
			return new \WP_Error( 'craynai_settings_invalid', sprintf( '%s: %s', $name, $message ) );
		};
		$check = isset( $spec['check'] ) ? $spec['check'] : '';

		switch ( $check ) {
			case 'timezone':
				if ( '' !== $text && ! in_array( $text, timezone_identifiers_list( \DateTimeZone::ALL_WITH_BC ), true ) ) {
					return $bad( 'is not a valid PHP timezone name (e.g. "Europe/Istanbul"); use gmt_offset for fixed offsets.' );
				}
				return $text;

			case 'gmt_offset':
				return $text;

			case 'format':
				if ( false !== strpos( $text, '<' ) || false !== strpos( $text, '>' ) ) {
					return $bad( 'must not contain HTML.' );
				}
				return $text;

			case 'wplang':
				if ( 'en_US' === $text ) {
					$text = ''; // Empty means English (US).
				}
				if ( '' !== $text && ! in_array( $text, get_available_languages(), true ) ) {
					return $bad( sprintf( '"%s" is not an installed language (installed: %s). Empty means English (US). Language packs are not downloaded by this tool.', $text, implode( ', ', get_available_languages() ) ) );
				}
				return $text;

			case 'permalink':
				if ( '' === $text ) {
					return '';
				}
				if ( 1 !== preg_match( '#^[A-Za-z0-9\-._~/%]+$#', $text ) ) {
					return $bad( 'may only contain letters, digits, "-._~/" and structure tags such as %postname%.' );
				}
				$text = preg_replace( '#/+#', '/', '/' . str_replace( '#', '', $text ) );
				$tags = array( 'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'post_id', 'postname', 'category', 'author' );
				if ( 0 === preg_match_all( '/%([^\/%]+)%/', $text, $found ) ) {
					return $bad( 'needs at least one structure tag (for example "/%postname%/").' );
				}
				foreach ( $found[1] as $tag ) {
					if ( ! in_array( $tag, $tags, true ) ) {
						return $bad( sprintf( 'unknown tag %%%s%%. Allowed: %s.', $tag, '%' . implode( '%, %', $tags ) . '%' ) );
					}
				}
				if ( false === strpos( $text, '%postname%' ) && false === strpos( $text, '%post_id%' ) ) {
					return $bad( 'must contain %postname% or %post_id%, otherwise posts cannot be told apart from pages.' );
				}
				return $text;

			case 'base':
				if ( '' === $text ) {
					return '';
				}
				if ( 1 !== preg_match( '#^[\p{L}\p{N}\-_./]+$#u', $text ) ) {
					return $bad( 'may only contain letters, digits and "-_./".' );
				}
				return preg_replace( '#/+#', '/', '/' . $text );

			case 'post_format':
				$formats = array_merge( array( '0', '' ), function_exists( 'get_post_format_slugs' ) ? array_values( get_post_format_slugs() ) : array() );
				if ( ! in_array( $text, $formats, true ) ) {
					return $bad( sprintf( 'must be one of: %s.', implode( ', ', $formats ) ) );
				}
				return '' === $text ? '0' : $text;
		}
		return $text;
	}

	/**
	 * The value WordPress will really store for a normalized input
	 * (sanitize_option(), wp-includes/formatting.php: e.g. blogname is HTML-escaped).
	 *
	 * @param string $name  Option name.
	 * @param string $value Normalized value.
	 * @return string
	 */
	private static function stored_value( $name, $value ) {
		$out = sanitize_option( $name, $value );
		return is_scalar( $out ) ? (string) $out : $value;
	}

	/**
	 * What get_option() returns for a stored value. category_base and tag_base are
	 * stored with a leading slash by WordPress' own settings screen but read back
	 * without slashes (get_option() trims them, wp-includes/option.php:239-241, and the
	 * option_category_base filter _wp_filter_taxonomy_base does the same).
	 *
	 * @param string $name  Option name.
	 * @param string $value Stored value.
	 * @return string
	 */
	private static function read_form( $name, $value ) {
		return in_array( $name, array( 'category_base', 'tag_base' ), true ) ? trim( $value, '/' ) : $value;
	}

	// ---------------------------------------------------------------------
	// Writing
	// ---------------------------------------------------------------------

	/**
	 * Re-read the rewrite options and flush the rewrite rules (hard flush: .htaccess too).
	 * WP_Rewrite::init() re-reads permalink_structure; flush_rules() needs the
	 * wp-admin misc/file helpers to write the server rules (wp-includes/class-wp-rewrite.php:1873-1908).
	 *
	 * @return void
	 */
	private static function flush_rewrite() {
		global $wp_rewrite;
		if ( ! is_object( $wp_rewrite ) ) {
			return;
		}
		self::load_admin_includes( array( 'file.php', 'misc.php' ) );
		$wp_rewrite->init();
		flush_rewrite_rules( true );
	}

	/**
	 * Execute settings-update.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_settings_update( $input ) {
		$dry   = Safety::dry_run( $input );
		$guard = Safety::guard( 'craynai-settings', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'Changing site settings requires the manage_options capability.' );
		}
		if ( ! isset( $input['values'] ) || ! is_array( $input['values'] ) || array() === $input['values'] ) {
			return new \WP_Error( 'craynai_values_required', 'Give "values": an object of option name => new value.' );
		}
		$values = $input['values'];
		if ( array_keys( $values ) === range( 0, count( $values ) - 1 ) ) {
			return new \WP_Error( 'craynai_values_required', '"values" must be an object of option name => new value, not a list.' );
		}

		$allow    = self::allowlist();
		$problems = array();
		$code     = 'craynai_settings_invalid';
		$plan     = array();

		foreach ( $values as $name => $value ) {
			$name  = (string) $name;
			$error = self::access_error( $name, $allow );
			if ( null !== $error ) {
				$problems[] = $error->get_error_message();
				if ( 'craynai_option_denied' === $error->get_error_code() || 'craynai_settings_invalid' === $code ) {
					$code = $error->get_error_code();
				}
				continue;
			}
			$normalized = self::validate_value( $name, $value, $allow[ $name ] );
			if ( is_wp_error( $normalized ) ) {
				$problems[] = $normalized->get_error_message();
				continue;
			}
			$write         = self::stored_value( $name, $normalized );
			$plan[ $name ] = array(
				'old'      => self::snapshot_option( $name ),
				'new'      => $normalized,
				'write'    => $write,
				'expected' => self::read_form( $name, $write ),
			);
		}

		// Cross-option rules on the resulting state.
		if ( array() === $problems ) {
			$problems = array_merge( $problems, self::cross_errors( $plan ) );
		}
		if ( array() !== $problems ) {
			return new \WP_Error(
				$code,
				'Nothing was changed. ' . implode( ' ', $problems ),
				array( 'problems' => $problems )
			);
		}

		$before    = array();
		$after     = array();
		$unchanged = array();
		foreach ( $plan as $name => $item ) {
			if ( $item['old']['exists'] && $item['old']['value'] === $item['expected'] ) {
				$unchanged[] = $name;
				unset( $plan[ $name ] );
				continue;
			}
			$before[ $name ] = $item['old'];
			$after[ $name ]  = array( 'exists' => true, 'value' => $item['expected'] );
		}

		$warning = self::warnings( $plan );
		$result  = array(
			'dry_run'   => (bool) $dry,
			'before'    => self::values_view( $before ),
			'after'     => self::values_view( $after ),
			'change_id' => 0,
			'unchanged' => $unchanged,
		);
		if ( '' !== $warning ) {
			$result['warning'] = $warning;
		}
		if ( array() === $plan || $dry ) {
			return $result;
		}

		// Apply atomically: write in order, verify each; on any failure restore what was written.
		$written = array();
		foreach ( $plan as $name => $item ) {
			update_option( $name, $item['write'] ); // False when unchanged or on failure: verified by reading back.
			$written[] = $name;
			$now       = self::snapshot_option( $name );
			if ( ! $now['exists'] || $now['value'] !== $item['expected'] ) {
				self::restore_options( $written, $before );
				if ( array_intersect( $written, self::REWRITE_OPTIONS ) ) {
					self::flush_rewrite();
				}
				return new \WP_Error(
					'craynai_settings_write_failed',
					sprintf( 'WordPress did not store the requested value of "%s" (it was rejected or altered by a filter). All options of this call were restored to their old values.', $name )
				);
			}
		}

		if ( array_intersect( array_keys( $plan ), self::REWRITE_OPTIONS ) ) {
			self::flush_rewrite();
		}

		$after_real = array();
		foreach ( $plan as $name => $item ) {
			$after_real[ $name ] = self::snapshot_option( $name );
		}
		$change_id = ChangeLogger::log(
			'craynai/settings-update',
			'settings',
			0,
			$before,
			$after_real,
			'Settings changed: ' . implode( ', ', array_keys( $plan ) )
		);
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( 'Options changed: ' . implode( ', ', array_keys( $plan ) ) . '.' );
		}
		$result['change_id'] = (int) $change_id;
		$result['after']     = self::values_view( $after_real );
		return $result;
	}

	/**
	 * Rules that involve more than one option, on the state after the call.
	 *
	 * @param array $plan Plan name => { old, new, expected }.
	 * @return string[] Problems.
	 */
	private static function cross_errors( array $plan ) {
		$problems = array();
		$eff      = function ( $name ) use ( $plan ) {
			return isset( $plan[ $name ] ) ? $plan[ $name ]['expected'] : self::read_value( $name );
		};
		if ( isset( $plan['page_on_front'] ) || isset( $plan['page_for_posts'] ) ) {
			$front = (int) $eff( 'page_on_front' );
			$posts = (int) $eff( 'page_for_posts' );
			if ( $front > 0 && $front === $posts ) {
				$problems[] = 'page_on_front and page_for_posts cannot be the same page.';
			}
		}
		return $problems;
	}

	/**
	 * Current value of an option as a string ('' when missing).
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private static function read_value( $name ) {
		$snap = self::snapshot_option( $name );
		return $snap['exists'] ? (string) $snap['value'] : '';
	}

	/**
	 * Warnings for a plan (hiding the site, permalink change ...).
	 *
	 * @param array $plan Plan.
	 * @return string
	 */
	private static function warnings( array $plan ) {
		$out = array();
		if ( isset( $plan['blog_public'] ) && '0' === $plan['blog_public']['expected'] ) {
			$out[] = 'blog_public = 0 asks search engines NOT to index this site; the site will drop out of search results over time.';
		}
		if ( isset( $plan['permalink_structure'] ) ) {
			$out[] = 'The permalink structure changes the URL of every post and page; old URLs stop working unless redirected. The rewrite rules are flushed.';
		}
		if ( isset( $plan['category_base'] ) || isset( $plan['tag_base'] ) ) {
			$out[] = 'Category/tag archive URLs change. The rewrite rules are flushed.';
		}
		if ( isset( $plan['gmt_offset'] ) && '' !== self::read_value( 'timezone_string' ) && ! isset( $plan['timezone_string'] ) ) {
			$out[] = 'gmt_offset is ignored while timezone_string is set.';
		}
		if ( isset( $plan['show_on_front'] ) && 'page' === $plan['show_on_front']['expected'] && 0 === (int) ( isset( $plan['page_on_front'] ) ? $plan['page_on_front']['expected'] : self::read_value( 'page_on_front' ) ) ) {
			$out[] = 'show_on_front is "page" but no page_on_front is set; WordPress falls back to the latest posts.';
		}
		return implode( ' ', $out );
	}

	/**
	 * Compact name => value view of snapshots for results.
	 *
	 * @param array $snaps Snapshots.
	 * @return array
	 */
	private static function values_view( array $snaps ) {
		$out = array();
		foreach ( $snaps as $name => $snap ) {
			$out[ $name ] = $snap['exists'] ? $snap['value'] : null;
		}
		return (object) $out;
	}

	/**
	 * Put options back to the snapshots (delete options that did not exist).
	 *
	 * @param string[] $names Option names.
	 * @param array    $snaps Snapshots name => { exists, value }.
	 * @return void
	 */
	private static function restore_options( array $names, array $snaps ) {
		foreach ( $names as $name ) {
			if ( ! isset( $snaps[ $name ] ) ) {
				continue;
			}
			if ( $snaps[ $name ]['exists'] ) {
				// Snapshots keep arrays serialized; update_option() must get the array back.
				// maybe_unserialize() would also build objects from a stored string.
				$raw = (string) $snaps[ $name ]['value'];
				update_option( $name, is_serialized( $raw ) ? unserialize( $raw, array( 'allowed_classes' => false ) ) : $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Objects are refused; the value comes from this plugin's own snapshot.
			} else {
				delete_option( $name );
			}
		}
	}

	// ---------------------------------------------------------------------
	// Revert (dispatched from ChangeAbilities)
	// ---------------------------------------------------------------------

	/**
	 * Current snapshot of the options of a change (change-revert logs it).
	 *
	 * @param array $names Option names.
	 * @return array
	 */
	public static function current_snapshot( array $names ) {
		$out = array();
		foreach ( $names as $name ) {
			$out[ (string) $name ] = self::snapshot_option( (string) $name );
		}
		return $out;
	}

	/**
	 * Restore a logged settings change. Refuses with craynai_revert_conflict
	 * when any option no longer has the value the change left.
	 *
	 * @param array $row Change log row (raw columns).
	 * @return string|\WP_Error
	 */
	public static function revert( array $row ) {
		$before = ChangeLogger::decode( isset( $row['before_data'] ) ? $row['before_data'] : '' );
		$after  = ChangeLogger::decode( isset( $row['after_data'] ) ? $row['after_data'] : '' );
		if ( array() === $before || array_keys( $before ) !== array_keys( $after ) ) {
			return new \WP_Error( 'craynai_revert_no_snapshot', 'The change log row has no complete settings snapshot to restore.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'Reverting settings requires the manage_options capability.' );
		}

		$allow = self::allowlist();
		foreach ( array_keys( $before ) as $name ) {
			$name  = (string) $name;
			$error = self::access_error( $name, $allow );
			if ( null !== $error ) {
				return new \WP_Error( 'craynai_option_not_allowed', 'This change cannot be reverted: ' . $error->get_error_message() );
			}
			if ( ! isset( $before[ $name ]['exists'], $after[ $name ]['exists'] ) ) {
				return new \WP_Error( 'craynai_revert_no_snapshot', 'The change log row has no complete settings snapshot to restore.' );
			}
			$now = self::snapshot_option( $name );
			if ( $now['exists'] !== (bool) $after[ $name ]['exists'] || $now['value'] !== $after[ $name ]['value'] ) {
				return new \WP_Error(
					'craynai_revert_conflict',
					sprintf( 'Option "%s" changed after this edit. Revert the newer changes first (see changes-list), newest first.', $name )
				);
			}
		}

		$names = array_map( 'strval', array_keys( $before ) );
		self::restore_options( $names, $before );
		foreach ( $names as $name ) {
			$now = self::snapshot_option( $name );
			if ( $now['exists'] !== (bool) $before[ $name ]['exists'] || $now['value'] !== $before[ $name ]['value'] ) {
				return new \WP_Error( 'craynai_revert_failed', sprintf( 'Option "%s" did not read back as its old value after the restore (a filter may rewrite it). The change stays marked as not reverted.', $name ) );
			}
		}
		if ( array_intersect( $names, self::REWRITE_OPTIONS ) ) {
			self::flush_rewrite();
		}
		return sprintf( 'Restored %d option(s): %s.', count( $names ), implode( ', ', $names ) );
	}
}
