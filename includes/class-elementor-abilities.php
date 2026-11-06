<?php
/**
 * Elementor abilities: read and safely edit pages built with Elementor through
 * an id-addressed element tree (ElementorTree).
 *
 * Registered only when Elementor is loaded. Every write supports dry_run, is
 * logged via ChangeLogger with a full snapshot (raw data meta, page settings,
 * edit mode, version, template type, post_content) and is reverted through
 * ElementorAbilities::revert().
 *
 * Why the data meta is written directly instead of through Elementor's
 * Document::save() - see SONUC-MODULLER.md (Elementor section): Document::save()
 * re-creates every element from the widget registry (get_elements_raw_data(),
 * core/base/document.php:1074-1131, the `continue` at 1113-1115) and silently drops elements whose widget is
 * not registered (a deactivated Pro/third-party plugin), re-encodes the whole
 * document and runs hooks of other plugins. Direct meta writes keep every
 * untouched element byte-identical and are exactly revertible. What Elementor's
 * save regenerates (page CSS, element cache, version, template type, the
 * post_content plain-text mirror) is refreshed here the same way.
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "craynai-content" Elementor abilities.
 */
class ElementorAbilities extends AbilitiesBase {

	/**
	 * Elementor data meta (core/base/document.php:44).
	 */
	const META_DATA = '_elementor_data';

	/**
	 * "Built with Elementor" meta, value "builder" (core/base/document.php:46,954-960).
	 */
	const META_MODE = '_elementor_edit_mode';

	/**
	 * Elementor version that saved the document (core/base/document.php:1443-1458).
	 */
	const META_VERSION = '_elementor_version';

	/**
	 * Document type meta, e.g. "wp-page" (core/base/document.php:42,1466-1469).
	 */
	const META_TYPE = '_elementor_template_type';

	/**
	 * Page settings meta (core/settings/page/manager.php:30).
	 */
	const META_PAGE = '_elementor_page_settings';

	/**
	 * Generated CSS meta (core/files/css/post.php:30).
	 */
	const META_CSS = '_elementor_css';

	/**
	 * Element cache meta (core/base/document.php:48).
	 */
	const META_CACHE = '_elementor_element_cache';

	/**
	 * Page settings that Elementor does not keep in the page settings meta
	 * (core/settings/page/manager.php, get_special_settings_names()).
	 *
	 * @var string[]
	 */
	const SPECIAL_PAGE_SETTINGS = array( 'id', 'post_title', 'post_status', 'template', 'post_excerpt', 'post_featured_image', 'menu_order', 'comment_status' );

