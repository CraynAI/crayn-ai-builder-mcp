<?php
/**
 * Plugin Name:       Crayn AI Builder MCP
 * Plugin URI:        https://github.com/CraynAI/crayn-ai-builder-mcp
 * Description:       Let AI agents build and manage your site with direct, ready-to-call tools. Every write can be previewed, is logged and can be reverted. Built on the official WordPress MCP Adapter, included.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Yunus Doğukan OĞLAKCI (CraynAI)
 * Author URI:        https://www.crayn.ai/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       crayn-ai-builder-mcp
 *
 * Copyright (C) 2026 Yunus Doğukan OĞLAKCI (CraynAI)
 */

defined( 'ABSPATH' ) || exit;

define( 'CRAYN_AI_BUILDER_MCP_VERSION', '1.0.0' );
define( 'CRAYN_AI_BUILDER_MCP_FILE', __FILE__ );
define( 'CRAYN_AI_BUILDER_MCP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Minimum WordPress version required by this plugin (Abilities API is in core).
 */
define( 'CRAYN_AI_BUILDER_MCP_MIN_WP', '6.9' );

/**
 * Minimum PHP version required by this plugin.
 */
define( 'CRAYN_AI_BUILDER_MCP_MIN_PHP', '7.4' );

/**
 * Show an admin notice when environment requirements are not met.
 *
 * @param string $message Notice text.
 * @param string $level   Notice level.
 * @return void
 */
function crayn_ai_builder_mcp_requirement_notice( $message, $level = 'error' ) {
	add_action(
		'admin_notices',
		static function () use ( $message, $level ) {
			printf(
				'<div class="notice notice-%1$s"><p><strong>%2$s:</strong> %3$s</p></div>',
				esc_attr( $level ),
				esc_html__( 'Crayn AI Builder MCP', 'crayn-ai-builder-mcp' ),
				esc_html( $message )
			);
		}
	);
}

// PHP version check. The plugin code is PHP 7.4 compatible; still guard explicitly.
if ( version_compare( PHP_VERSION, CRAYN_AI_BUILDER_MCP_MIN_PHP, '<' ) ) {
	crayn_ai_builder_mcp_requirement_notice(
		sprintf(
			/* translators: 1: minimum PHP version, 2: current PHP version. */
			__( 'PHP %1$s or higher is required. You are running PHP %2$s.', 'crayn-ai-builder-mcp' ),
			CRAYN_AI_BUILDER_MCP_MIN_PHP,
			PHP_VERSION
		)
	);
	return;
}

// WordPress version check.
global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, CRAYN_AI_BUILDER_MCP_MIN_WP, '<' ) ) {
	crayn_ai_builder_mcp_requirement_notice(
		sprintf(
			/* translators: 1: minimum WordPress version, 2: current WordPress version. */
			__( 'WordPress %1$s or higher is required (the Abilities API must be in core). You are running WordPress %2$s.', 'crayn-ai-builder-mcp' ),
			CRAYN_AI_BUILDER_MCP_MIN_WP,
			$wp_version
		)
	);
	return;
}

// Bundled dependencies (official MCP Adapter + Jetpack Autoloader).
// See the MCP Adapter installation docs, section "As a Composer Library":
// bundling plugins should use Jetpack Autoloader to avoid class conflicts
// with the standalone MCP Adapter plugin or other plugins shipping their own copy.

/**
 * Is the canonical "mcp-adapter" WordPress.org plugin active (single-site or
 * network-wide)? Plugins load alphabetically, so this plugin's file runs
 * BEFORE the canonical plugin's file and the adapter classes may not exist
 * yet; the decision therefore relies on the active plugin lists plus
 * file_exists(), never on class_exists().
 *
 * @return bool
 */
function crayn_ai_builder_mcp_canonical_adapter_active() {
	$plugin_file = 'mcp-adapter/mcp-adapter.php';

	if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
		return false;
	}

	if ( in_array( $plugin_file, (array) get_option( 'active_plugins', array() ), true ) ) {
		return true;
	}

	if ( is_multisite() ) {
		$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
		if ( isset( $network_active[ $plugin_file ] ) ) {
			return true;
		}
	}

	return false;
}

// When the canonical plugin is active it starts the adapter and this plugin does
// not load its bundled autoloader. A Jetpack Autoloader on the site can still
// resolve the classes from this copy; the adapter then logs a duplicate-copy notice
// in debug mode. Tested working in that state.
$crayn_ai_builder_mcp_bundled_adapter = ! crayn_ai_builder_mcp_canonical_adapter_active();

if ( $crayn_ai_builder_mcp_bundled_adapter ) {
	$crayn_ai_builder_mcp_autoloader = CRAYN_AI_BUILDER_MCP_DIR . 'vendor/autoload_packages.php';
	if ( ! file_exists( $crayn_ai_builder_mcp_autoloader ) ) {
		$crayn_ai_builder_mcp_autoloader = CRAYN_AI_BUILDER_MCP_DIR . 'vendor/autoload.php';
	}
	if ( ! file_exists( $crayn_ai_builder_mcp_autoloader ) ) {
		crayn_ai_builder_mcp_requirement_notice(
			__( 'The bundled dependencies are missing. Please run "composer install --no-dev" in the plugin directory.', 'crayn-ai-builder-mcp' )
		);
		return;
	}
	require_once $crayn_ai_builder_mcp_autoloader;
}

define( 'CRAYN_AI_BUILDER_MCP_BUNDLED_ADAPTER', $crayn_ai_builder_mcp_bundled_adapter );

// Load plugin classes directly so the plugin does not depend on classmap generation.
$crayn_ai_builder_mcp_includes = array(
	'includes/class-settings.php',
	'includes/class-change-logger.php',
	'includes/class-safety.php',
	'includes/class-health-check.php',
	'includes/class-plugin-backup.php',
	'includes/class-abilities-base.php',
	'includes/class-content-abilities.php',
	'includes/class-shortcode-tree.php',
	'includes/class-wpbakery-abilities.php',
	'includes/class-elementor-tree.php',
	'includes/class-elementor-abilities.php',
	'includes/class-settings-abilities.php',
	'includes/class-menu-abilities.php',
	'includes/class-seo-abilities.php',
	'includes/class-rankmath-abilities.php',
	'includes/class-external-abilities.php',
	'includes/class-plugin-abilities.php',
	'includes/class-site-abilities.php',
	'includes/class-change-abilities.php',
	'includes/class-admin-page.php',
	'includes/class-plugin.php',
);
foreach ( $crayn_ai_builder_mcp_includes as $crayn_ai_builder_mcp_include ) {
	require_once CRAYN_AI_BUILDER_MCP_DIR . $crayn_ai_builder_mcp_include;
}

register_activation_hook( __FILE__, array( 'CraynAiBuilderMcp\\ChangeLogger', 'activate' ) );

// Boot early on plugins_loaded so our hooks are in place before the MCP Adapter
// fires "mcp_adapter_init" during plugins_loaded.
CraynAiBuilderMcp\Plugin::boot();
