<?php
/**
 * Settings storage for Crayn AI Builder MCP.
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the plugin option `crayn_ai_builder_mcp_settings`.
 */
class Settings {

	/**
	 * Option name.
	 */
	const OPTION_NAME = 'crayn_ai_builder_mcp_settings';

	/**
	 * All category slugs with labels.
	 *
	 * @return array<string, string>
	 */
	public static function categories() {
		return array(
			'content' => __( 'Content (posts, media, terms)', 'crayn-ai-builder-mcp' ),
			'seo'     => __( 'SEO (Rank Math / Yoast fields)', 'crayn-ai-builder-mcp' ),
			'plugins' => __( 'Plugins (list, update, activate, rollback)', 'crayn-ai-builder-mcp' ),
			'site'    => __( 'Site health (read only)', 'crayn-ai-builder-mcp' ),
			'settings' => __( 'Site settings and menus (allow-listed options, navigation menus)', 'crayn-ai-builder-mcp' ),
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'enabled_categories' => array( 'content', 'seo', 'plugins', 'site', 'settings' ),
			'read_only'          => false,
			'external_abilities' => false,
		);
	}

	/**
	 * Get the raw settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Is a category enabled?
	 *
	 * @param string $category Category slug.
	 * @return bool
	 */
	public static function category_enabled( $category ) {
		$all      = self::all();
		$category = preg_replace( '/^craynai-/', '', (string) $category );
		return in_array( $category, (array) $all['enabled_categories'], true );
	}

	/**
	 * Is read-only mode enabled?
	 *
	 * @return bool
	 */
	public static function read_only() {
		$all = self::all();
		return (bool) $all['read_only'];
	}

	/**
	 * Are Rank Math / Yoast SEO abilities exposed (read-only ones directly, writing ones via safe wrappers)?
	 *
	 * @return bool
	 */
	public static function external_abilities() {
		$all = self::all();
		return ! empty( $all['external_abilities'] );
	}

	/**
	 * Update settings from a raw (unsanitized) array.
	 *
	 * @param mixed $input Raw input, e.g. from the settings form.
	 * @return void
	 */
	public static function update( $input ) {
		update_option( self::OPTION_NAME, self::sanitize( $input ), false );
	}

	/**
	 * Sanitize callback for register_setting(). Returns the clean value and never
	 * calls update_option() itself (that would recurse through the sanitize filter).
	 * Accepts both the form shape (slug => 1) and the stored shape (list of slugs).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$raw = isset( $input['enabled_categories'] ) ? (array) $input['enabled_categories'] : array();

		$categories = array();
		foreach ( array_keys( self::categories() ) as $slug ) {
			if ( ! empty( $raw[ $slug ] ) || in_array( $slug, $raw, true ) ) {
				$categories[] = $slug;
			}
		}

		return array(
			'enabled_categories' => $categories,
			'read_only'          => ! empty( $input['read_only'] ),
			'external_abilities' => ! empty( $input['external_abilities'] ),
		);
	}
}
