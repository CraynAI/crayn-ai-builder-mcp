<?php
/**
 * Plugin bootstrap for Crayn AI Builder MCP.
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Wires abilities, categories and the custom MCP server into WordPress.
 */
class Plugin {

	/**
	 * Boot on plugins_loaded so our hooks exist before the MCP Adapter fires mcp_adapter_init.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'plugins_loaded', array( self::class, 'init' ), 1 );
	}

	/**
	 * Initialize the plugin: hooks, admin page (WordPress.org loads translations itself).
	 *
	 * @return void
	 */
	public static function init() {
		// Categories must exist before any ability is registered.
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
		ExternalAbilities::init();

		// The adapter fires this while initializing (rest_api_init, or init:20 in WP-CLI);
		// our server lists all CraynAI abilities as direct tools, and we keep
		// meta.mcp.public = true on every ability so the default discovery server finds them too.
		add_action( 'mcp_adapter_init', array( self::class, 'create_mcp_server' ) );

		// Start the bundled adapter only when the canonical plugin is not active:
		// the canonical plugin initializes its own copy, and ours stays unloaded.
		if ( CRAYN_AI_BUILDER_MCP_BUNDLED_ADAPTER && class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}

		// Admin surface.
		if ( is_admin() ) {
			AdminPage::init();
		}

		// Friendly notice when the bundled adapter could not be loaded.
		add_action( 'admin_notices', array( self::class, 'maybe_notice_adapter' ) );
	}