	/**
	 * Registered "widgets" that are not placeable elements: the shared common control
	 * holders and the inner section (stored as a section with isInner, includes/managers/widgets.php:83).
	 *
	 * @var string[]
	 */
	const INTERNAL_WIDGETS = array( 'common', 'common-base', 'common-optimized', 'inner-section' );

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		return array(
			'craynai/elementor-widgets',
			'craynai/elementor-get',
			'craynai/elementor-add',
			'craynai/elementor-update',
			'craynai/elementor-move',
			'craynai/elementor-remove',
			'craynai/elementor-duplicate',
			'craynai/elementor-replace',
			'craynai/elementor-page-settings',
			'craynai/elementor-enable',
		);
	}

	/**
	 * Is Elementor loaded? "elementor/loaded" fires inside Plugin::instance()
	 * (includes/plugin.php:605) when elementor.php is included, which is before
	 * plugins_loaded and therefore before the Abilities API init hook runs. The
	 * widget and element registries do NOT exist yet at that point: they are created
	 * on "init" priority 0 (includes/plugin.php:826, init_components() at 681) and filled lazily, so
	 * they are only touched at execute time (see runtime_error()).
	 *
	 * @return bool
	 */
	public static function is_loaded() {
		return did_action( 'elementor/loaded' ) > 0;
	}

	/**
	 * Register every Elementor ability (only when Elementor is loaded).
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! self::is_loaded() ) {
			return;
		}
		self::register_widgets();
		self::register_get();
		self::register_add();
		self::register_update();
		self::register_move();
		self::register_remove();
		self::register_duplicate();
		self::register_replace();
		self::register_page_settings();
		self::register_enable();
	}

	// ---------------------------------------------------------------------
	// Registration
	// ---------------------------------------------------------------------

	/**
	 * Schema fragment: post id.
	 *
	 * @return array<string,mixed>
	 */
	private static function post_id_property() {
		return array(
			'type'        => 'integer',
			'description' => 'ID of the post/page built with Elementor.',
		);
	}

	/**
	 * Schema fragment: element id.
	 *
	 * @param string $label Description.
	 * @return array<string,mixed>
	 */
	private static function element_id_property( $label = 'Element id (the "id" member of the element, see elementor-get).' ) {
		return array(
			'type'        => 'string',
			'description' => $label,
		);
	}

	/**
	 * Schema fragment: new element input.
	 *
	 * @return array<string,mixed>
	 */
	private static function element_property() {
		return array(
			'type'        => 'object',
			'description' => 'Element to add: {elType, widgetType?, settings?, elements?}. elType is "container" (or "section" / "column") or "widget"; a widget needs widgetType (see elementor-widgets). "elements" are children of the same shape (recursive). Ids are generated by the tool, ids in the input are ignored. V4 atomic elements ("e-...") are not supported.',
			'properties'  => array(
				'elType'     => array( 'type' => 'string', 'description' => 'container | section | column | widget.' ),
				'widgetType' => array( 'type' => 'string', 'description' => 'Widget name for elType "widget", e.g. "heading".' ),
				'settings'   => array( 'type' => 'object', 'description' => 'Control values, e.g. {"title":"Hello"}. Names/options: elementor-widgets with the widget name.' ),
				'elements'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Child elements, same shape.' ),
			),
			'required'    => array( 'elType' ),
		);
	}

	/**
	 * Extra output fields shared by the write abilities.
	 *
	 * @return array<string,mixed>
	 */
	private static function write_output() {
		return array(
			'post_id'    => array( 'type' => 'integer' ),
			'element_id' => array( 'type' => 'string', 'description' => 'Id of the added / duplicated element (empty for other tools).' ),
			'outline'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Outline of the page after the change (also given for dry runs).' ),
			'warnings'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		);
	}

	/**
	 * Shared registration wrapper.
	 *
	 * @param string $name        Ability name.
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @param array  $properties  Input properties.
	 * @param array  $required    Required input names.
	 * @param string $callback    Static method name of this class.
	 * @param bool   $write       True for write abilities.
	 * @param bool   $destructive Destructive hint.
	 * @param bool   $idempotent  Idempotent hint.
	 * @param array  $output      Output schema (read abilities) or null for the change shape.
	 * @return void
	 */
	private static function define( $name, $label, $description, array $properties, array $required, $callback, $write, $destructive, $idempotent, $output = null ) {
		if ( $write ) {
			$properties['dry_run'] = Safety::dry_run_property();
		}
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'craynai-content',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => $required,
				),
				'output_schema'       => null === $output ? self::change_output_schema( self::write_output() ) : $output,
				'execute_callback'    => array( self::class, $callback ),
				'permission_callback' => array( self::class, $write ? 'permission_write' : 'permission_read' ),
				'meta'                => Safety::meta( Safety::annotations( ! $write, $destructive, $idempotent, $label ) ),
			)
		);
	}

	/**
	 * Register craynai/elementor-widgets.
	 *
	 * @return void
	 */
	private static function register_widgets() {
		self::define(
			'craynai/elementor-widgets',
			'List Elementor widgets',
			'List the widgets that are registered on this site (name, title, categories) and the layout element types, or, with "name", the controls of one widget (name, type, label, default, options). Search with "search"/"category". Control lists default to the content tab; ask for tab "style" or "advanced" for the rest. V4 atomic widgets are listed (atomic: true) but can only be moved/removed by craynai tools.',
			array(
				'name'           => array( 'type' => 'string', 'description' => 'Widget name for its controls, e.g. "heading".' ),
				'search'         => array( 'type' => 'string', 'description' => 'Case-insensitive filter on name/title/keywords.' ),
				'category'       => array( 'type' => 'string', 'description' => 'Exact widget category, e.g. "basic" or "general".' ),
				'tab'            => array( 'type' => 'string', 'description' => 'With "name": control tab content|style|advanced|all. Default content.' ),
				'include_common' => array( 'type' => 'boolean', 'description' => 'With "name": also return the common (advanced tab) controls shared by all widgets. Default false.' ),
				'include_wp_widgets' => array( 'type' => 'boolean', 'description' => 'List the WordPress widgets wrapped by Elementor (wp-widget-*). Default false.' ),
				'limit'          => array( 'type' => 'integer', 'description' => 'Maximum list size (1-300). Default 100.', 'default' => 100 ),
				'offset'         => array( 'type' => 'integer', 'description' => 'List offset. Default 0.', 'default' => 0 ),
			),
			array(),
			'execute_elementor_widgets',
			false,
			false,
			true,
			array(
				'type'       => 'object',
				'properties' => array(
					'total'    => array( 'type' => 'integer' ),
					'items'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'elements' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Layout element types (container, section, column, atomic).' ),
					'widget'   => array( 'type' => 'object', 'description' => 'With "name": the widget and its controls.' ),
				),
			)
		);
	}

	/**
	 * Register craynai/elementor-get.
	 *
	 * @return void
	 */
	private static function register_get() {
		self::define(
			'craynai/elementor-get',
			'Get Elementor page',
			'Get a post/page and its Elementor state: uses_elementor flag, document type, outline (path, id, type, text preview per element), the full element tree as JSON (decoded; an empty settings object reads as []) and the page settings. Posts that are not built with Elementor are reported with uses_elementor false and an empty tree; no error.',
			array(
				'post_id'      => self::post_id_property(),
				'include_tree' => array( 'type' => 'boolean', 'description' => 'Include the full tree. Default true; false returns only the outline.', 'default' => true ),
			),
			array( 'post_id' ),
			'execute_elementor_get',
			false,
			false,
			true,
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'         => array( 'type' => 'integer' ),
					'post_type'       => array( 'type' => 'string' ),
					'post_status'     => array( 'type' => 'string' ),
					'uses_elementor'  => array( 'type' => 'boolean', 'description' => 'True when _elementor_edit_mode is "builder".' ),
					'has_data'        => array( 'type' => 'boolean' ),
					'document_type'   => array( 'type' => 'string' ),
					'saved_with'      => array( 'type' => 'string', 'description' => 'Elementor version that saved the data (meta _elementor_version).' ),
					'active_version'  => array( 'type' => 'string' ),
					'data_length'     => array( 'type' => 'integer' ),
					'has_atomic'      => array( 'type' => 'boolean', 'description' => 'True when the page uses V4 atomic elements (movable/removable here, not editable).' ),
					'container_available' => array( 'type' => 'boolean' ),
					'outline'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'tree'            => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'page_settings'   => array( 'type' => 'object' ),
					'data_error'      => array( 'type' => 'string' ),
					'locked_by'       => array( 'type' => 'string', 'description' => 'Display name of another user editing the page right now.' ),
				),
			)
		);
	}

	/**
	 * Register craynai/elementor-add.
	 *
	 * @return void
	 */
	private static function register_add() {
		self::define(
			'craynai/elementor-add',
			'Add Elementor element',
			'Add an element (optionally with children) under a parent element id, or at the top level when parent_id is empty. position is 0-based among the siblings, omitted = append. Validates the widget exists and the placement (section > column > widget/inner section; container > widget/container; widgets only inside a container or column). New ids are generated; isInner is computed. Accepts dry_run; logged with change_id.',
			array(
				'post_id'   => self::post_id_property(),
				'parent_id' => self::element_id_property( 'Id of the parent element; empty or omitted = top level.' ),
				'position'  => array( 'type' => 'integer', 'description' => '0-based position among the siblings. Omit to append.' ),
				'element'   => self::element_property(),
			),
			array( 'post_id', 'element' ),
			'execute_elementor_add',
			true,
			false,
			false
		);
	}

	/**
	 * Register craynai/elementor-update.
	 *
	 * @return void
	 */
	private static function register_update() {
		self::define(
			'craynai/elementor-update',
			'Update Elementor element settings',
			'Merge settings into an element by id: each given key replaces that setting as a whole value (no deep merge); other settings keep their original bytes; keys in remove_settings are dropped. Warns about keys that are not controls of the element. V4 atomic elements are refused. Accepts dry_run; logged with change_id.',
			array(
				'post_id'         => self::post_id_property(),
				'element_id'      => self::element_id_property(),
				'settings'        => array( 'type' => 'object', 'description' => 'Settings to set, e.g. {"title":"New","align":"center"}. Names: elementor-widgets with the widget name.' ),
				'remove_settings' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Setting names to remove.' ),
			),
			array( 'post_id', 'element_id' ),
			'execute_elementor_update',
			true,
			false,
			false
		);
	}

	/**
	 * Register craynai/elementor-move.
	 *
	 * @return void
	 */
	private static function register_move() {
		self::define(
			'craynai/elementor-move',
			'Move Elementor element',
			'Move an element (with its subtree) under another parent id, or to the top level when parent_id is empty. position is among the new siblings after the element was taken out; omitted = append. Moving into the element itself or its descendants is refused; placement rules apply and isInner follows the new nesting. Works for V4 atomic elements too (the data is moved unchanged). Accepts dry_run; logged with change_id.',
			array(
				'post_id'    => self::post_id_property(),
				'element_id' => self::element_id_property( 'Id of the element to move.' ),
				'parent_id'  => self::element_id_property( 'Id of the new parent; empty or omitted = top level.' ),
				'position'   => array( 'type' => 'integer', 'description' => '0-based position among the new siblings. Omit to append.' ),
			),
			array( 'post_id', 'element_id' ),
			'execute_elementor_move',
			true,
			false,
			false
		);
	}

	/**
	 * Register craynai/elementor-remove.
	 *
	 * @return void
	 */
	private static function register_remove() {
		self::define(
			'craynai/elementor-remove',
			'Remove Elementor element',
			'Remove an element (and its children) by id. Nothing is hard deleted: the whole previous data stays in the change log and is fully restorable. Accepts dry_run; logged with change_id.',
			array(
				'post_id'    => self::post_id_property(),
				'element_id' => self::element_id_property(),
			),
			array( 'post_id', 'element_id' ),
			'execute_elementor_remove',
			true,
			true,
			true
		);
	}

	/**
	 * Register craynai/elementor-duplicate.
	 *
	 * @return void
	 */
	private static function register_duplicate() {
		self::define(
			'craynai/elementor-duplicate',
			'Duplicate Elementor element',
			'Duplicate an element with its whole subtree right after the original, inside the same parent. Every element of the copy gets a fresh unique id. Subtrees with V4 atomic elements are refused (their local style ids are derived from the element ids). Accepts dry_run; logged with change_id.',
			array(
				'post_id'    => self::post_id_property(),
				'element_id' => self::element_id_property(),
			),
			array( 'post_id', 'element_id' ),
			'execute_elementor_duplicate',
			true,
			false,
			false
		);
	}

	/**
	 * Register craynai/elementor-replace.
	 *
	 * @return void
	 */
	private static function register_replace() {
		self::define(
			'craynai/elementor-replace',
			'Replace Elementor page tree',
			'Replace the whole element tree of a page with the given list (e.g. the "tree" of elementor-get after editing). Existing ids are kept; elements without id get new ones; elements and members that did not change keep their original bytes. Every widget is validated against the registry and the placement rules. V4 atomic elements may only appear unchanged. Use for bulk rewrites; per-element tools are safer. Accepts dry_run; logged with change_id.',
			array(
				'post_id' => self::post_id_property(),
				'tree'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Full list of top-level elements, each {id?, elType, widgetType?, settings?, elements?, isInner?}.' ),
			),
			array( 'post_id', 'tree' ),
			'execute_elementor_replace',
			true,
			true,
			false
		);
	}

	/**
	 * Register craynai/elementor-page-settings.
	 *
	 * @return void
	 */
	private static function register_page_settings() {
		self::define(
			'craynai/elementor-page-settings',
			'Set Elementor page settings',
			'Merge values into the Elementor page settings (meta _elementor_page_settings: page layout, background, padding, custom CSS ...) of a page built with Elementor: given keys replace that setting as a whole value, keys in remove_settings are dropped. Settings that Elementor stores on the post itself (post_title, post_status, template, post_excerpt, post_featured_image, menu_order, comment_status) are refused; use the post tools. Clears the generated page CSS; Elementor rebuilds it on the next page view. Accepts dry_run; logged with change_id.',
			array(
				'post_id'         => self::post_id_property(),
				'settings'        => array( 'type' => 'object', 'description' => 'Page settings to set.' ),
				'remove_settings' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Page setting names to remove.' ),
			),
			array( 'post_id' ),
			'execute_elementor_page_settings',
			true,
			false,
			true
		);
	}

	/**
	 * Register craynai/elementor-enable.
	 *
	 * @return void
	 */
	private static function register_enable() {
		self::define(
			'craynai/elementor-enable',
			'Enable Elementor for a post',
			'Turn a post/page into an Elementor page: sets _elementor_edit_mode to "builder", the document type and version, and an empty or existing data meta. Refuses when the post has content and no Elementor data, because the front end would stop showing that content, unless convert_content is true: then the content is put into a text-editor widget (or a shortcode widget when it is only one shortcode), as Elementor does. post_content itself is left untouched. Accepts dry_run; logged with change_id.',
			array(
				'post_id'         => self::post_id_property(),
				'convert_content' => array( 'type' => 'boolean', 'description' => 'Put the existing post content into a text-editor widget. Default false.', 'default' => false ),
			),
			array( 'post_id' ),
			'execute_elementor_enable',
			true,
			false,
			true
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	/**
	 * Permission for read abilities.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission_read( $input = array() ) {
		if ( ! self::is_loaded() ) {
			return false;
		}
		$post_id = ( is_array( $input ) && isset( $input['post_id'] ) ) ? (int) $input['post_id'] : 0;
		$can     = self::can_edit_post_or_missing( $post_id );
		return $can && null === Safety::guard( 'craynai-content', false );
	}

	/**
	 * Permission for write abilities (post and Elementor caps are re-checked in execute).
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission_write( $input = array() ) {
		return self::permission_read( $input );
	}

	// ---------------------------------------------------------------------
	// Runtime helpers
	// ---------------------------------------------------------------------

	/**
	 * Are Elementor's runtime registries ready? They are created on "init"
	 * (includes/plugin.php:826, init_components() at 681), so this can only be asked at execute time.
	 *
	 * @return \WP_Error|null
	 */
	private static function runtime_error() {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) || ! is_object( \Elementor\Plugin::$instance ) ) {
			return new \WP_Error( 'craynai_elementor_not_ready', 'Elementor is not loaded.' );
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( empty( $plugin->widgets_manager ) || empty( $plugin->elements_manager ) || empty( $plugin->documents ) ) {
			return new \WP_Error( 'craynai_elementor_not_ready', 'Elementor is loaded but its widget/element registries are not initialized yet (they are created on "init"). Retry the call.' );
		}
		return null;
	}

	/**
	 * Load a post and check the current user may edit it, with Elementor's own
	 * rules (editing role blacklist, supported post types, posts page / shop page,
	 * includes/user.php:100-135).
	 *
	 * @param int  $post_id Post id.
	 * @param bool $elementor_rules Also apply Elementor's edit rules (writes).
	 * @return \WP_Post|\WP_Error
	 */
	private static function get_editable_post( $post_id, $elementor_rules ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'craynai_post_id_required', 'A valid post_id is required.' );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'craynai_post_not_found', sprintf( 'Post %d does not exist.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'You cannot edit this content.' );
		}
		if ( $elementor_rules ) {
			$runtime = self::runtime_error();
			if ( is_wp_error( $runtime ) ) {
				return $runtime;
			}
			if ( class_exists( '\Elementor\User' ) && ! \Elementor\User::is_current_user_can_edit( $post_id ) ) {
				return new \WP_Error( 'craynai_elementor_not_editable', 'Elementor does not allow the current user to edit this post (post type not enabled for Elementor, your role is excluded, the post is in the trash, or it is the posts / shop page).' );
			}
			if ( 'revision' === $post->post_type || 'trash' === $post->post_status ) {
				return new \WP_Error( 'craynai_elementor_not_editable', 'Revisions and trashed posts cannot be edited.' );
			}
		}
		return $post;
	}

	/**
	 * Refuse a write while another user has the page open in an editor: their
	 * autosave would overwrite (or be overwritten by) this change.
	 *
	 * @param int $post_id Post id.
	 * @return \WP_Error|null
	 */
	private static function lock_error( $post_id ) {
		$user = self::locked_user( $post_id );
		if ( null === $user ) {
			return null;
		}
		return new \WP_Error( 'craynai_elementor_locked', sprintf( 'The page is being edited by %s right now. Try again in a few minutes (the lock expires when they close the editor).', $user ) );
	}

	/**
	 * Display name of another user who holds the edit lock, or null.
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	private static function locked_user( $post_id ) {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			self::load_admin_includes( array( 'post.php' ) );
		}
		$uid = wp_check_post_lock( (int) $post_id );
		if ( ! $uid ) {
			return null;
		}
		$user = get_user_by( 'id', (int) $uid );
		return $user ? $user->display_name : 'another user';
	}

	/**
	 * Raw value of a meta key as a string, or null when the key does not exist.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return string|null
	 */
	private static function meta_raw( $post_id, $key ) {
		if ( ! metadata_exists( 'post', (int) $post_id, $key ) ) {
			return null;
		}
		$value = get_post_meta( (int) $post_id, $key, true );
		return is_string( $value ) ? $value : maybe_serialize( $value );
	}

	/**
	 * Everything Elementor writes for a post: used for before/after and revert.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,string|null>
	 */
	private static function state( $post_id ) {
		return array(
			'data'          => self::meta_raw( $post_id, self::META_DATA ),
			'page_settings' => self::meta_raw( $post_id, self::META_PAGE ),
			'edit_mode'     => self::meta_raw( $post_id, self::META_MODE ),
			'version'       => self::meta_raw( $post_id, self::META_VERSION ),
			'template_type' => self::meta_raw( $post_id, self::META_TYPE ),
			'post_content'  => (string) get_post_field( 'post_content', (int) $post_id, 'raw' ),
		);
	}

	/**
	 * Current snapshot of a post for the change-revert log row. Null when gone.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function current_snapshot( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return null;
		}
		return self::state( $post_id );
	}

	/**
	 * Parse the data meta of a post.
	 *
	 * @param array $state State from state().
	 * @return array|string Tree or error message.
	 */
	private static function parse_state( array $state ) {
		return ElementorTree::parse( null === $state['data'] ? '' : $state['data'] );
	}

	/**
	 * Does the post currently use Elementor?
	 *
	 * @param array $state State.
	 * @return bool
	 */
	private static function uses_elementor( array $state ) {
		return 'builder' === $state['edit_mode'];
	}

	/**
	 * Error for write tools on a post that is not built with Elementor.
	 *
	 * @param int $post_id Post id.
	 * @return \WP_Error
	 */
	private static function not_enabled_error( $post_id ) {
		return new \WP_Error( 'craynai_elementor_not_enabled', sprintf( 'Post %d is not built with Elementor. Run craynai/elementor-enable first.', (int) $post_id ) );
	}

	/**
	 * Can this state be stored in the change log and read back identical?
	 * wp_json_encode() silently repairs invalid UTF-8, which would make a revert
	 * differ from the original bytes, so a lossy encode counts as "not loggable".
	 *
	 * @param array $state State.
	 * @return bool
	 */
	private static function loggable( array $state ) {
		$encoded = wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) && json_decode( $encoded, true ) === $state;
	}

	/**
	 * Strip markup the current user may not save, the way Elementor does on save
	 * (core/base/document.php:856-858: users without unfiltered_html get kses).
	 *
	 * @param mixed $data Input data.
	 * @return mixed
	 */
	private static function kses_input( $data ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $data;
		}
		return map_deep(
			$data,
			static function ( $value ) {
				return is_string( $value ) ? wp_kses_post( $value ) : $value;
			}
		);
	}

	/**
	 * Is a widget object an upsell placeholder (Pro widget promotion)?
	 *
	 * @param object $widget Widget instance.
	 * @return bool
	 */
	private static function is_promotion( $widget ) {
		return false !== stripos( get_class( $widget ), 'promotion' );
	}

	// ---------------------------------------------------------------------
	// Registry validation
	// ---------------------------------------------------------------------

	/**
	 * Check one NEW (non-atomic) node against Elementor's registries.
	 *
	 * @param array      $node   Node.
	 * @param array|null $parent Parent node.
	 * @return string|null Error message.
	 */
	private static function registry_error( array $node, $parent ) {
		if ( ElementorTree::is_atomic( $node ) ) {
			return sprintf( 'V4 atomic elements ("%s") cannot be created or changed with this tool; they use typed props and local styles. Edit them in the Elementor editor.', 'widget' === $node['elType'] ? $node['widgetType'] : $node['elType'] );
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( 'widget' === $node['elType'] ) {
			$widget = in_array( $node['widgetType'], self::INTERNAL_WIDGETS, true ) ? null : $plugin->widgets_manager->get_widget_types( $node['widgetType'] );
			if ( ! $widget ) {
				return sprintf( 'Widget "%s" is not registered on this site (check elementor-widgets; a Pro or third-party widget needs its plugin active).', $node['widgetType'] );
			}
			if ( self::is_promotion( $widget ) ) {
				return sprintf( 'Widget "%s" is only a promotion placeholder here (it needs Elementor Pro).', $node['widgetType'] );
			}
		} elseif ( ! $plugin->elements_manager->get_element_types( $node['elType'] ) ) {
			return sprintf( 'Element type "%s" is not available on this site (the Container needs Elementor\'s Container feature to be active).', $node['elType'] );
		}
		return self::atomic_parent_error( $node, $parent );
	}

	/**
	 * Atomic parents list the child types they accept (allowed_child_types in their
	 * element config, modules/atomic-widgets/elements/base/atomic-element-base.php:115).
	 *
	 * @param array      $node   Child node.
	 * @param array|null $parent Parent node.
	 * @return string|null
	 */
	private static function atomic_parent_error( array $node, $parent ) {
		if ( null === $parent || 0 !== strpos( (string) $parent['elType'], 'e-' ) ) {
			return null;
		}
		$type = \Elementor\Plugin::$instance->elements_manager->get_element_types( $parent['elType'] );
		if ( ! $type ) {
			return sprintf( 'The parent element type "%s" is not available on this site.', $parent['elType'] );
		}
		$config  = $type->get_config();
		$allowed = isset( $config['allowed_child_types'] ) && is_array( $config['allowed_child_types'] ) ? $config['allowed_child_types'] : array();
		if ( array() === $allowed ) {
			return null;
		}
		$child_type = '' !== $node['widgetType'] ? $node['widgetType'] : $node['elType'];
		if ( ! in_array( $child_type, $allowed, true ) ) {
			return sprintf( '"%s" is not allowed inside "%s" (allowed: %s).', $child_type, $parent['elType'], implode( ', ', $allowed ) );
		}
		return null;
	}

	/**
	 * Registry-check a whole new subtree (every non-reused node).
	 *
	 * @param array      $node    Node.
	 * @param array|null $parent  Parent node.
	 * @param bool       $reusing True to skip nodes that carry their original bytes (replace).
	 * @return string|null
	 */
	private static function validate_subtree( array $node, $parent, $reusing ) {
		if ( $reusing && null !== $node['src'] ) {
			return null; // Unchanged bytes of an existing element: nothing new to validate.
		}
		if ( $reusing && ElementorTree::is_atomic( $node ) ) {
			return sprintf( 'The V4 atomic element "%s" (%s) changed. V4 elements can only be kept unchanged, moved or removed with these tools; edit them in the Elementor editor.', $node['id'], $node['elType'] );
		}
		$error = self::registry_error( $node, $parent );
		if ( null !== $error ) {
			return $error;
		}
		foreach ( ElementorTree::children( $node ) as $child ) {
			$error = self::validate_subtree( $child, $node, $reusing );
			if ( null !== $error ) {
				return $error;
			}
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Executes: reads
	// ---------------------------------------------------------------------

	/**
	 * Compact view of a control for elementor-widgets.
	 *
	 * @param array $control Control array from the stack.
	 * @param bool  $with_fields Include repeater fields.
	 * @return array
	 */
	private static function compact_control( array $control, $with_fields = true ) {
		$out = array(
			'name'  => isset( $control['name'] ) ? (string) $control['name'] : '',
			'type'  => isset( $control['type'] ) ? (string) $control['type'] : '',
			'label' => isset( $control['label'] ) && is_scalar( $control['label'] ) ? (string) $control['label'] : '',
		);
		if ( isset( $control['tab'] ) ) {
			$out['tab'] = (string) $control['tab'];
		}
		if ( isset( $control['section'] ) ) {
			$out['section'] = (string) $control['section'];
		}
		if ( array_key_exists( 'default', $control ) && '' !== $control['default'] && array() !== $control['default'] ) {
			$out['default'] = $control['default'];
		}
		if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
			$options = array();
			foreach ( $control['options'] as $value => $label ) {
				if ( is_array( $label ) ) {
					$label = isset( $label['title'] ) ? $label['title'] : ( isset( $label['label'] ) ? $label['label'] : '' );
				}
				$options[ (string) $value ] = is_scalar( $label ) ? (string) $label : '';
				if ( count( $options ) >= 40 ) {
					$out['options_truncated'] = true;
					break;
				}
			}
			$out['options'] = $options;
		}
		if ( ! empty( $control['size_units'] ) && is_array( $control['size_units'] ) ) {
			$out['size_units'] = array_values( $control['size_units'] );
		}
		if ( ! empty( $control['responsive'] ) ) {
			$out['responsive'] = true;
		}
		if ( ! empty( $control['condition'] ) && is_array( $control['condition'] ) ) {
			$out['condition'] = $control['condition'];
		}
		if ( $with_fields && 'repeater' === $out['type'] && ! empty( $control['fields'] ) && is_array( $control['fields'] ) ) {
			$fields = array();
			foreach ( $control['fields'] as $field ) {
				if ( is_array( $field ) && ! in_array( isset( $field['type'] ) ? $field['type'] : '', array( 'section', 'tab', 'raw_html', 'heading', 'divider' ), true ) ) {
					$fields[] = self::compact_control( $field, false );
				}
			}
			$out['fields'] = $fields;
			if ( ! empty( $control['title_field'] ) ) {
				$out['title_field'] = (string) $control['title_field'];
			}
		}
		return $out;
	}

	/**
	 * Execute elementor-widgets.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_widgets( $input ) {
		$guard = Safety::guard( 'craynai-content', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$runtime = self::runtime_error();
		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}
		$plugin  = \Elementor\Plugin::$instance;
		$widgets = $plugin->widgets_manager->get_widget_types();
		$widgets = is_array( $widgets ) ? $widgets : array();

		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		if ( '' !== $name ) {
			if ( ! isset( $widgets[ $name ] ) ) {
				return new \WP_Error( 'craynai_widget_unknown', sprintf( 'Widget "%s" is not registered. List the available names without the "name" argument.', $name ) );
			}
			$widget = $widgets[ $name ];
			$tab    = isset( $input['tab'] ) ? strtolower( (string) $input['tab'] ) : 'content';
			// get_stack( false ) = the widget's own controls; get_stack() adds the common controls
			// (includes/base/widget-base.php:185-199).
			$stack    = ! empty( $input['include_common'] ) ? $widget->get_stack() : $widget->get_stack( false );
			$controls = isset( $stack['controls'] ) && is_array( $stack['controls'] ) ? $stack['controls'] : array();
			// On non-editor, non-REST requests Elementor keeps style controls in a separate list
			// (core/frontend/performance.php:20, includes/managers/controls.php:812).
			if ( isset( $stack['style_controls'] ) && is_array( $stack['style_controls'] ) ) {
				$controls += $stack['style_controls'];
			}
			$skip     = array( 'section', 'tab', 'raw_html', 'heading', 'divider', 'alert', 'notice', 'deprecated_notice' );
			$counts   = array();
			$list     = array();
			foreach ( $controls as $control ) {
				if ( ! is_array( $control ) || in_array( isset( $control['type'] ) ? $control['type'] : '', $skip, true ) ) {
					continue;
				}
				$ctab            = isset( $control['tab'] ) ? (string) $control['tab'] : '';
				$counts[ $ctab ] = isset( $counts[ $ctab ] ) ? $counts[ $ctab ] + 1 : 1;
				if ( 'all' !== $tab && $ctab !== $tab ) {
					continue;
				}
				$list[] = self::compact_control( $control );
			}
			return array(
				'total'  => 1,
				'items'  => array(),
				'widget' => array(
					'name'       => $name,
					'title'      => (string) $widget->get_title(),
					'categories' => array_values( (array) $widget->get_categories() ),
					'atomic'     => ElementorTree::is_atomic( 'widget', $name ),
					'promotion'  => self::is_promotion( $widget ),
					'tab'        => $tab,
					'controls_per_tab' => $counts,
					'controls'   => $list,
				),
			);
		}

		$search   = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
		$category = isset( $input['category'] ) ? strtolower( (string) $input['category'] ) : '';
		$with_wp  = ! empty( $input['include_wp_widgets'] );
		$items    = array();
		foreach ( $widgets as $key => $widget ) {
			$key = (string) $key;
			if ( ! $with_wp && 0 === strpos( $key, 'wp-widget-' ) ) {
				continue;
			}
			if ( in_array( $key, self::INTERNAL_WIDGETS, true ) || self::is_promotion( $widget ) ) {
				continue; // Control holders, the inner section "widget" and Pro upsell placeholders cannot be placed.
			}
			$categories = array_values( (array) $widget->get_categories() );
			if ( '' !== $category && ! in_array( $category, array_map( 'strtolower', $categories ), true ) ) {
				continue;
			}
			$title = (string) $widget->get_title();
			if ( '' !== $search ) {
				$hay = strtolower( $key . ' ' . $title . ' ' . implode( ' ', array_map( 'strval', (array) $widget->get_keywords() ) ) );
				if ( false === strpos( $hay, $search ) ) {
					continue;
				}
			}
			$row = array(
				'name'       => $key,
				'title'      => $title,
				'categories' => $categories,
			);
			if ( ElementorTree::is_atomic( 'widget', $key ) ) {
				$row['atomic'] = true;
			}
			$items[] = $row;
		}

		$total  = count( $items );
		$limit  = isset( $input['limit'] ) ? max( 1, min( 300, (int) $input['limit'] ) ) : 100;
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

		$elements = array();
		foreach ( (array) $plugin->elements_manager->get_element_types() as $key => $type ) {
			$elements[] = array(
				'name'   => (string) $key,
				'title'  => (string) $type->get_title(),
				'atomic' => ElementorTree::is_atomic( (string) $key ),
			);
		}

		return array(
			'total'    => $total,
			'items'    => array_slice( $items, $offset, $limit ),
			'elements' => $elements,
		);
	}

	/**
	 * Execute elementor-get.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_get( $input ) {
		$guard = Safety::guard( 'craynai-content', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0, false );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$state = self::state( $post->ID );
		$out   = array(
			'post_id'        => (int) $post->ID,
			'post_type'      => (string) $post->post_type,
			'post_status'    => (string) $post->post_status,
			'uses_elementor' => self::uses_elementor( $state ),
			'has_data'       => null !== $state['data'] && '' !== trim( $state['data'] ) && '[]' !== trim( $state['data'] ),
			'document_type'  => (string) $state['template_type'],
			'saved_with'     => (string) $state['version'],
			'active_version' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
			'data_length'    => null === $state['data'] ? 0 : strlen( $state['data'] ),
			'has_atomic'     => false,
			'container_available' => false,
			'outline'        => array(),
			'tree'           => array(),
			'page_settings'  => (object) array(),
		);

		$runtime = self::runtime_error();
		if ( ! is_wp_error( $runtime ) ) {
			$out['container_available'] = (bool) \Elementor\Plugin::$instance->elements_manager->get_element_types( 'container' );
		}
		$locked = self::locked_user( $post->ID );
		if ( null !== $locked ) {
			$out['locked_by'] = $locked;
		}

		if ( null !== $state['page_settings'] ) {
			$settings = is_serialized( $state['page_settings'] ) ? unserialize( $state['page_settings'], array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Reads the plugin's own page settings meta; objects are not allowed.
			if ( is_array( $settings ) && array() !== $settings ) {
				$out['page_settings'] = $settings;
			}
		}

		$tree = self::parse_state( $state );
		if ( is_string( $tree ) ) {
			$out['data_error'] = $tree;
			return $out;
		}
		$out['has_atomic'] = ElementorTree::tree_has_atomic( $tree );
		$out['outline']    = ElementorTree::outline( $tree );
		if ( ! isset( $input['include_tree'] ) || ! empty( $input['include_tree'] ) ) {
			$out['tree'] = ElementorTree::to_array( $tree );
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Write pipeline
	// ---------------------------------------------------------------------

	/**
	 * Common start of every tree-changing write: guard, post, Elementor state, lock, parsed tree.
	 *
	 * @param array $input Validated input.
	 * @param bool  $dry   Dry run flag.
	 * @return array|\WP_Error { post, state, tree }
	 */
	private static function begin_write( array $input, $dry ) {
		$guard = Safety::guard( 'craynai-content', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0, true );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$state = self::state( $post->ID );
		if ( ! self::uses_elementor( $state ) ) {
			return self::not_enabled_error( $post->ID );
		}
		if ( ! $dry ) {
			$lock = self::lock_error( $post->ID );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
		}
		$tree = self::parse_state( $state );
		if ( is_string( $tree ) ) {
			return new \WP_Error( 'craynai_elementor_data_invalid', $tree . ' The page data is left untouched.' );
		}
		return array(
			'post'  => $post,
			'state' => $state,
			'tree'  => $tree,
		);
	}

	/**
	 * Compact view of a state for write results: lengths and outline only.
	 *
	 * @param array $state   State.
	 * @param array $outline Outline.
	 * @return array
	 */
	private static function state_view( array $state, array $outline ) {
		return array(
			'outline'        => $outline,
			'data_length'    => null === $state['data'] ? 0 : strlen( $state['data'] ),
			'post_content_length' => strlen( (string) $state['post_content'] ),
		);
	}

	/**
	 * Persist a new tree: write the data meta, refresh what Elementor's own save
	 * regenerates, log the change. A tree whose bytes equal the stored data is a
	 * no-op (change_id 0). Dry runs write nothing and call no Elementor code that writes.
	 *
	 * @param \WP_Post $post       Post.
	 * @param array    $state      State before.
	 * @param array    $old_tree   Tree before.
	 * @param array    $new_tree   Tree after.
	 * @param string   $ability    Ability name.
	 * @param string   $note       Log note.
	 * @param bool     $dry        Dry run.
	 * @param array    $extra      Extra output keys.
	 * @param array    $warnings   Warnings.
	 * @return array|\WP_Error
	 */
	private static function apply_tree( \WP_Post $post, array $state, array $old_tree, array $new_tree, $ability, $note, $dry, array $extra = array(), array $warnings = array() ) {
		$new_raw        = ElementorTree::encode( $new_tree );
		$old_outline    = ElementorTree::outline( $old_tree );
		$new_outline    = ElementorTree::outline( $new_tree );
		$result_base    = array(
			'post_id'  => (int) $post->ID,
			'outline'  => $new_outline,
			'warnings' => $warnings,
		) + $extra;

		if ( null !== $state['data'] && $state['data'] === $new_raw ) {
			return array_merge(
				$result_base,
				array(
					'dry_run'   => (bool) $dry,
					'before'    => self::state_view( $state, $old_outline ),
					'after'     => self::state_view( $state, $old_outline ),
					'change_id' => 0,
				)
			);
		}

		$after_view            = $state;
		$after_view['data']    = $new_raw;
		if ( $dry ) {
			return array_merge(
				$result_base,
				array(
					'dry_run'   => true,
					'before'    => self::state_view( $state, $old_outline ),
					'after'     => self::state_view( $after_view, $new_outline ),
					'change_id' => 0,
				)
			);
		}

		if ( ! self::loggable( $state ) ) {
			return new \WP_Error( 'craynai_elementor_not_loggable', sprintf( 'The current Elementor data or content of post %d is not valid UTF-8 and cannot be stored in the change log, so nothing was changed.', (int) $post->ID ) );
		}

		$write = self::write_data( (int) $post->ID, $new_raw );
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$mirror_warning = self::refresh_after_save( $post, $state, $new_tree, true );
		if ( null !== $mirror_warning ) {
			$result_base['warnings'][] = $mirror_warning;
		}

		$after     = self::state( $post->ID );
		$change_id = ChangeLogger::log( $ability, 'post', (int) $post->ID, $state, $after, $note );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'The change was applied to post %d (%s) but could not be logged.', (int) $post->ID, $note ) );
		}

		return array_merge(
			$result_base,
			array(
				'dry_run'   => false,
				'before'    => self::state_view( $state, $old_outline ),
				'after'     => self::state_view( $after, $new_outline ),
				'change_id' => (int) $change_id,
			)
		);
	}

	/**
	 * Write the raw data meta and verify it reads back byte for byte.
	 * wp_slash() is required: update_post_meta() unslashes its input, which would
	 * strip every backslash of the JSON (Elementor does the same,
	 * core/base/document.php:1383-1387).
	 *
	 * @param int    $post_id Post id.
	 * @param string $raw     Raw JSON text.
	 * @return true|\WP_Error
	 */
	private static function write_data( $post_id, $raw ) {
		update_post_meta( $post_id, self::META_DATA, wp_slash( $raw ) ); // False when the value is unchanged; verified by reading back.
		if ( get_post_meta( $post_id, self::META_DATA, true ) !== $raw ) {
			return new \WP_Error( 'craynai_elementor_write_failed', sprintf( 'The Elementor data of post %d could not be written (the stored value differs from what was sent).', (int) $post_id ) );
		}
		return true;
	}

	/**
	 * Refresh what Elementor's Document::save() regenerates after the data meta
	 * changed (core/base/document.php:812-900, save()): template type, version, the
	 * post CSS file/meta, the element cache and, for V4 content, the atomic style
	 * cache; optionally the post_content plain-text mirror (includes/db.php:225-243).
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $state    State before (decides whether V4 caches need clearing).
	 * @param array    $tree     Tree after.
	 * @param bool     $mirror   Regenerate the post_content mirror.
	 * @return string|null Warning text, or null.
	 */
	private static function refresh_after_save( \WP_Post $post, array $state, array $tree, $mirror ) {
		$post_id = (int) $post->ID;
		$warning = null;

		if ( null === self::meta_raw( $post_id, self::META_TYPE ) ) {
			$type = 'wp-' . ( 'page' === $post->post_type ? 'page' : 'post' );
			try {
				$doc = \Elementor\Plugin::$instance->documents->get( $post_id );
				if ( $doc ) {
					$type = (string) $doc->get_name();
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Keep the fallback type.
			}
			update_post_meta( $post_id, self::META_TYPE, wp_slash( $type ) );
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, self::META_VERSION, wp_slash( (string) ELEMENTOR_VERSION ) );
		}

		if ( $mirror ) {
			$text = self::plain_text( $tree );
			if ( null === $text ) {
				$warning = 'The post_content plain-text mirror could not be regenerated (a widget failed to render); post_content was left as it was.';
			} elseif ( $text !== (string) $state['post_content'] ) {
				$updated = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $text ) ), true );
				if ( is_wp_error( $updated ) ) {
					$warning = 'The post_content plain-text mirror could not be saved: ' . $updated->get_error_message();
				}
			}
		}

		$atomic = ElementorTree::tree_has_atomic( $tree );
		if ( ! $atomic && null !== $state['data'] ) {
			$old = ElementorTree::parse( $state['data'] );
			$atomic = is_array( $old ) && ElementorTree::tree_has_atomic( $old );
		}
		self::invalidate_caches( $post_id, $atomic );
		return $warning;
	}

	/**
	 * Delete the generated CSS, the element cache and the V4 style cache of a post,
	 * the same invalidation Document::save() does. The CSS file is rebuilt by Elementor on the next view.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $atomic  The page uses V4 elements.
	 * @return void
	 */
	private static function invalidate_caches( $post_id, $atomic ) {
		$deleted = false;
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( (int) $post_id )->delete();
				$deleted = true;
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall back to deleting the meta below.
			}
		}
		if ( ! $deleted ) {
			delete_post_meta( $post_id, self::META_CSS );
		}
		delete_post_meta( $post_id, self::META_CACHE );
		if ( $atomic ) {
			// Same action Atomic_Widget_Styles fires after a save (modules/atomic-widgets/styles/atomic-widget-styles.php:102-120).
			do_action( 'elementor/atomic-widgets/styles/clear', array( 'local', (int) $post_id ) );
		}
	}

	/**
	 * Plain text of a tree, as Elementor stores it in post_content on save
	 * (includes/db.php:225-243, 591-613). Null when rendering failed.
	 *
	 * @param array $tree Tree.
	 * @return string|null
	 */
	private static function plain_text( array $tree ) {
		if ( ! isset( \Elementor\Plugin::$instance->db ) || ! method_exists( \Elementor\Plugin::$instance->db, 'get_plain_text_from_data' ) ) {
			return null;
		}
		$dynamic = isset( \Elementor\Plugin::$instance->dynamic_tags ) ? \Elementor\Plugin::$instance->dynamic_tags : null;
		$mode    = null;
		if ( $dynamic && method_exists( $dynamic, 'get_parsing_mode' ) && class_exists( '\Elementor\Core\DynamicTags\Manager' ) ) {
			$mode = $dynamic->get_parsing_mode();
			$dynamic->set_parsing_mode( \Elementor\Core\DynamicTags\Manager::MODE_REMOVE );
		}
		$level = ob_get_level();
		$text  = null;
		try {
			$text = (string) \Elementor\Plugin::$instance->db->get_plain_text_from_data( ElementorTree::to_array( $tree ) );
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$text = null;
		}
		if ( $dynamic && null !== $mode ) {
			$dynamic->set_parsing_mode( $mode );
		}
		return $text;
	}

	// ---------------------------------------------------------------------
	// Executes: tree writes
	// ---------------------------------------------------------------------

	/**
	 * Execute elementor-add.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_add( $input ) {
		$dry = Safety::dry_run( $input );
		$ctx = self::begin_write( $input, $dry );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		if ( ! isset( $input['element'] ) || ! is_array( $input['element'] ) ) {
			return new \WP_Error( 'craynai_element_required', 'The "element" input is required.' );
		}
		$parent_id = isset( $input['parent_id'] ) ? trim( (string) $input['parent_id'] ) : '';
		$position  = ( isset( $input['position'] ) && is_numeric( $input['position'] ) ) ? max( 0, (int) $input['position'] ) : null;

		$res = ElementorTree::insert( $ctx['tree'], $parent_id, $position, self::kses_input( $input['element'] ) );
		if ( is_string( $res ) ) {
			return new \WP_Error( 'craynai_placement_denied', $res );
		}
		$node = ElementorTree::get( $res['tree'], $res['id'] );
		$path = ElementorTree::find( $res['tree'], $res['id'] );
		if ( null === $node || null === $path ) {
			return new \WP_Error( 'craynai_elementor_internal', 'The new element could not be located after the insert.' );
		}
		$parent_node = null === ElementorTree::parent_id( $res['tree'], $path ) ? null : ElementorTree::get( $res['tree'], (string) ElementorTree::parent_id( $res['tree'], $path ) );
		$error       = self::validate_subtree( $node, $parent_node, false );
		if ( null !== $error ) {
			return new \WP_Error( 'craynai_element_invalid', $error );
		}

		$extra = array( 'element_id' => $res['id'] );
		if ( $dry ) {
			$extra['element'] = ElementorTree::to_array_node( $node );
		}
		return self::apply_tree( $ctx['post'], $ctx['state'], $ctx['tree'], $res['tree'], 'craynai/elementor-add', sprintf( 'element %s added under %s', $res['id'], '' === $parent_id ? 'the top level' : $parent_id ), $dry, $extra );
	}

	/**
	 * Execute elementor-update.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_update( $input ) {
		$dry = Safety::dry_run( $input );
		$ctx = self::begin_write( $input, $dry );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$id   = isset( $input['element_id'] ) ? trim( (string) $input['element_id'] ) : '';
		$node = '' === $id ? null : ElementorTree::get( $ctx['tree'], $id );
		if ( null === $node ) {
			return new \WP_Error( 'craynai_element_not_found', sprintf( 'Element "%s" does not exist on this page. Use elementor-get for current ids.', $id ) );
		}
		if ( ElementorTree::is_atomic( $node ) ) {
			return new \WP_Error( 'craynai_elementor_atomic_unsupported', sprintf( 'Element "%s" is a V4 atomic element (%s). Its settings are typed props with local styles and cannot be edited with this tool; use the Elementor editor.', $id, 'widget' === $node['elType'] ? $node['widgetType'] : $node['elType'] ) );
		}

		$set    = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? self::kses_input( $input['settings'] ) : array();
		$remove = ( isset( $input['remove_settings'] ) && is_array( $input['remove_settings'] ) ) ? array_map( 'strval', $input['remove_settings'] ) : array();
		if ( array() === $set && array() === $remove ) {
			return new \WP_Error( 'craynai_nothing_to_do', 'Give "settings" and/or "remove_settings".' );
		}
		if ( array() !== $set && array_keys( $set ) === range( 0, count( $set ) - 1 ) ) {
			return new \WP_Error( 'craynai_settings_invalid', 'settings must be an object (name => value), not a list.' );
		}

		$warnings = self::unknown_setting_warnings( $node, array_merge( array_keys( $set ), $remove ) );
		$new      = ElementorTree::update_settings( $ctx['tree'], $id, $set, $remove );
		if ( is_string( $new ) ) {
			return new \WP_Error( 'craynai_settings_invalid', $new );
		}
		return self::apply_tree( $ctx['post'], $ctx['state'], $ctx['tree'], $new, 'craynai/elementor-update', sprintf( 'settings of element %s updated (%s)', $id, implode( ', ', array_merge( array_map( 'strval', array_keys( $set ) ), array_map( static function ( $k ) { return '-' . $k; }, $remove ) ) ) ), $dry, array(), $warnings );
	}

	/**
	 * Warn about setting names that are not controls of the element. Elementor
	 * stores unknown keys without complaint; this only helps catch typos.
	 *
	 * @param array    $node  Node.
	 * @param string[] $names Setting names.
	 * @return string[]
	 */
	private static function unknown_setting_warnings( array $node, array $names ) {
		$warnings = array();
		try {
			$plugin = \Elementor\Plugin::$instance;
			if ( 'widget' === $node['elType'] ) {
				$type = $plugin->widgets_manager->get_widget_types( $node['widgetType'] );
				if ( ! $type ) {
					return array( sprintf( 'Widget "%s" is not registered on this site; its settings were not checked.', $node['widgetType'] ) );
				}
				$stack = $type->get_stack();
			} else {
				$type = $plugin->elements_manager->get_element_types( $node['elType'] );
				if ( ! $type ) {
					return array();
				}
				$stack = $type->get_stack();
			}
			$controls = isset( $stack['controls'] ) && is_array( $stack['controls'] ) ? $stack['controls'] : array();
			foreach ( $names as $name ) {
				$name = (string) $name;
				if ( ! isset( $controls[ $name ] ) ) {
					$warnings[] = sprintf( '"%s" is not a control of %s; it is stored but Elementor will ignore it.', $name, 'widget' === $node['elType'] ? $node['widgetType'] : $node['elType'] );
				}
			}
		} catch ( \Throwable $e ) {
			return array( 'The settings could not be checked against the controls of the element: ' . $e->getMessage() );
		}
		return $warnings;
	}

	/**
	 * Execute elementor-move.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_move( $input ) {
		$dry = Safety::dry_run( $input );
		$ctx = self::begin_write( $input, $dry );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$id        = isset( $input['element_id'] ) ? trim( (string) $input['element_id'] ) : '';
		$parent_id = isset( $input['parent_id'] ) ? trim( (string) $input['parent_id'] ) : '';
		$position  = ( isset( $input['position'] ) && is_numeric( $input['position'] ) ) ? max( 0, (int) $input['position'] ) : null;

		$node = '' === $id ? null : ElementorTree::get( $ctx['tree'], $id );
		if ( null === $node ) {
			return new \WP_Error( 'craynai_element_not_found', sprintf( 'Element "%s" does not exist on this page. Use elementor-get for current ids.', $id ) );
		}
		$new = ElementorTree::move( $ctx['tree'], $id, $parent_id, $position );
		if ( is_string( $new ) ) {
			return new \WP_Error( 'craynai_move_failed', $new );
		}
		if ( '' !== $parent_id ) {
			$parent_node = ElementorTree::get( $new, $parent_id );
			$error       = null === $parent_node ? null : self::atomic_parent_error( $node, $parent_node );
			if ( null !== $error ) {
				return new \WP_Error( 'craynai_placement_denied', $error );
			}
		}
		return self::apply_tree( $ctx['post'], $ctx['state'], $ctx['tree'], $new, 'craynai/elementor-move', sprintf( 'element %s moved to %s', $id, '' === $parent_id ? 'the top level' : $parent_id ), $dry );
	}

	/**
	 * Execute elementor-remove.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_remove( $input ) {
		$dry = Safety::dry_run( $input );
		$ctx = self::begin_write( $input, $dry );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$id = isset( $input['element_id'] ) ? trim( (string) $input['element_id'] ) : '';
		if ( '' === $id || null === ElementorTree::get( $ctx['tree'], $id ) ) {
			return new \WP_Error( 'craynai_element_not_found', sprintf( 'Element "%s" does not exist on this page. Use elementor-get for current ids.', $id ) );
		}
		$new = ElementorTree::remove( $ctx['tree'], $id );
		if ( is_string( $new ) ) {
			return new \WP_Error( 'craynai_remove_failed', $new );
		}
		return self::apply_tree( $ctx['post'], $ctx['state'], $ctx['tree'], $new, 'craynai/elementor-remove', sprintf( 'element %s removed', $id ), $dry );
	}

	/**
	 * Execute elementor-duplicate.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_duplicate( $input ) {
		$dry = Safety::dry_run( $input );
		$ctx = self::begin_write( $input, $dry );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$id   = isset( $input['element_id'] ) ? trim( (string) $input['element_id'] ) : '';
		$node = '' === $id ? null : ElementorTree::get( $ctx['tree'], $id );
		if ( null === $node ) {
			return new \WP_Error( 'craynai_element_not_found', sprintf( 'Element "%s" does not exist on this page. Use elementor-get for current ids.', $id ) );
		}
		if ( ElementorTree::contains_atomic( $node ) ) {
			return new \WP_Error( 'craynai_elementor_atomic_unsupported', sprintf( 'Element "%s" contains V4 atomic elements. Their local style ids are derived from the element id (modules/atomic-widgets/import-export/modifiers/styles-ids-modifier.php), so a plain copy would share styles with the original. Duplicate it in the Elementor editor.', $id ) );
		}
		$res = ElementorTree::duplicate( $ctx['tree'], $id );
		if ( is_string( $res ) ) {
			return new \WP_Error( 'craynai_duplicate_failed', $res );
		}
		return self::apply_tree( $ctx['post'], $ctx['state'], $ctx['tree'], $res['tree'], 'craynai/elementor-duplicate', sprintf( 'element %s duplicated as %s', $id, $res['id'] ), $dry, array( 'element_id' => $res['id'] ) );
	}

	/**
	 * Execute elementor-replace.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_replace( $input ) {
		$dry = Safety::dry_run( $input );
		$ctx = self::begin_write( $input, $dry );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		if ( ! isset( $input['tree'] ) || ! is_array( $input['tree'] ) ) {
			return new \WP_Error( 'craynai_tree_required', 'The "tree" input (a list of elements) is required.' );
		}
		$new = ElementorTree::from_array( self::kses_input( $input['tree'] ), $ctx['tree'] );
		if ( is_string( $new ) ) {
			return new \WP_Error( 'craynai_tree_invalid', $new );
		}
		foreach ( $new['nodes'] as $node ) {
			$error = self::validate_subtree( $node, null, true );
			if ( null !== $error ) {
				return new \WP_Error( 'craynai_element_invalid', $error );
			}
		}
		return self::apply_tree( $ctx['post'], $ctx['state'], $ctx['tree'], $new, 'craynai/elementor-replace', 'full element tree replaced', $dry );
	}

	// ---------------------------------------------------------------------
	// Executes: page settings and enable
	// ---------------------------------------------------------------------

	/**
	 * Execute elementor-page-settings.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_page_settings( $input ) {
		$dry = Safety::dry_run( $input );
		$ctx = self::begin_write( $input, $dry );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$post   = $ctx['post'];
		$state  = $ctx['state'];
		$set    = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? self::kses_input( $input['settings'] ) : array();
		$remove = ( isset( $input['remove_settings'] ) && is_array( $input['remove_settings'] ) ) ? array_map( 'strval', $input['remove_settings'] ) : array();
		if ( array() === $set && array() === $remove ) {
			return new \WP_Error( 'craynai_nothing_to_do', 'Give "settings" and/or "remove_settings".' );
		}
		foreach ( array_merge( array_map( 'strval', array_keys( $set ) ), $remove ) as $key ) {
			if ( in_array( $key, self::SPECIAL_PAGE_SETTINGS, true ) ) {
				return new \WP_Error( 'craynai_page_setting_not_allowed', sprintf( '"%s" is not stored in the Elementor page settings (Elementor keeps it on the post itself). Use the post tools for it.', $key ) );
			}
			if ( '' === $key ) {
				return new \WP_Error( 'craynai_settings_invalid', 'A page setting name cannot be empty.' );
			}
		}

		$current = array();
		if ( null !== $state['page_settings'] ) {
			$parsed = is_serialized( $state['page_settings'] ) ? unserialize( $state['page_settings'], array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Reads the Elementor page settings meta; objects are not allowed.
			$current = is_array( $parsed ) ? $parsed : array();
		}
		$merged = $current;
		foreach ( $remove as $key ) {
			unset( $merged[ $key ] );
		}
		foreach ( $set as $key => $value ) {
			$merged[ (string) $key ] = $value;
		}

		$tree    = self::parse_state( $state );
		$outline = is_array( $tree ) ? ElementorTree::outline( $tree ) : array();
		$base    = array(
			'post_id'  => (int) $post->ID,
			'outline'  => $outline,
			'warnings' => array(),
		);

		$after_serialized = array() === $merged ? null : maybe_serialize( $merged );
		if ( $after_serialized === $state['page_settings'] ) {
			return array_merge( $base, array( 'dry_run' => (bool) $dry, 'before' => array( 'page_settings' => (object) $current ), 'after' => array( 'page_settings' => (object) $current ), 'change_id' => 0 ) );
		}
		if ( $dry ) {
			return array_merge( $base, array( 'dry_run' => true, 'before' => array( 'page_settings' => (object) $current ), 'after' => array( 'page_settings' => (object) $merged ), 'change_id' => 0 ) );
		}
		if ( ! self::loggable( $state ) ) {
			return new \WP_Error( 'craynai_elementor_not_loggable', sprintf( 'The current Elementor data or content of post %d is not valid UTF-8 and cannot be stored in the change log, so nothing was changed.', (int) $post->ID ) );
		}

		// Same storage as Elementor's page settings manager (core/settings/page/manager.php:204-212).
		if ( array() === $merged ) {
			delete_post_meta( $post->ID, self::META_PAGE );
		} else {
			update_post_meta( $post->ID, self::META_PAGE, wp_slash( $merged ) );
		}
		if ( self::meta_raw( $post->ID, self::META_PAGE ) !== $after_serialized ) {
			return new \WP_Error( 'craynai_elementor_write_failed', sprintf( 'The page settings of post %d could not be written.', (int) $post->ID ) );
		}
		// The page settings feed the page CSS; Document::save() drops it and the element cache.
		self::invalidate_caches( (int) $post->ID, is_array( $tree ) && ElementorTree::tree_has_atomic( $tree ) );

		$after     = self::state( $post->ID );
		$change_id = ChangeLogger::log( 'craynai/elementor-page-settings', 'post', (int) $post->ID, $state, $after, 'Elementor page settings changed (' . implode( ', ', array_merge( array_map( 'strval', array_keys( $set ) ), array_map( static function ( $k ) { return '-' . $k; }, $remove ) ) ) . ')' );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'The Elementor page settings of post %d were changed but could not be logged.', (int) $post->ID ) );
		}
		return array_merge( $base, array( 'dry_run' => false, 'before' => array( 'page_settings' => (object) $current ), 'after' => array( 'page_settings' => (object) $merged ), 'change_id' => (int) $change_id ) );
	}

	/**
	 * Execute elementor-enable.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_elementor_enable( $input ) {
		$dry   = Safety::dry_run( $input );
		$guard = Safety::guard( 'craynai-content', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0, true );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! class_exists( '\Elementor\Utils' ) || ! \Elementor\Utils::is_post_type_support( $post->post_type ) ) {
			return new \WP_Error( 'craynai_elementor_post_type', sprintf( 'The post type "%s" is not enabled for Elementor (Elementor > Settings > General > Post Types).', $post->post_type ) );
		}
		$state   = self::state( $post->ID );
		$convert = ! empty( $input['convert_content'] );
		$base    = array(
			'post_id'  => (int) $post->ID,
			'warnings' => array(),
		);

		if ( self::uses_elementor( $state ) ) {
			$tree = self::parse_state( $state );
			$out  = is_array( $tree ) ? ElementorTree::outline( $tree ) : array();
			return array_merge( $base, array( 'dry_run' => (bool) $dry, 'outline' => $out, 'before' => self::state_view( $state, $out ), 'after' => self::state_view( $state, $out ), 'change_id' => 0 ) );
		}
		if ( ! $dry ) {
			$lock = self::lock_error( $post->ID );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
		}

		$new_raw = $state['data'];
		$tree    = self::parse_state( $state );
		if ( is_string( $tree ) ) {
			return new \WP_Error( 'craynai_elementor_data_invalid', $tree . ' The post is left untouched.' );
		}
		$before_outline = ElementorTree::outline( $tree );
		$has_data       = array() !== $tree['nodes'];

		if ( ! $has_data ) {
			$content = (string) $post->post_content;
			if ( '' !== trim( $content ) ) {
				if ( ! $convert ) {
					return new \WP_Error( 'craynai_elementor_content_would_be_lost', sprintf( 'Post %d has content but no Elementor data. Once Elementor is enabled the front end shows the (empty) Elementor page instead of that content. Pass convert_content: true to put the content into a text-editor widget, or leave the post alone.', (int) $post->ID ) );
				}
				$converted = self::converted_tree( $content );
				if ( is_wp_error( $converted ) ) {
					return $converted;
				}
				$tree = $converted;
			}
			$new_raw = ElementorTree::encode( $tree );
			if ( array() === $tree['nodes'] ) {
				$new_raw = '[]';
			}
		}

		$after_view                  = $state;
		$after_view['data']          = $new_raw;
		$after_view['edit_mode']     = 'builder';
		$after_view['version']       = defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : $state['version'];
		$after_view['template_type'] = null === $state['template_type'] ? '(set from the document type)' : $state['template_type'];
		$outline                     = ElementorTree::outline( $tree );

		if ( $dry ) {
			return array_merge( $base, array( 'dry_run' => true, 'outline' => $outline, 'before' => self::state_view( $state, $before_outline ), 'after' => self::state_view( $after_view, $outline ), 'change_id' => 0 ) );
		}
		if ( ! self::loggable( $state ) ) {
			return new \WP_Error( 'craynai_elementor_not_loggable', sprintf( 'The current Elementor data or content of post %d is not valid UTF-8 and cannot be stored in the change log, so nothing was changed.', (int) $post->ID ) );
		}

		if ( $new_raw !== $state['data'] ) {
			$write = self::write_data( (int) $post->ID, (string) $new_raw );
			if ( is_wp_error( $write ) ) {
				return $write;
			}
		}
		// "builder" string, not a boolean, for rollback compatibility (core/base/document.php:954-960).
		update_post_meta( $post->ID, self::META_MODE, 'builder' );
		// post_content is not mirrored here: the content stays exactly as it was.
		$warning = self::refresh_after_save( $post, $state, $tree, false );
		$out_base = $base;
		if ( null !== $warning ) {
			$out_base['warnings'][] = $warning;
		}

		$after     = self::state( $post->ID );
		$change_id = ChangeLogger::log( 'craynai/elementor-enable', 'post', (int) $post->ID, $state, $after, 'Elementor enabled' . ( $convert && ! $has_data && '' !== trim( (string) $post->post_content ) ? ' (content converted into a widget)' : '' ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Elementor was enabled for post %d but the change could not be logged.', (int) $post->ID ) );
		}
		return array_merge( $out_base, array( 'dry_run' => false, 'outline' => $outline, 'before' => self::state_view( $state, $before_outline ), 'after' => self::state_view( $after, $outline ), 'change_id' => (int) $change_id ) );
	}

	/**
	 * Tree Elementor would build for a post that only has post_content
	 * (core/base/document.php:1182-1230, convert_to_elementor()): one text-editor
	 * widget, or one shortcode widget when the content is a single shortcode, inside
	 * a container (or a section with one column when the Container is not available).
	 *
	 * @param string $content Post content.
	 * @return array|\WP_Error Tree.
	 */
	private static function converted_tree( $content ) {
		$runtime = self::runtime_error();
		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}
		$plugin      = \Elementor\Plugin::$instance;
		$widget_name = 'text-editor';
		$settings    = array( 'editor' => $content );
		preg_match_all( '/' . get_shortcode_regex() . '/', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $shortcode ) {
			if ( trim( $content ) === $shortcode[0] ) {
				$widget_name = 'shortcode';
				$settings    = array( 'shortcode' => $content );
				break;
			}
		}
		if ( ! $plugin->widgets_manager->get_widget_types( $widget_name ) ) {
			return new \WP_Error( 'craynai_widget_unknown', sprintf( 'The "%s" widget needed to hold the content is not registered.', $widget_name ) );
		}
		$widget = array(
			'elType'     => 'widget',
			'widgetType' => $widget_name,
			'settings'   => self::kses_input( $settings ),
		);
		if ( $plugin->elements_manager->get_element_types( 'container' ) ) {
			$element = array( 'elType' => 'container', 'elements' => array( $widget ) );
		} else {
			$element = array(
				'elType'   => 'section',
				'elements' => array(
					array(
						'elType'   => 'column',
						'elements' => array( $widget ),
					),
				),
			);
		}
		$res = ElementorTree::insert( array( 'nodes' => array(), 'src' => '' ), null, null, $element );
		if ( is_string( $res ) ) {
			return new \WP_Error( 'craynai_elementor_internal', $res );
		}
		return $res['tree'];
	}

	// ---------------------------------------------------------------------
	// Revert (dispatched from ChangeAbilities)
	// ---------------------------------------------------------------------

	/**
	 * Restore a logged Elementor change: the whole snapshot of the before state
	 * (data meta byte for byte, page settings, edit mode, version, template type,
	 * post_content). Refuses with craynai_revert_conflict when anything of the
	 * snapshot changed after the logged write.
	 *
	 * @param array $row Change log row (raw columns).
	 * @return string|\WP_Error Message on success.
	 */
	public static function revert( array $row ) {
		$before  = ChangeLogger::decode( isset( $row['before_data'] ) ? $row['before_data'] : '' );
		$after   = ChangeLogger::decode( isset( $row['after_data'] ) ? $row['after_data'] : '' );
		$keys    = array( 'data', 'page_settings', 'edit_mode', 'version', 'template_type', 'post_content' );
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $before ) || ! array_key_exists( $key, $after ) ) {
				return new \WP_Error( 'craynai_revert_no_snapshot', 'The change log row has no complete Elementor snapshot to restore.' );
			}
		}
		$post_id = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'craynai_post_not_found', sprintf( 'Post %d does not exist anymore; nothing to restore.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'craynai_permission_denied', 'You cannot edit this content.' );
		}
		$runtime = self::runtime_error();
		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}

		$current = self::state( $post_id );
		foreach ( $keys as $key ) {
			if ( $current[ $key ] !== $after[ $key ] ) {
				return new \WP_Error(
					'craynai_revert_conflict',
					sprintf( 'Post %d changed after this edit (%s differs from what the edit left). Revert the newer changes first (see changes-list), newest first.', $post_id, $key )
				);
			}
		}
		$lock = self::lock_error( $post_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		// Data meta, byte for byte.
		if ( null === $before['data'] ) {
			delete_post_meta( $post_id, self::META_DATA );
		} else {
			update_post_meta( $post_id, self::META_DATA, wp_slash( (string) $before['data'] ) );
		}
		// Page settings.
		if ( null === $before['page_settings'] ) {
			delete_post_meta( $post_id, self::META_PAGE );
		} else {
			$value = is_serialized( (string) $before['page_settings'] ) ? unserialize( (string) $before['page_settings'], array( 'allowed_classes' => false ) ) : (string) $before['page_settings']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Snapshot written by this plugin; objects are not allowed.
			update_post_meta( $post_id, self::META_PAGE, wp_slash( $value ) );
		}
		// Simple string metas.
		$metas = array(
			'edit_mode'     => self::META_MODE,
			'version'       => self::META_VERSION,
			'template_type' => self::META_TYPE,
		);
		foreach ( $metas as $key => $meta_key ) {
			if ( null === $before[ $key ] ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, wp_slash( (string) $before[ $key ] ) );
			}
		}

		$notes = array();
		if ( (string) $before['post_content'] !== $current['post_content'] ) {
			$updated = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => (string) $before['post_content'] ) ), true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$atomic = false;
		foreach ( array( $before['data'], $current['data'] ) as $raw ) {
			if ( is_string( $raw ) ) {
				$parsed = ElementorTree::parse( $raw );
				if ( is_array( $parsed ) && ElementorTree::tree_has_atomic( $parsed ) ) {
					$atomic = true;
				}
			}
		}
		self::invalidate_caches( $post_id, $atomic );

		// Verify the restore.
		$now = self::state( $post_id );
		foreach ( array( 'data', 'page_settings', 'edit_mode', 'version', 'template_type' ) as $key ) {
			if ( $now[ $key ] !== $before[ $key ] ) {
				return new \WP_Error( 'craynai_elementor_restore_failed', sprintf( 'Restoring "%s" of post %d did not read back identical. The change stays marked as not reverted; retry or check the post.', $key, $post_id ) );
			}
		}
		if ( $now['post_content'] !== (string) $before['post_content'] ) {
			$notes[] = 'post_content was restored but WordPress filtered it on save (it differs from the snapshot)';
		}

		return sprintf( 'Elementor data of post %d restored byte for byte (data, page settings, edit mode, version, type, content).%s', $post_id, array() === $notes ? '' : ' Note: ' . implode( '; ', $notes ) . '.' );
	}
}
