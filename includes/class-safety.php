<?php
/**
 * Shared safety helpers for Crayn Safe MCP abilities.
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Guards used by every ability: category gate, read-only mode,
 * dry-run helpers and MCP annotations.
 */
class Safety {

	/**
	 * Gate every ability behind its category setting (and read-only mode for writes).
	 *
	 * @param string $category      Category slug: content|seo|plugins|site.
	 * @param bool   $for_write     True when the ability would write data.
	 * @param bool   $dry_run       True when the caller asked for a dry run.
	 * @return \WP_Error|null Null when the call is allowed, WP_Error otherwise.
	 */
	public static function guard( $category, $for_write = false, $dry_run = false ) {
		$category = preg_replace( '/^crayn-/', '', (string) $category );
		if ( ! Settings::category_enabled( $category ) ) {
			return new \WP_Error(
				'crayn_safe_category_disabled',
				sprintf(
					/* translators: %s: category slug. */
					__( 'The Crayn Safe MCP category "%s" is disabled in the plugin settings.', 'crayn-safe-mcp' ),
					$category
				)
			);
		}

		if ( $for_write && Settings::read_only() && ! $dry_run ) {
			return new \WP_Error(
				'crayn_safe_read_only',
				__( 'Crayn Safe MCP is in read-only mode. Enable writes in Settings or pass dry_run: true to preview the change.', 'crayn-safe-mcp' )
			);
		}

		return null;
	}

	/**
	 * Read the dry_run flag from validated input.
	 *
	 * @param array $input Validated ability input.
	 * @return bool
	 */
	public static function dry_run( $input ) {
		return isset( $input['dry_run'] ) && ! empty( $input['dry_run'] );
	}

	/**
	 * MCP annotations helper (WordPress Abilities API format).
	 *
	 * @param bool $readonly      Does not modify data.
	 * @param bool $destructive   May delete/destroy data (trash only here).
	 * @param bool $idempotent    Same input produces same result.
	 * @param string $title       Optional human title.
	 * @return array<string, mixed>
	 */
	public static function annotations( $readonly, $destructive, $idempotent, $title = '' ) {
		$annotations = array(
			'readonly'    => (bool) $readonly,
			'destructive' => (bool) $destructive,
			'idempotent'  => (bool) $idempotent,
		);
		if ( '' !== $title ) {
			$annotations['title'] = $title;
		}
		return $annotations;
	}

	/**
	 * Meta for public MCP exposure (works for the default server AND custom servers).
	 *
	 * @param array  $annotations Annotations array.
	 * @param string $type        MCP component type: tool (default).
	 * @return array<string, mixed>
	 */
	public static function meta( array $annotations, $type = 'tool' ) {
		return array(
			'mcp' => array(
				'public' => true,
				'type'   => $type,
			),
			'annotations' => $annotations,
		);
	}

	/**
	 * Boolean schema for the universal dry_run input flag.
	 *
	 * @return array<string, mixed>
	 */
	public static function dry_run_property() {
		return array(
			'type'        => 'boolean',
			'description' => 'Dry run: return the planned change as {before, after} without writing anything. Default false.',
			'default'     => false,
		);
	}
}