	/**
	 * Notify when no usable MCP Adapter is loaded (vendor/ not built, filtered
	 * away, or the canonical adapter plugin failed to load).
	 *
	 * @return void
	 */
	public static function maybe_notice_adapter() {
		if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
			return;
		}
		$adapter_active = ( defined( 'CRAYN_AI_BUILDER_MCP_BUNDLED_ADAPTER' ) && CRAYN_AI_BUILDER_MCP_BUNDLED_ADAPTER );
		if ( crayn_ai_builder_mcp_canonical_adapter_active() && ! $adapter_active ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
				esc_html__( 'Crayn AI Builder MCP', 'crayn-ai-builder-mcp' ),
				esc_html__( 'The MCP Adapter plugin is active, but its adapter classes did not load, so MCP clients cannot reach the site. Check the MCP Adapter plugin installation (its vendor/ directory).', 'crayn-ai-builder-mcp' )
			);
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
			esc_html__( 'Crayn AI Builder MCP', 'crayn-ai-builder-mcp' ),
			esc_html__( 'The MCP Adapter was not loaded, so MCP clients cannot reach the site. Make sure vendor/ exists in the plugin directory (run "composer install --no-dev").', 'crayn-ai-builder-mcp' )
		);
	}

	/**
	 * Register the CraynAI categories.
	 *
	 * @return void
	 */
	public static function register_categories() {
		wp_register_ability_category(
			'craynai-content',
			array(
				'label'       => __( 'Crayn: Content', 'crayn-ai-builder-mcp' ),
				'description' => __( 'Safe content management: posts, media and terms with preview, logging and revert.', 'crayn-ai-builder-mcp' ),
			)
		);
		wp_register_ability_category(
			'craynai-seo',
			array(
				'label'       => __( 'Crayn: SEO', 'crayn-ai-builder-mcp' ),
				'description' => __( 'Safe SEO field management for Rank Math and Yoast with old-value logging.', 'crayn-ai-builder-mcp' ),
			)
		);
		wp_register_ability_category(
			'craynai-plugins',
			array(
				'label'       => __( 'Crayn: Plugins', 'crayn-ai-builder-mcp' ),
				'description' => __( 'Safe plugin list/update/activate/rollback with zipped backups and automatic health-check rollback.', 'crayn-ai-builder-mcp' ),
			)
		);
		wp_register_ability_category(
			'craynai-site',
			array(
				'label'       => __( 'Crayn: Site', 'crayn-ai-builder-mcp' ),
				'description' => __( 'Site health and the change log (list and revert) with admin-only permissions.', 'crayn-ai-builder-mcp' ),
			)
		);
		wp_register_ability_category(
			'craynai-settings',
			array(
				'label'       => __( 'Site settings and menus', 'crayn-ai-builder-mcp' ),
				'description' => __( 'Allow-listed site settings and navigation menus with preview, logging and revert.', 'crayn-ai-builder-mcp' ),
			)
		);
	}

	/**
	 * Register abilities of every enabled category.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( Settings::category_enabled( 'content' ) ) {
			ContentAbilities::register();
			WpbakeryAbilities::register(); // Only registers when WPBakery is active.
			ElementorAbilities::register(); // Only registers when Elementor is loaded.
		}
		if ( Settings::category_enabled( 'seo' ) ) {
			SeoAbilities::register();
			RankMathAbilities::register(); // Only registers when Rank Math is active.
		}
		if ( Settings::category_enabled( 'plugins' ) ) {
			PluginAbilities::register();
		}
		if ( Settings::category_enabled( 'site' ) ) {
			SiteAbilities::register();
			// The change log abilities live in the site category too.
			ChangeAbilities::register();
		}
		if ( Settings::category_enabled( 'settings' ) ) {
			SettingsAbilities::register();
			MenuAbilities::register();
		}
	}

	/**
	 * Create the custom MCP server that lists every CraynAI ability as a direct tool.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
	 * @return void
	 */
	public static function create_mcp_server( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		// Every disabled category disappears from the server's tool list.
		$names = array();
		foreach (
			array(
				'content' => 'ContentAbilities',
				'seo'     => 'SeoAbilities',
				'plugins' => 'PluginAbilities',
				'site'    => 'SiteAbilities',
			) as $category => $class
		) {
			if ( Settings::category_enabled( $category ) ) {
				$names = array_merge( $names, call_user_func( array( 'CraynAiBuilderMcp\\' . $class, 'ability_names' ) ) );
			}
		}

		// WPBakery tools follow the content category.
		if ( Settings::category_enabled( 'content' ) && defined( 'WPB_VC_VERSION' ) ) {
			$names = array_merge( $names, WpbakeryAbilities::ability_names() );
		}

		// Elementor tools follow the content category and exist only when Elementor is loaded.
		if ( Settings::category_enabled( 'content' ) && ElementorAbilities::is_loaded() ) {
			$names = array_merge( $names, ElementorAbilities::ability_names() );
		}

		// Site settings and menu tools follow the settings category.
		if ( Settings::category_enabled( 'settings' ) ) {
			$names = array_merge( $names, SettingsAbilities::ability_names(), MenuAbilities::ability_names() );
		}

		// The change log tools follow the site category.
		if ( Settings::category_enabled( 'site' ) ) {
			$names = array_merge( $names, ChangeAbilities::ability_names() );
		}

		// Rank Math / Yoast abilities: read-only ones directly, writing ones as safe wrappers.
		if ( Settings::category_enabled( 'seo' ) ) {
			$names = array_merge( $names, ExternalAbilities::server_names() );
		}

		$adapter->create_server(
			'craynai',
			'craynai/v1',
			'mcp',
			__( 'Crayn AI Builder MCP', 'crayn-ai-builder-mcp' ),
			__( 'Safe, previewable, revertible WordPress abilities: content, page builders, SEO, plugins, menus, settings and site health. Every write supports dry run, is logged with before/after snapshots and can be reverted.', 'crayn-ai-builder-mcp' ),
			'1.0.0',
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			null,
			array_values( array_unique( $names ) ),
			array(),
			array(),
			static function () {
				// Transport gate: any authenticated user passes; fine-grained caps are
				// enforced per ability. Application Passwords send Basic auth.
				return is_user_logged_in();
			}
		);
	}
}
