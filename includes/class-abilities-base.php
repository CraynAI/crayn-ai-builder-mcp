<?php
/**
 * Base helpers shared by all ability registrations.
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Common schema property builders and annotation shortcuts.
 */
abstract class AbilitiesBase {

	/**
	 * Common output fields for a post summary.
	 *
	 * @param int|\WP_Post $post Post id or object.
	 * @return array<string, mixed>
	 */
	protected static function post_summary( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array();
		}
		return array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'slug'     => $post->post_name,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'excerpt'  => $post->post_excerpt,
			'link'     => get_permalink( $post ),
		);
	}

	/**
	 * Capability gate for tools bound to one post. A post that does not exist falls
	 * back to edit_posts, so the tool itself can answer "not found" instead of a
	 * misleading permission error.
	 *
	 * @param int $post_id Post id (0 when none was given).
	 * @return bool
	 */
	protected static function can_edit_post_or_missing( $post_id ) {
		return ( $post_id > 0 && get_post( $post_id ) ) ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
	}

	/**
	 * Ensure WP admin helper functions are available (REST context does not load wp-admin).
	 *
	 * @param array $files Extra wp-admin includes to load.
	 * @return void
	 */
	protected static function load_admin_includes( array $files = array() ) {
		foreach ( array( 'plugin.php' ) as $base ) {
			require_once ABSPATH . 'wp-admin/includes/' . $base;
		}
		foreach ( $files as $file ) {
			require_once ABSPATH . 'wp-admin/includes/' . $file;
		}
	}

	/**
	 * Standard metadata for change log "after_data" of posts.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>
	 */
	protected static function post_snapshot( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$terms = array();
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) ) {
				$terms[ $taxonomy ] = array_map( 'intval', $ids );
			}
		}

		$snapshot = array(
			'ID'            => (int) $post->ID,
			'post_title'    => $post->post_title,
			'post_content'  => $post->post_content,
			'post_excerpt'  => $post->post_excerpt,
			'post_status'   => $post->post_status,
			'post_name'     => $post->post_name,
			'post_type'     => $post->post_type,
			'terms'         => $terms,
			'featured_media' => (int) get_post_thumbnail_id( $post_id ),
			'seo'           => SeoAbilities::seo_snapshot( $post_id ),
		);

		// Full Rank Math meta (schemas, social, robots, ...) so post reverts restore it exactly.
		if ( RankMathAbilities::is_active() ) {
			$snapshot['rankmath_raw'] = RankMathAbilities::raw_snapshot( $post_id );
		}

		return $snapshot;
	}

	/**
	 * Standard output schema for change-shaped results (dry_run/before/after/change_id).
	 *
	 * @param array $extra Extra properties to merge in.
	 * @return array<string, mixed>
	 */
	protected static function change_output_schema( array $extra = array() ) {
		$props = array(
			'dry_run'   => array( 'type' => 'boolean', 'description' => 'True when nothing was written.' ),
			'before'    => array( 'type' => array( 'object', 'null' ), 'description' => 'State before the change (null for creations).' ),
			'after'     => array( 'type' => 'object', 'description' => 'Planned (dry run) or actual state after the change.' ),
			'change_id' => array( 'type' => 'integer', 'description' => 'Change log row id you can revert with change-revert (0 on dry run).' ),
		);
		foreach ( $extra as $key => $schema ) {
			$props[ $key ] = $schema;
		}
		return array( 'type' => 'object', 'properties' => $props );
	}
}
