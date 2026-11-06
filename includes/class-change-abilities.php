<?php
/**
 * Change log abilities: list changes and safely revert them.
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Register "craynai/changes-list" and "craynai/change-revert".
 *
 * change-revert restores the before_data snapshot depending on the ability:
 * post fields (incl. term assignments and the featured image), SEO meta,
 * plugin activation state, and plugin files (from the backup zip).
 * Reverting an already-reverted change fails cleanly.
 */
class ChangeAbilities extends AbilitiesBase {

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		return array(
			'craynai/changes-list',
			'craynai/change-revert',
		);
	}

	/**
	 * Register both ability names.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_changes_list();
		self::register_change_revert();
	}

	/**
	 * craynai/changes-list
	 *
	 * @return void
	 */
	private static function register_changes_list() {
		wp_register_ability(
			'craynai/changes-list',
			array(
				'label'              => 'List changes',
				'description'        => 'List logged changes (most recent first) with optional object_type/object_id filters. Read only.',
				'category'           => 'craynai-site',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'object_type' => array(
							'type'        => 'string',
							'description' => 'Filter by object type: post|seo|term|attachment|plugin|settings|menu. Elementor and WPBakery changes are listed as post.',
						),
						'object_id'   => array(
							'type'        => 'integer',
							'description' => 'Filter by object id.',
						),
						'limit'       => array(
							'type'        => 'integer',
							'description' => 'Max rows to return (1-100). Default 20.',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'dry_run'     => Safety::dry_run_property(),
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
									'id'          => array( 'type' => 'integer' ),
									'created_at'  => array( 'type' => 'string' ),
									'user_id'     => array( 'type' => 'integer' ),
									'ability'     => array( 'type' => 'string' ),
									'object_type' => array( 'type' => 'string' ),
									'object_id'   => array( 'type' => 'integer' ),
									'reverted'    => array( 'type' => 'boolean' ),
									'note'        => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_changes_list' ),
				'permission_callback' => array( self::class, 'permission' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, false, 'List Changes' ) ),
			)
		);
	}

	/**
	 * Execute changes-list.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_changes_list( $input ) {
		$guard = Safety::guard( 'craynai-site', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$rows  = ChangeLogger::list_changes(
			array(
				'object_type' => isset( $input['object_type'] ) ? (string) $input['object_type'] : '',
				'object_id'   => isset( $input['object_id'] ) ? (int) $input['object_id'] : 0,
				'limit'       => isset( $input['limit'] ) ? (int) $input['limit'] : 20,
			)
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'          => (int) $row['id'],
				'created_at'  => (string) $row['created_at'],
				'user_id'     => (int) $row['user_id'],
				'ability'     => (string) $row['ability'],
				'object_type' => (string) $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'reverted'    => ! empty( $row['reverted_at'] ),
				'note'        => (string) $row['note'],
			);
		}

		return array(
			'total' => count( $items ),
			'items' => $items,
		);
	}

	/**
	 * craynai/change-revert
	 *
	 * @return void
	 */
	private static function register_change_revert() {
		wp_register_ability(
			'craynai/change-revert',
			array(
				'label'              => 'Revert change',
				'description'        => 'Revert a logged change by its change id (get ids from changes-list or any write result). Restores the exact before snapshot: post fields, term assignments, SEO meta, plugin activation state, plugin files from the backup zip, WPBakery / Elementor page data, site settings, menus and menu locations. Refuses with craynai_revert_conflict when the object changed after the write. Reverting twice fails cleanly.',
				'category'           => 'craynai-site',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'change_id' => array(
							'type'        => 'integer',
							'description' => 'Id of the change row to revert.',
						),
					),
					'required'   => array( 'change_id' ),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'reverted'  => array( 'type' => 'boolean' ),
						'change_id' => array( 'type' => 'integer' ),
						'ability'   => array( 'type' => 'string' ),
						'details'   => array( 'type' => 'string', 'description' => 'Human readable description of what was restored.' ),
						'revert_change_id' => array( 'type' => 'integer', 'description' => 'Change log id of the revert itself.' ),
					),
				),
				'execute_callback'   => array( self::class, 'execute_change_revert' ),
				'permission_callback' => array( self::class, 'permission' ),
				'meta'               => Safety::meta( Safety::annotations( false, true, true, 'Revert Change' ) ),
			)
		);
	}

	/**
	 * Execute change-revert.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_change_revert( $input ) {
		return self::revert( isset( $input['change_id'] ) ? (int) $input['change_id'] : 0 );
	}

	/**
	 * Revert a logged change. Used by both the MCP ability and the admin page.
	 *
	 * @param int $change_id Change row id.
	 * @return array|\WP_Error
	 */
	public static function revert( $change_id ) {
		$guard = Safety::guard( 'craynai-site', true, false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'Reverting changes requires the manage_options capability.' );
		}

		$row = ChangeLogger::get( $change_id );
		if ( null === $row ) {
			return new \WP_Error( 'craynai_change_not_found', sprintf( 'Change %d does not exist.', $change_id ) );
		}
		if ( ! empty( $row['reverted_at'] ) ) {
			return new \WP_Error( 'craynai_already_reverted', sprintf( 'Change %d was already reverted at %s. Reverting twice is not allowed.', $change_id, $row['reverted_at'] ) );
		}

		$before  = ChangeLogger::decode( $row['before_data'] );
		$after   = ChangeLogger::decode( $row['after_data'] );
		$ability = (string) $row['ability'];
		$obj_id  = (int) $row['object_id'];

		$supported = array(
			'craynai/post-update',
			'craynai/post-trash',
			'craynai/post-create',
			'craynai/seo-update',
			'craynai/plugin-update',
			'craynai/plugin-activate',
			'craynai/plugin-deactivate',
		);
		$supported = array_merge(
			$supported,
			array(
				'craynai/rankmath-update',
				'craynai/rankmath-schema-set',
				'craynai/rankmath-schema-delete',
				'craynai/rankmath-redirect-set',
				'craynai/wpbakery-add',
				'craynai/wpbakery-update',
				'craynai/wpbakery-move',
				'craynai/wpbakery-remove',
				'craynai/wpbakery-replace',
				'craynai/wpbakery-enable',
				'craynai/wpbakery-page-css',
				'craynai/elementor-add',
				'craynai/elementor-update',
				'craynai/elementor-move',
				'craynai/elementor-remove',
				'craynai/elementor-duplicate',
				'craynai/elementor-replace',
				'craynai/elementor-page-settings',
				'craynai/elementor-enable',
				'craynai/settings-update',
				'craynai/menu-create',
				'craynai/menu-update',
				'craynai/menu-delete',
				'craynai/menu-item-add',
				'craynai/menu-item-update',
				'craynai/menu-item-move',
				'craynai/menu-item-remove',
				'craynai/menu-locations-set',
			)
		);
		$is_ext = 0 === strpos( $ability, 'craynai/ext-' );
		if ( ! $is_ext && ! in_array( $ability, $supported, true ) ) {
			return new \WP_Error(
				'craynai_revert_not_supported',
				sprintf( 'Changes of type "%s" cannot be reverted automatically. Nothing is ever hard deleted: created terms and imported media stay in place.', $ability )
			);
		}

		if ( self::changed_after_write( $ability, $obj_id, $before, $after ) ) {
			return new \WP_Error( 'craynai_revert_conflict', sprintf( 'What change %d wrote was changed afterwards. Revert the newer changes first (see changes-list).', $change_id ) );
		}

		// Claim the change atomically first, so a concurrent second revert fails cleanly.
		if ( ! ChangeLogger::mark_reverted( $change_id ) ) {
			return new \WP_Error( 'craynai_already_reverted', sprintf( 'Change %d was already reverted. Reverting twice is not allowed.', $change_id ) );
		}

		// State right before the revert, for the revert's own log row.
		$pre = self::current_state( $ability, $obj_id, $before );

		$details = null;
		switch ( $ability ) {
			case 'craynai/wpbakery-add':
			case 'craynai/wpbakery-update':
			case 'craynai/wpbakery-move':
			case 'craynai/wpbakery-remove':
			case 'craynai/wpbakery-replace':
			case 'craynai/wpbakery-enable':
			case 'craynai/wpbakery-page-css':
				$result = WpbakeryAbilities::revert( $row );
				break;
			case 'craynai/elementor-add':
			case 'craynai/elementor-update':
			case 'craynai/elementor-move':
			case 'craynai/elementor-remove':
			case 'craynai/elementor-duplicate':
			case 'craynai/elementor-replace':
			case 'craynai/elementor-page-settings':
			case 'craynai/elementor-enable':
				$result = ElementorAbilities::revert( $row );
				break;
			case 'craynai/settings-update':
				$result = SettingsAbilities::revert( $row );
				break;
			case 'craynai/menu-create':
			case 'craynai/menu-update':
			case 'craynai/menu-delete':
			case 'craynai/menu-item-add':
			case 'craynai/menu-item-update':
			case 'craynai/menu-item-move':
			case 'craynai/menu-item-remove':
			case 'craynai/menu-locations-set':
				$result = MenuAbilities::revert( $row );
				break;
			case 'craynai/post-update':
				$result = self::restore_post_snapshot( $obj_id, $before, $after );
				break;
			case 'craynai/post-trash':
				$result = self::untrash_post( $obj_id, $before );
				break;
			case 'craynai/post-create':
				$result = self::trash_created_post( $obj_id );
				break;
			case 'craynai/seo-update':
				$result = SeoAbilities::restore_snapshot( $obj_id, $before );
				if ( ! is_wp_error( $result ) ) {
					$result = sprintf( 'SEO fields of post %d restored.', $obj_id );
				}
				break;
			case 'craynai/plugin-update':
				$backup_file = isset( $after['backup_file'] ) ? (string) $after['backup_file'] : '';
				if ( '' === $backup_file ) {
					$result = new \WP_Error( 'craynai_no_backup_in_log', 'The change log has no backup file for this plugin update; cannot revert.' );
					break;
				}
				$result = PluginBackup::restore( $backup_file, true );
				if ( ! is_wp_error( $result ) ) {
					$result = sprintf( 'Plugin files restored from %s (version %s).', $backup_file, isset( $result['version'] ) ? $result['version'] : '?' );
				}
				break;
			case 'craynai/rankmath-update':
			case 'craynai/rankmath-schema-set':
			case 'craynai/rankmath-schema-delete':
				$result = RankMathAbilities::revert_change( $before, $after );
				break;
			case 'craynai/rankmath-redirect-set':
				$result = RankMathAbilities::revert_redirect( $obj_id, empty( $before ) ? null : $before, $after );
				break;
			case 'craynai/plugin-activate':
				$result = self::set_plugin_state( $before, 'deactivate' );
				break;
			case 'craynai/plugin-deactivate':
				$result = self::set_plugin_state( $before, 'activate' );
				break;
			default: // craynai/ext-* wrapper changes (plugin options).
				$result = ExternalAbilities::revert( $before, $after );
				break;
		}

		if ( is_wp_error( $result ) ) {
			ChangeLogger::unmark_reverted( $change_id ); // Nothing was (fully) restored; allow a retry.
			return $result;
		}

		$details = is_string( $result ) ? $result : wp_json_encode( $result, JSON_UNESCAPED_SLASHES );

		// The revert is a logged change of its own.
		$revert_log_id = ChangeLogger::log(
			'craynai/change-revert',
			(string) $row['object_type'],
			$obj_id,
			$pre,
			self::current_state( $ability, $obj_id, $before ),
			sprintf( 'Reverted change %d (%s). %s', $change_id, $ability, $details )
		);

		return array(
			'reverted'         => true,
			'change_id'        => $change_id,
			'ability'          => $ability,
			'details'          => $details,
			'revert_change_id' => $revert_log_id,
		);
	}

	/**
	 * Did a value this post, SEO or Rank Math field change wrote change afterwards? Values
	 * the change did not touch are ignored, so unrelated later edits do not block a revert.
	 *
	 * @param string $ability Ability of the change.
	 * @param int    $obj_id  Object id.
	 * @param array  $before  Before snapshot.
	 * @param array  $after   After snapshot.
	 * @return bool
	 */
	private static function changed_after_write( $ability, $obj_id, array $before, array $after ) {
		if ( 'craynai/post-update' === $ability ) {
			$fields  = array_flip( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name' ) );
			$before  = array_intersect_key( $before, $fields );
			$after   = array_intersect_key( $after, $fields );
			$current = (array) self::post_snapshot( $obj_id );
		} elseif ( 'craynai/seo-update' === $ability ) {
			unset( $after['raw'], $after['plugin'], $after['post_id'] );
			$current = SeoAbilities::seo_snapshot( $obj_id );
		} elseif ( 'craynai/rankmath-update' === $ability ) {
			$before  = isset( $before['raw'] ) ? (array) $before['raw'] : array();
			$after   = isset( $after['raw'] ) ? (array) $after['raw'] : array();
			$current = RankMathAbilities::raw_snapshot( $obj_id );
		} else {
			return false;
		}
		foreach ( $after as $key => $value ) {
			$old = isset( $before[ $key ] ) ? $before[ $key ] : null;
			$now = isset( $current[ $key ] ) ? $current[ $key ] : null;
			if ( $old !== $value && $now !== $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Snapshot of the object a change touches, for the revert's own log row.
	 *
	 * @param string $ability Ability of the reverted change.
	 * @param int    $obj_id  Object id.
	 * @param array  $before  Before snapshot of the reverted change (names the settings options).
	 * @return array|null
	 */
	private static function current_state( $ability, $obj_id, array $before = array() ) {
		if ( 'craynai/settings-update' === $ability ) {
			return SettingsAbilities::current_snapshot( array_map( 'strval', array_keys( $before ) ) );
		}
		if ( 0 === strpos( $ability, 'craynai/menu-' ) ) {
			return MenuAbilities::current_snapshot( $ability, $obj_id );
		}
		if ( $obj_id <= 0 ) {
			return null;
		}
		if ( 0 === strpos( $ability, 'craynai/elementor-' ) ) {
			return ElementorAbilities::current_snapshot( $obj_id );
		}
		if ( 0 === strpos( $ability, 'craynai/post-' ) ) {
			return self::post_snapshot( $obj_id );
		}
		if ( 'craynai/seo-update' === $ability ) {
			return SeoAbilities::seo_snapshot( $obj_id );
		}
		if ( 0 === strpos( $ability, 'craynai/wpbakery-' ) ) {
			return WpbakeryAbilities::current_snapshot( $obj_id );
		}
		if ( in_array( $ability, array( 'craynai/rankmath-update', 'craynai/rankmath-schema-set', 'craynai/rankmath-schema-delete' ), true ) ) {
			return array( 'post_id' => $obj_id, 'raw' => RankMathAbilities::raw_snapshot( $obj_id ) );
		}
		return null;
	}

	/**
	 * Restore a post snapshot (fields, terms, featured image and SEO meta).
	 * Terms, featured image and SEO are only restored when the original change
	 * actually altered them, so unrelated later edits are not rolled back.
	 *
	 * @param int   $post_id Post id.
	 * @param array $before  Before snapshot.
	 * @param array $after   After snapshot of the reverted change.
	 * @return string|\WP_Error
	 */
	private static function restore_post_snapshot( $post_id, array $before, array $after = array() ) {
		if ( empty( $before ) ) {
			return new \WP_Error( 'craynai_no_snapshot', 'The change log has no before snapshot for this post change.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'craynai_object_gone', sprintf( 'Post %d no longer exists.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'You cannot edit this post.' );
		}

		$postarr = array( 'ID' => $post_id );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name' ) as $field ) {
			// Only what this change wrote: a later edit of another field stays.
			if ( array_key_exists( $field, $before ) && ( ! array_key_exists( $field, $after ) || $before[ $field ] !== $after[ $field ] ) ) {
				$postarr[ $field ] = (string) $before[ $field ];
			}
		}

		if ( count( $postarr ) > 1 ) {
			// wp_update_post() unslashes its input.
			$updated = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		// Term assignments: only taxonomies the change altered.
		if ( isset( $before['terms'] ) && is_array( $before['terms'] ) ) {
			foreach ( $before['terms'] as $taxonomy => $term_ids ) {
				$old = array_values( array_filter( array_map( 'intval', (array) $term_ids ) ) );
				if ( isset( $after['terms'][ $taxonomy ] ) ) {
					$new = array_values( array_filter( array_map( 'intval', (array) $after['terms'][ $taxonomy ] ) ) );
					if ( $new === $old ) {
						continue;
					}
				}
				if ( taxonomy_exists( (string) $taxonomy ) ) {
					wp_set_object_terms( $post_id, $old, (string) $taxonomy, false );
				}
			}
		}

		// Featured image.
		if ( isset( $before['featured_media'] ) && ( ! isset( $after['featured_media'] ) || (int) $after['featured_media'] !== (int) $before['featured_media'] ) ) {
			if ( (int) $before['featured_media'] > 0 ) {
				set_post_thumbnail( $post_id, (int) $before['featured_media'] );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		// Rank Math meta (full raw snapshot): only the keys this change altered.
		$rank_math_done = false;
		if ( isset( $before['rankmath_raw'] ) && is_array( $before['rankmath_raw'] ) ) {
			$rank_math_done = true;
			$rm_after       = ( isset( $after['rankmath_raw'] ) && is_array( $after['rankmath_raw'] ) ) ? $after['rankmath_raw'] : array();
			$rm_keys        = RankMathAbilities::diff_keys( $before['rankmath_raw'], $rm_after );
			if ( ! empty( $rm_keys ) ) {
				RankMathAbilities::restore_raw( $post_id, $before['rankmath_raw'], $rm_keys );
			}
		}

		// SEO meta (Yoast, or older log rows without a Rank Math raw snapshot): only when this change altered it.
		if ( ! $rank_math_done && ! empty( $before['seo'] ) && is_array( $before['seo'] ) && ( ! isset( $after['seo'] ) || $after['seo'] !== $before['seo'] ) ) {
			$seo = SeoAbilities::restore_snapshot( $post_id, $before['seo'] );
			if ( is_wp_error( $seo ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Crayn AI Builder MCP: SEO restore issue for post ' . $post_id . ': ' . $seo->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		return sprintf( 'Post %d restored to its previous state (title, content, status, terms, featured image, SEO fields where changed).', $post_id );
	}

	/**
	 * Revert a post-trash change with WordPress' own untrash (removes the __trashed slug suffix).
	 *
	 * @param int   $post_id Post id.
	 * @param array $before  Before snapshot with post_status.
	 * @return string|\WP_Error
	 */
	private static function untrash_post( $post_id, array $before ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'craynai_object_gone', sprintf( 'Post %d no longer exists.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'You cannot edit this post.' );
		}
		if ( 'trash' !== $post->post_status ) {
			return sprintf( 'Post %d is no longer in the trash (status: %s); nothing to restore.', $post_id, $post->post_status );
		}

		$restored = wp_untrash_post( $post_id );
		if ( ! $restored ) {
			return new \WP_Error( 'craynai_untrash_failed', 'The post could not be restored from the trash.' );
		}

		// wp_untrash_post() restores the previous status (draft since WP 5.6); enforce the logged one.
		$want = isset( $before['post_status'] ) ? (string) $before['post_status'] : '';
		if ( '' !== $want && 'trash' !== $want && get_post_status( $post_id ) !== $want ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => $want ) );
		}

		// Drafts without a slug keep WordPress' "__trashed" suffix after untrash; strip it.
		$name = (string) get_post_field( 'post_name', $post_id );
		if ( preg_match( '/__trashed(-\d+)?$/', $name ) ) {
			global $wpdb;
			$clean = preg_replace( '/__trashed(-\d+)?$/', '', $name );
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- wp_update_post() would re-sanitize the slug and re-append the "__trashed(-N)" suffix; this line strips it and clean_post_cache() refreshes WordPress' own caches afterwards.
				$wpdb->posts,
				array( 'post_name' => $clean ),
				array( 'ID' => $post_id )
			);
			clean_post_cache( $post_id );
		}

		return sprintf( 'Post %d restored from the trash.', $post_id );
	}

	/**
	 * Revert a post-create change by safely trashing the created post.
	 *
	 * @param int $post_id Post id.
	 * @return string|\WP_Error
	 */
	private static function trash_created_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'craynai_object_gone', sprintf( 'Post %d no longer exists (already removed manually?).', $post_id ) );
		}
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'You cannot delete this post.' );
		}

		if ( 'trash' === $post->post_status ) {
			return sprintf( 'Post %d was already in the trash; the creation is effectively reverted.', $post_id );
		}

		$trashed = wp_trash_post( $post_id );
		if ( ! $trashed ) {
			return new \WP_Error( 'craynai_trash_failed', 'The post could not be moved to the trash.' );
		}

		return sprintf( 'Post %d (created by the reverted change) moved to the trash.', $post_id );
	}

	/**
	 * Restore plugin activation state from a snapshot.
	 *
	 * @param array  $before Before snapshot with plugin_file (+ network_active).
	 * @param string $target 'activate' or 'deactivate'.
	 * @return string|\WP_Error
	 */
	private static function set_plugin_state( array $before, $target ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$file = isset( $before['plugin_file'] ) ? (string) $before['plugin_file'] : '';
		if ( '' === $file ) {
			return new \WP_Error( 'craynai_no_snapshot', 'The change log has no plugin file for this activation change.' );
		}

		if ( 'deactivate' === $target && PluginAbilities::is_self( $file ) ) {
			return new \WP_Error( 'craynai_self_protected', 'Crayn AI Builder MCP refuses to deactivate itself. Do this manually in wp-admin.' );
		}

		$network = ! empty( $before['network_active'] );

		if ( 'deactivate' === $target ) {
			if ( ! is_plugin_active( $file ) && ( ! is_multisite() || ! is_plugin_active_for_network( $file ) ) ) {
				return sprintf( 'Plugin %s is already inactive; nothing to revert.', $file );
			}
			deactivate_plugins( $file, false, $network );
			return sprintf( 'Plugin %s deactivated (state was reverted).', $file );
		}

		if ( is_plugin_active( $file ) ) {
			return sprintf( 'Plugin %s is already active; nothing to revert.', $file );
		}
		$result = activate_plugin( $file, '', $network );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return sprintf( 'Plugin %s activated (state was reverted).', $file );
	}

	/**
	 * Permission for change abilities.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission( $input = array() ) {
		return current_user_can( 'manage_options' ) && null === Safety::guard( 'craynai-site', false );
	}
}
