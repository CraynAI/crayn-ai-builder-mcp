<?php
/**
 * WPBakery (js_composer) abilities: read and safely edit pages built with
 * WPBakery shortcodes via a path-addressed shortcode tree.
 *
 * Requires the WPBakery plugin (WPB_VC_VERSION) for element discovery and
 * placement rules; the tree itself is handled by ShortcodeTree (pure PHP).
 * Every write supports dry_run, is logged via ChangeLogger (with
 * post_content and the WPBakery custom CSS metas in before/after) and can
 * be reverted through WpbakeryAbilities::revert().
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "crayn-content" WPBakery abilities.
 */
class WpbakeryAbilities extends AbilitiesBase {

	/**
	 * Elements whose inner value is base64(rawurlencode()) encoded.
	 *
	 * @var array<int,string>
	 */
	const RAW_CONTENT_TAGS = array( 'vc_raw_html', 'vc_raw_js' );

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		return array(
			'crayn-safe/wpbakery-elements',
			'crayn-safe/wpbakery-get',
			'crayn-safe/wpbakery-add',
			'crayn-safe/wpbakery-update',
			'crayn-safe/wpbakery-move',
			'crayn-safe/wpbakery-remove',
			'crayn-safe/wpbakery-replace',
			'crayn-safe/wpbakery-enable',
			'crayn-safe/wpbakery-page-css',
		);
	}

	/**
	 * Register every WPBakery ability (only when WPBakery is active).
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! defined( 'WPB_VC_VERSION' ) ) {
			return;
		}
		self::register_elements();
		self::register_get();
		self::register_add();
		self::register_update();
		self::register_move();
		self::register_remove();
		self::register_replace();
		self::register_enable();
		self::register_page_css();
	}

	/**
	 * Schema fragment: post id.
	 *
	 * @return array<string,mixed>
	 */
	private static function post_id_property() {
		return array(
			'type'        => 'integer',
			'description' => 'Post/page ID whose content is edited.',
		);
	}

	/**
	 * Schema fragment: a path of 0-based indexes over shortcode children only
	 * (whitespace-only text between tags is ignored).
	 *
	 * @return array<string,mixed>
	 */
	private static function path_property( $label = 'Path' ) {
		return array(
			'type'        => 'array',
			'description' => $label . ' of 0-based indexes over shortcode children only, e.g. [1,0,2] = 2nd row -> 1st column -> 3rd element. Empty array = top level. Get paths from the outline/tree of wpbakery-get.',
			'items'       => array( 'type' => 'integer' ),
		);
	}

	/**
	 * Schema fragment: recursive element object used by wpbakery-add.
	 *
	 * @return array<string,mixed>
	 */
	private static function element_property() {
		return array(
			'type'        => 'object',
			'description' => 'Element to add: {tag, attrs, content, children}. "attrs" takes human-readable values (a link param may also be given as an object {url,title,target,rel}). "content" is the inner text (for vc_raw_html/vc_raw_js pass the raw HTML/JS, it is encoded automatically). "children" are the same shape (recursive).',
			'properties'  => array(
				'tag'      => array( 'type' => 'string', 'description' => 'Element base name, e.g. "vc_row". "#text" adds a text node.' ),
				'attrs'    => array( 'type' => 'object', 'description' => 'Attribute values (decoded/human-readable on input and output).' ),
				'content'  => array( 'type' => 'string', 'description' => 'Inner content of leaf elements (e.g. the HTML of vc_column_text).' ),
				'children' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Child elements, same shape (e.g. columns inside a row).' ),
			),
			'required'    => array( 'tag' ),
		);
	}

	/**
	 * crayn-safe/wpbakery-elements
	 *
	 * @return void
	 */
	private static function register_elements() {
		wp_register_ability(
			'crayn-safe/wpbakery-elements',
			array(
				'label'              => 'List WPBakery elements',
				'description'        => 'List available WPBakery elements with container flags and placement rules. Params are only included with "base" (one element in full: param_name, type, heading, options, default), which keeps the list small. Use "search"/"category" to filter.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Filter by base/name/description (case-insensitive substring).' ),
						'category' => array( 'type' => 'string', 'description' => 'Filter by exact WPBakery category (case-insensitive).' ),
						'base'     => array( 'type' => 'string', 'description' => 'Return a single element by base name (overrides search/category).' ),
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
									'base'         => array( 'type' => 'string' ),
									'name'         => array( 'type' => 'string' ),
									'category'     => array( 'type' => 'string' ),
									'description'  => array( 'type' => 'string' ),
									'is_container' => array( 'type' => 'boolean' ),
									'as_parent'    => array( 'type' => 'string' ),
									'params'       => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'param_name' => array( 'type' => 'string' ),
												'type'       => array( 'type' => 'string' ),
												'heading'    => array( 'type' => 'string' ),
												'options'    => array( 'type' => 'array', 'description' => 'Allowed {value,label} pairs. Store the value, not the label.' ),
												'default'    => array( 'type' => 'string' ),
											),
										),
									),
								),
							),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_wpbakery_elements' ),
				'permission_callback' => array( self::class, 'permission_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, true, 'List WPBakery Elements' ) ),
			)
		);
	}

	/**
	 * crayn-safe/wpbakery-get
	 *
	 * @return void
	 */
	private static function register_get() {
		wp_register_ability(
			'crayn-safe/wpbakery-get',
			array(
				'label'              => 'Get WPBakery page',
				'description'        => 'Get a page built with WPBakery: uses_wpbakery flag, outline (path/tag/preview lines per element), the full shortcode tree as JSON with decoded attribute values (extra "link" and "decoded" keys show parsed links, raw HTML and param groups) and the WPBakery custom CSS values. The tree can be sent back to wpbakery-replace.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => self::post_id_property(),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array( 'type' => 'integer' ),
						'uses_wpbakery'  => array( 'type' => 'boolean' ),
						'editor_status'  => array( 'type' => 'string' ),
						'outline'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'tree'           => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Full shortcode tree (list of nodes) with decoded values.' ),
						'custom_css'     => array(
							'type'       => 'object',
							'properties' => array(
								'shortcodes_css' => array( 'type' => 'string', 'description' => 'Meta _wpb_shortcodes_custom_css (design options of the elements).' ),
								'default_css'    => array( 'type' => 'string', 'description' => 'Meta _wpb_shortcodes_default_css (generated element defaults).' ),
								'post_css'       => array( 'type' => 'string', 'description' => 'Meta _wpb_post_custom_css (custom CSS of the page).' ),
							),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_wpbakery_get' ),
				'permission_callback' => array( self::class, 'permission_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, true, 'Get WPBakery Page' ) ),
			)
		);
	}

	/**
	 * crayn-safe/wpbakery-add
	 *
	 * @return void
	 */
	private static function register_add() {
		wp_register_ability(
			'crayn-safe/wpbakery-add',
			array(
				'label'              => 'Add WPBakery element',
				'description'        => 'Add an element (optionally with pre-built children) into a WPBakery page at a path. parent_path [] = top level; index omitted = append. Validates the element exists in WPBakery and its placement (rows/columns/sections). Accepts dry_run; logged with change_id.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => self::post_id_property(),
						'parent_path'    => self::path_property( 'Path of the parent container' ),
						'index'          => array( 'type' => 'integer', 'description' => 'Addressable position inside the parent (0-based). Omit to append.' ),
						'element'        => self::element_property(),
						'values_encoded' => array(
							'type'        => 'boolean',
							'description' => 'When true, attr values and content are passed already WPBakery-encoded (base64 for raw html / pre-built link strings) and are stored as-is. Default false.',
							'default'     => false,
						),
						'dry_run'        => Safety::dry_run_property(),
					),
					'required'   => array( 'post_id', 'element' ),
				),
				'output_schema'      => self::change_output_schema( self::outline_output() ),
				'execute_callback'   => array( self::class, 'execute_wpbakery_add' ),
				'permission_callback' => array( self::class, 'permission_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Add WPBakery Element' ) ),
			)
		);
	}

	/**
	 * crayn-safe/wpbakery-update
	 *
	 * @return void
	 */
	private static function register_update() {
		wp_register_ability(
			'crayn-safe/wpbakery-update',
			array(
				'label'              => 'Update WPBakery element',
				'description'        => 'Update the element at a path in a WPBakery page: merge attrs, remove attrs and/or replace its content. Only given fields change. Accepts dry_run; logged with change_id.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => self::post_id_property(),
						'path'           => self::path_property(),
						'attrs'          => array( 'type' => 'object', 'description' => 'Attributes to set/merge (human-readable values; link params can be objects).' ),
						'remove_attrs'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Attribute names to remove.' ),
						'content'        => array( 'type' => 'string', 'description' => 'New inner content (for vc_column_text etc.; may itself contain shortcodes).' ),
						'values_encoded' => array( 'type' => 'boolean', 'description' => 'When true, attr values and content are stored as given (already encoded). Default false.', 'default' => false ),
						'dry_run'        => Safety::dry_run_property(),
					),
					'required'   => array( 'post_id', 'path' ),
				),
				'output_schema'      => self::change_output_schema( self::outline_output() ),
				'execute_callback'   => array( self::class, 'execute_wpbakery_update' ),
				'permission_callback' => array( self::class, 'permission_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Update WPBakery Element' ) ),
			)
		);
	}

	/**
	 * crayn-safe/wpbakery-move
	 *
	 * @return void
	 */
	private static function register_move() {
		wp_register_ability(
			'crayn-safe/wpbakery-move',
			array(
				'label'              => 'Move WPBakery element',
				'description'        => 'Move a subtree to another parent path (or top level) at an optional position. Moving into the moved subtree itself is rejected and WPBakery placement rules apply. Accepts dry_run; logged with change_id.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => self::post_id_property(),
						'from_path'      => self::path_property( 'Path of the element to move' ),
						'to_parent_path' => self::path_property( 'Path of the target parent container' ),
						'index'          => array( 'type' => 'integer', 'description' => 'Position inside the target (0-based). Omit to append.' ),
						'dry_run'        => Safety::dry_run_property(),
					),
					'required'   => array( 'post_id', 'from_path', 'to_parent_path' ),
				),
				'output_schema'      => self::change_output_schema( self::outline_output() ),
				'execute_callback'   => array( self::class, 'execute_wpbakery_move' ),
				'permission_callback' => array( self::class, 'permission_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Move WPBakery Element' ) ),
			)
		);
	}

	/**
	 * crayn-safe/wpbakery-remove
	 *
	 * @return void
	 */
	private static function register_remove() {
		wp_register_ability(
			'crayn-safe/wpbakery-remove',
			array(
				'label'              => 'Remove WPBakery element',
				'description'        => 'Remove the element (and its children) at a path from a WPBakery page. Nothing is hard deleted: the whole previous content stays in the change log and is fully restorable. Accepts dry_run; logged with change_id.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => self::post_id_property(),
						'path'     => self::path_property(),
						'dry_run'  => Safety::dry_run_property(),
					),
					'required'   => array( 'post_id', 'path' ),
				),
				'output_schema'      => self::change_output_schema( self::outline_output() ),
				'execute_callback'   => array( self::class, 'execute_wpbakery_remove' ),
				'permission_callback' => array( self::class, 'permission_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, true, true, 'Remove WPBakery Element' ) ),
			)
		);
	}

	/**
	 * crayn-safe/wpbakery-replace
	 *
	 * @return void
	 */
	private static function register_replace() {
		wp_register_ability(
			'crayn-safe/wpbakery-replace',
			array(
				'label'              => 'Replace WPBakery content',
				'description'        => 'Replace the complete WPBakery shortcode content of a page in one call: pass either "content" (full shortcode string as stored) or "tree" (the full node list, e.g. from wpbakery-get tree). Use for bulk rewrites; per-element edits are better with add/update/move/remove. Accepts dry_run; logged with change_id.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => self::post_id_property(),
						'content'        => array( 'type' => 'string', 'description' => 'Full shortcode content as stored (encoded values).' ),
						'tree'           => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Full shortcode tree (list of nodes) to serialize instead of "content".' ),
						'values_encoded' => array( 'type' => 'boolean', 'description' => 'When true, values in a passed tree are stored as given. Default false.', 'default' => false ),
						'dry_run'        => Safety::dry_run_property(),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'      => self::change_output_schema( self::outline_output() ),
				'execute_callback'   => array( self::class, 'execute_wpbakery_replace' ),
				'permission_callback' => array( self::class, 'permission_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Replace WPBakery Content' ) ),
			)
		);
	}

	/**
	 * crayn-safe/wpbakery-enable
	 *
	 * @return void
	 */
	private static function register_enable() {
		wp_register_ability(
			'crayn-safe/wpbakery-enable',
			array(
				'label'              => 'Enable WPBakery editor for a page',
				'description'        => "Set the post meta _wpb_vc_js_status to 'true' so the backend/frontend editor opens the page in WPBakery mode. Logged with change_id.",
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => self::post_id_property(),
						'dry_run'  => Safety::dry_run_property(),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'      => self::change_output_schema( self::outline_output() ),
				'execute_callback'   => array( self::class, 'execute_wpbakery_enable' ),
				'permission_callback' => array( self::class, 'permission_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, true, 'Enable WPBakery Editor' ) ),
			)
		);
	}

	/**
	 * crayn-safe/wpbakery-page-css
	 *
	 * @return void
	 */
	private static function register_page_css() {
		wp_register_ability(
			'crayn-safe/wpbakery-page-css',
			array(
				'label'              => 'Set WPBakery page CSS',
				'description'        => 'Set the custom CSS of a WPBakery page (meta _wpb_post_custom_css, exactly what the WPBakery editor writes). An empty "css" string clears the CSS. "</style" sequences are stripped so the value cannot break out of the <style> block it is shown in. Accepts dry_run; logged with change_id.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => self::post_id_property(),
						'css'     => array( 'type' => 'string', 'description' => 'The custom CSS of the page (as typed in the WPBakery editor). Empty string clears it.' ),
						'dry_run' => Safety::dry_run_property(),
					),
					'required'   => array( 'post_id', 'css' ),
				),
				'output_schema'      => self::change_output_schema( self::outline_output() ),
				'execute_callback'   => array( self::class, 'execute_wpbakery_page_css' ),
				'permission_callback' => array( self::class, 'permission_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, true, 'Set WPBakery Page CSS' ) ),
			)
		);
	}

	/**
	 * Extra output fields shared by the write abilities.
	 *
	 * @return array<string,mixed>
	 */
	private static function outline_output() {
		return array(
			'post_id' => array( 'type' => 'integer' ),
			'outline' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		);
	}

	/**
	 * Permission for read-type WPBakery abilities.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission_read( $input = array() ) {
		if ( ! defined( 'WPB_VC_VERSION' ) ) {
			return false;
		}
		$post_id = ( is_array( $input ) && isset( $input['post_id'] ) ) ? (int) $input['post_id'] : 0;
		$can     = self::can_edit_post_or_missing( $post_id );
		return $can && null === Safety::guard( 'crayn-content', false );
	}

	/**
	 * Permission generic for WPBakery writes (post caps re-checked in execute).
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission_write( $input = array() ) {
		return self::permission_read( $input );
	}

	// ---------------------------------------------------------------------
	// Executes
	// ---------------------------------------------------------------------

	/**
	 * Execute wpbakery-elements.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_wpbakery_elements( $input ) {
		$guard = Safety::guard( 'crayn-content', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$catalog = self::elements_catalog();
		$items   = array();

		$base = isset( $input['base'] ) ? (string) $input['base'] : '';
		if ( '' !== $base ) {
			if ( ! isset( $catalog[ $base ] ) ) {
				return new \WP_Error( 'crayn_safe_element_unknown', sprintf( 'WPBakery element "%s" is not registered.', $base ) );
			}
			$items[] = self::element_item( $base, $catalog[ $base ], true );
			return array( 'total' => 1, 'items' => $items );
		}

		$search   = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
		$category = isset( $input['category'] ) ? strtolower( (string) $input['category'] ) : '';

		foreach ( $catalog as $base_key => $entry ) {
			$item = self::element_item( $base_key, is_array( $entry ) ? $entry : array(), false );
			if ( '' !== $category && strtolower( (string) $item['category'] ) !== $category ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystack = strtolower( $item['base'] . ' ' . $item['name'] . ' ' . $item['description'] );
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}
			$items[] = $item;
		}

		return array( 'total' => count( $items ), 'items' => $items );
	}

	/**
	 * Execute wpbakery-get.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_wpbakery_get( $input ) {
		$guard = Safety::guard( 'crayn-content', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$content = (string) $post->post_content;
		$tree    = ShortcodeTree::parse( $content );

		return array(
			'post_id'        => (int) $post->ID,
			'uses_wpbakery'  => self::uses_wpbakery( $post ),
			'editor_status'  => (string) get_post_meta( $post->ID, '_wpb_vc_js_status', true ),
			'outline'        => ShortcodeTree::outline( $tree ),
			'tree'           => self::readable_tree( $tree ),
			'custom_css'     => self::custom_css( $post->ID ),
		);
	}

	/**
	 * Execute wpbakery-add.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_wpbakery_add( $input ) {
		$dry = Safety::dry_run( $input );
		$guard = Safety::guard( 'crayn-content', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! isset( $input['element'] ) || ! is_array( $input['element'] ) ) {
			return new \WP_Error( 'crayn_safe_element_required', 'The "element" input is required.' );
		}

		$tree = ShortcodeTree::parse( (string) $post->post_content );
		$node = self::prepare_node( $input['element'], ! empty( $input['values_encoded'] ) );
		if ( is_wp_error( $node ) ) {
			return $node;
		}

		$parent_path = self::int_path( isset( $input['parent_path'] ) && is_array( $input['parent_path'] ) ? $input['parent_path'] : array() );
		if ( null === $parent_path ) {
			return new \WP_Error( 'crayn_safe_path_invalid', 'parent_path must be an array of non-negative integers.' );
		}
		$placement   = self::check_placement( $node, $tree, $parent_path, true );
		if ( is_wp_error( $placement ) ) {
			return $placement;
		}

		$index = isset( $input['index'] ) ? (int) $input['index'] : null;
		$new   = ShortcodeTree::insert_node( $tree, $parent_path, $index, $node );
		if ( null === $new ) {
			return new \WP_Error( 'crayn_safe_path_not_found', 'The parent_path does not point to an element in this page. Re-run wpbakery-get for current paths.' );
		}

		return self::apply_tree( $post, $new, 'crayn-safe/wpbakery-add', 'element added at path ' . self::path_str( $parent_path ) . ( null !== $index ? ':' . $index : ':append' ), $dry );
	}

	/**
	 * Execute wpbakery-update.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_wpbakery_update( $input ) {
		$dry = Safety::dry_run( $input );
		$guard = Safety::guard( 'crayn-content', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$path = self::int_path( isset( $input['path'] ) && is_array( $input['path'] ) ? $input['path'] : array() );
		if ( null === $path ) {
			return new \WP_Error( 'crayn_safe_path_invalid', 'path must be an array of non-negative integers.' );
		}
		if ( array() === $path ) {
			return new \WP_Error( 'crayn_safe_path_required', 'A non-empty "path" is required (the whole page can be replaced with wpbakery-replace).' );
		}

		$tree       = ShortcodeTree::parse( (string) $post->post_content );
		$node       = ShortcodeTree::get_node( $tree, $path );
		$attrs      = ( isset( $input['attrs'] ) && is_array( $input['attrs'] ) ) ? $input['attrs'] : array();
		$content    = isset( $input['content'] ) ? (string) $input['content'] : null;
		$remove     = ( isset( $input['remove_attrs'] ) && is_array( $input['remove_attrs'] ) ) ? $input['remove_attrs'] : array();

		// Prepare human-readable values (link objects, raw html encoding ...)
		// against the element's own param types.
		$tag     = is_array( $node ) ? (string) $node['tag'] : '';
		$merged  = self::prepare_attrs( $tag, $attrs, ! empty( $input['values_encoded'] ) );
		$content = self::prepare_content( $tag, $content, ! empty( $input['values_encoded'] ) );

		$new = ShortcodeTree::update_node( $tree, $path, $merged, $content, $remove );
		if ( null === $new ) {
			return new \WP_Error( 'crayn_safe_path_not_found', 'The path does not point to an element in this page. Re-run wpbakery-get for current paths.' );
		}

		return self::apply_tree( $post, $new, 'crayn-safe/wpbakery-update', 'element updated at path ' . self::path_str( $path ), $dry );
	}

	/**
	 * Execute wpbakery-move.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_wpbakery_move( $input ) {
		$dry = Safety::dry_run( $input );
		$guard = Safety::guard( 'crayn-content', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$from_path = self::int_path( isset( $input['from_path'] ) && is_array( $input['from_path'] ) ? $input['from_path'] : array() );
		$to_path   = self::int_path( isset( $input['to_parent_path'] ) && is_array( $input['to_parent_path'] ) ? $input['to_parent_path'] : array() );
		if ( null === $from_path || null === $to_path ) {
			return new \WP_Error( 'crayn_safe_path_invalid', 'from_path/to_parent_path must be arrays of non-negative integers.' );
		}
		if ( array() === $from_path ) {
			return new \WP_Error( 'crayn_safe_path_required', 'A non-empty "from_path" is required.' );
		}
		$index = isset( $input['index'] ) ? (int) $input['index'] : null;

		$tree   = ShortcodeTree::parse( (string) $post->post_content );
		$moving = ShortcodeTree::get_node( $tree, $from_path );
		if ( is_array( $moving ) ) {
			$placement = self::check_placement( $moving, $tree, $to_path, false );
			if ( is_wp_error( $placement ) ) {
				return $placement;
			}
		}
		$new = ShortcodeTree::move_node( $tree, $from_path, $to_path, $index );
		if ( null === $new ) {
			return new \WP_Error( 'crayn_safe_move_failed', 'The move failed: from_path/to_parent_path must point to existing elements and the target must not be inside the moved subtree. Re-run wpbakery-get for current paths.' );
		}

		return self::apply_tree( $post, $new, 'crayn-safe/wpbakery-move', 'element moved from ' . self::path_str( $from_path ) . ' to parent ' . self::path_str( $to_path ), $dry );
	}

	/**
	 * Execute wpbakery-remove.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_wpbakery_remove( $input ) {
		$dry = Safety::dry_run( $input );
		$guard = Safety::guard( 'crayn-content', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$path = self::int_path( isset( $input['path'] ) && is_array( $input['path'] ) ? $input['path'] : array() );
		if ( null === $path ) {
			return new \WP_Error( 'crayn_safe_path_invalid', 'path must be an array of non-negative integers.' );
		}
		if ( array() === $path ) {
			return new \WP_Error( 'crayn_safe_path_required', 'A non-empty "path" is required.' );
		}

		$tree = ShortcodeTree::parse( (string) $post->post_content );
		$new  = ShortcodeTree::remove_node( $tree, $path );
		if ( null === $new ) {
			return new \WP_Error( 'crayn_safe_path_not_found', 'The path does not point to an element in this page. Re-run wpbakery-get for current paths.' );
		}

		return self::apply_tree( $post, $new, 'crayn-safe/wpbakery-remove', 'element removed at path ' . self::path_str( $path ), $dry );
	}

	/**
	 * Execute wpbakery-replace.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_wpbakery_replace( $input ) {
		$dry = Safety::dry_run( $input );
		$guard = Safety::guard( 'crayn-content', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$encoded_values = ! empty( $input['values_encoded'] );
		$tree_in        = ( isset( $input['tree'] ) && is_array( $input['tree'] ) ) ? $input['tree'] : null;
		$content        = isset( $input['content'] ) ? (string) $input['content'] : '';

		if ( null !== $tree_in ) {
			// A full tree: normalize and (re-)encode human-readable values.
			$prepared = self::prepare_nodes( $tree_in, $encoded_values );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			// Same rule as wpbakery-add, but what is already on the page stays round-trippable.
			$existing = ShortcodeTree::collect_tags( ShortcodeTree::parse( (string) $post->post_content ) );
			foreach ( ShortcodeTree::collect_tags( $prepared ) as $tag ) {
				if ( ! in_array( $tag, $existing, true ) && null === self::wpb_map_entry( $tag ) && ! shortcode_exists( $tag ) ) {
					return new \WP_Error( 'crayn_safe_element_unknown', sprintf( 'Element "%s" is not registered on this site.', $tag ) );
				}
			}
			$new = $prepared;
		} else {
			// Full shortcode content: parse it (values already encoded).
			if ( '' === trim( $content ) ) {
				return new \WP_Error( 'crayn_safe_content_required', 'Provide either "content" (full shortcode string) or "tree" (full node list).' );
			}
			$parsed = ShortcodeTree::parse( $content );
			if ( ! is_array( $parsed ) ) {
				return new \WP_Error( 'crayn_safe_content_invalid', 'The provided shortcode content could not be parsed.' );
			}
			$new = $parsed;
		}

		return self::apply_tree( $post, $new, 'crayn-safe/wpbakery-replace', 'full content replaced', $dry );
	}

	/**
	 * Execute wpbakery-enable.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_wpbakery_enable( $input ) {
		$dry = Safety::dry_run( $input );
		$guard = Safety::guard( 'crayn-content', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$before = self::wpb_snapshot( $post->ID );
		if ( 'true' === (string) $before['wpb_vc_js_status'] ) {
			$tree = ShortcodeTree::parse( (string) $post->post_content );
			return array(
				'dry_run'   => (bool) $dry,
				'before'    => $before,
				'after'     => $before,
				'change_id' => 0,
				'post_id'   => (int) $post->ID,
				'outline'   => ShortcodeTree::outline( $tree ),
			);
		}

		$after  = $before;
		$after['wpb_vc_js_status'] = 'true';
		if ( $dry ) {
			$tree = ShortcodeTree::parse( (string) $post->post_content );
			return array(
				'dry_run'   => true,
				'before'    => $before,
				'after'     => $after,
				'change_id' => 0,
				'post_id'   => (int) $post->ID,
				'outline'   => ShortcodeTree::outline( $tree ),
			);
		}

		update_post_meta( $post->ID, '_wpb_vc_js_status', 'true' );
		$change_id = ChangeLogger::log( 'crayn-safe/wpbakery-enable', 'post', $post->ID, $before, self::wpb_snapshot( $post->ID ), 'WPBakery editor enabled (_wpb_vc_js_status = true)' );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'WPBakery editor was enabled for post %d.', $post->ID ) );
		}

		$tree = ShortcodeTree::parse( (string) $post->post_content );
		return array(
			'dry_run'   => false,
			'before'    => $before,
			'after'     => self::wpb_snapshot( $post->ID ),
			'change_id' => $change_id,
			'post_id'   => (int) $post->ID,
			'outline'   => ShortcodeTree::outline( $tree ),
		);
	}
	/**
	 * Execute wpbakery-page-css: set or clear the page's own custom CSS
	 * (meta _wpb_post_custom_css, the third WPBakery CSS meta).
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_wpbakery_page_css( $input ) {
		$dry = Safety::dry_run( $input );
		$guard = Safety::guard( 'crayn-content', true, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post = self::get_editable_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$css = (string) ( isset( $input['css'] ) ? $input['css'] : '' );
		// Never let the CSS close the <style> block the value is echoed into later.
		do {
			$css_before_strip = $css;
			$css              = str_ireplace( '</style', '', $css );
		} while ( $css !== $css_before_strip ); // One pass could reassemble the sequence.

		$before = self::wpb_snapshot( $post->ID );
		if ( (string) $before['wpb_post_css'] === $css ) {
			// Nothing to change (the same CSS, or clearing an empty value).
			$tree = ShortcodeTree::parse( (string) $post->post_content );
			return array(
				'dry_run'   => (bool) $dry,
				'before'    => $before,
				'after'     => $before,
				'change_id' => 0,
				'post_id'   => (int) $post->ID,
				'outline'   => ShortcodeTree::outline( $tree ),
			);
		}

		$after = $before;
		$after['wpb_post_css'] = $css;

		if ( $dry ) {
			$tree = ShortcodeTree::parse( (string) $post->post_content );
			return array(
				'dry_run'   => true,
				'before'    => $before,
				'after'     => $after,
				'change_id' => 0,
				'post_id'   => (int) $post->ID,
				'outline'   => ShortcodeTree::outline( $tree ),
			);
		}

		if ( '' === $css ) {
			delete_post_meta( $post->ID, '_wpb_post_custom_css' );
		} else {
			update_post_meta( $post->ID, '_wpb_post_custom_css', wp_slash( $css ) );
		}
		self::sync_revision_css( (int) $post->ID );

		$new_snapshot = self::wpb_snapshot( $post->ID );
		if ( (string) $new_snapshot['wpb_post_css'] !== $css ) {
			return new \WP_Error( 'crayn_safe_css_write_failed', sprintf( 'The custom CSS of post %d could not be written.', (int) $post->ID ) );
		}

		$change_id = ChangeLogger::log(
			'crayn-safe/wpbakery-page-css',
			'post',
			(int) $post->ID,
			$before,
			$new_snapshot,
			'' === $css ? 'page CSS cleared (_wpb_post_custom_css)' : 'page CSS set (_wpb_post_custom_css)'
		);
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'The custom CSS of post %d was changed but could not be logged.', (int) $post->ID ) );
		}

		$tree = ShortcodeTree::parse( (string) $post->post_content );
		return array(
			'dry_run'   => false,
			'before'    => $before,
			'after'     => $new_snapshot,
			'change_id' => (int) $change_id,
			'post_id'   => (int) $post->ID,
			'outline'   => ShortcodeTree::outline( $tree ),
		);
	}

	// ---------------------------------------------------------------------
	// Internal helpers
	// ---------------------------------------------------------------------

	/**
	 * Cached WPBakery map entries, keyed by element base name (null = unknown).
	 *
	 * @var array<string,array|null>
	 */
	private static $map_entry_cache = array();

	/**
	 * Cached param types, keyed by tag.
	 *
	 * @var array<string,array>
	 */
	private static $param_types_cache = array();

	/**
	 * Render a path as dotted string for messages/notes.
	 *
	 * @param array $path Path.
	 * @return string
	 */
	private static function path_str( array $path ) {
		return array() === $path ? '' : implode( '.', $path );
	}

	/**
	 * Normalize a path input: it must be a list of non-negative integers.
	 *
	 * @param mixed $path Raw path input.
	 * @return array|null Normalized integer path, or null when invalid.
	 */
	private static function int_path( $path ) {
		if ( ! is_array( $path ) ) {
			return array();
		}
		$out = array();
		foreach ( array_values( $path ) as $idx ) {
			if ( ! is_numeric( $idx ) ) {
				return null;
			}
			$int = (int) $idx;
			if ( $int < 0 ) {
				return null;
			}
			$out[] = $int;
		}
		return $out;
	}

	/**
	 * Load a post and check the current user may edit it.
	 *
	 * @param int $post_id Post id.
	 * @return \WP_Post|\WP_Error
	 */
	private static function get_editable_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'crayn_safe_post_id_required', 'A valid post_id is required.' );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'crayn_safe_post_not_found', sprintf( 'Post %d does not exist.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot edit this content.' );
		}
		return $post;
	}

	/**
	 * Does this post look like a WPBakery page?
	 * Either the editor flag meta says so, or the content starts with a row/section.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function uses_wpbakery( \WP_Post $post ) {
		if ( 'true' === (string) get_post_meta( $post->ID, '_wpb_vc_js_status', true ) ) {
			return true;
		}
		return 1 === preg_match( '~\[vc_(row|section)[\]\s]~', (string) $post->post_content );
	}

	/**
	 * The two WPBakery custom CSS metas of a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,string>
	 */
	private static function custom_css( $post_id ) {
		return array(
			'shortcodes_css' => (string) get_post_meta( (int) $post_id, '_wpb_shortcodes_custom_css', true ),
			'default_css'    => (string) get_post_meta( (int) $post_id, '_wpb_shortcodes_default_css', true ),
			'post_css'       => (string) get_post_meta( (int) $post_id, '_wpb_post_custom_css', true ),
		);
	}

	/**
	 * Snapshot of everything WPBakery writes for a post: content plus the two
	 * custom CSS metas and the editor status. Used for before/after and revert.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,string>
	 */
	private static function wpb_snapshot( $post_id ) {
		return array(
			'post_content'              => (string) get_post_field( 'post_content', (int) $post_id, 'raw' ),
			'wpb_shortcodes_custom_css' => (string) get_post_meta( (int) $post_id, '_wpb_shortcodes_custom_css', true ),
			'wpb_shortcodes_default_css' => (string) get_post_meta( (int) $post_id, '_wpb_shortcodes_default_css', true ),
			'wpb_post_css'              => (string) get_post_meta( (int) $post_id, '_wpb_post_custom_css', true ),
			'wpb_vc_js_status'          => (string) get_post_meta( (int) $post_id, '_wpb_vc_js_status', true ),
		);
	}

	/**
	 * WPBakery map entry of an element base name, or null when it is not
	 * registered. Results are cached per request.
	 *
	 * @param string $tag Element base name.
	 * @return array|null
	 */
	private static function wpb_map_entry( $tag ) {
		$tag = (string) $tag;
		if ( '' === $tag ) {
			return null;
		}
		if ( array_key_exists( $tag, self::$map_entry_cache ) ) {
			return self::$map_entry_cache[ $tag ];
		}
		$entry = null;
		if ( defined( 'WPB_VC_VERSION' ) && class_exists( 'WPBMap' ) && method_exists( 'WPBMap', 'getShortCode' ) ) {
			$mapped = \WPBMap::getShortCode( $tag );
			if ( is_array( $mapped ) ) {
				$entry = $mapped;
			}
		}
		self::$map_entry_cache[ $tag ] = $entry;
		return $entry;
	}

	/**
	 * param_name => type map of an element, from the WPBakery map.
	 *
	 * @param string $tag Element base name.
	 * @return array<string,string>
	 */
	private static function param_types( $tag ) {
		$tag = (string) $tag;
		if ( array_key_exists( $tag, self::$param_types_cache ) ) {
			return self::$param_types_cache[ $tag ];
		}
		$types = array();
		$entry = self::wpb_map_entry( $tag );
		if ( null !== $entry && isset( $entry['params'] ) && is_array( $entry['params'] ) ) {
			foreach ( $entry['params'] as $param ) {
				if ( ! is_array( $param ) || ! isset( $param['param_name'] ) ) {
					continue;
				}
				$types[ (string) $param['param_name'] ] = isset( $param['type'] ) ? (string) $param['type'] : 'textfield';
			}
		}
		self::$param_types_cache[ $tag ] = $types;
		return $types;
	}

	/**
	 * Custom CSS (design options) that a shortcode string produces. Uses
	 * WPBakery's own parser when it is available.
	 *
	 * @param string $content Encoded shortcode content.
	 * @return string
	 */
	private static function content_shortcodes_css( $content ) {
		if ( function_exists( 'wpbakery' ) && method_exists( wpbakery(), 'parseShortcodesCss' ) ) {
			try {
				return (string) wpbakery()->parseShortcodesCss( (string) $content, 'custom' );
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall through to the plain collection below.
			}
		}
		$css = '';
		if ( preg_match_all( '~\scss="(\.[^"]*)"~', (string) $content, $matches ) ) {
			$css = implode( '', $matches[1] );
		}
		return $css;
	}

	/**
	 * Rebuild the generated CSS metas of a saved post, the way WPBakery does on
	 * its own save (custom design options and element defaults).
	 *
	 * @param int    $post_id Post id.
	 * @param string $content Saved content, for the fallback.
	 * @return void
	 */
	private static function rebuild_css( $post_id, $content ) {
		if ( function_exists( 'wpbakery' ) && method_exists( wpbakery(), 'buildShortcodesCss' ) ) {
			try {
				wpbakery()->buildShortcodesCss( $post_id, 'custom' );
				wpbakery()->buildShortcodesCss( $post_id, 'default' );
				return;
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall through to the plain collection below.
			}
		}
		$css = self::content_shortcodes_css( $content );
		if ( '' === $css ) {
			delete_post_meta( $post_id, '_wpb_shortcodes_custom_css' );
		} else {
			update_post_meta( $post_id, '_wpb_shortcodes_custom_css', wp_slash( $css ) );
		}
	}

	/**
	 * Compact view of a snapshot for write results: dry runs show the whole
	 * state, applied writes only the outline (the full state is in the log).
	 *
	 * @param array $snapshot Snapshot.
	 * @param array $outline  Outline lines.
	 * @param bool  $full     Include content and CSS.
	 * @return array
	 */
	private static function state_view( array $snapshot, array $outline, $full ) {
		if ( $full ) {
			return array_merge( array( 'outline' => $outline ), $snapshot );
		}
		return array(
			'outline'        => $outline,
			'content_length' => strlen( $snapshot['post_content'] ),
		);
	}

	/**
	 * Serialize a prepared tree and write it back: save the post, rebuild the
	 * WPBakery custom CSS meta, and log the change. Empty differences return
	 * the unchanged state (change_id 0) instead of a pointless write.
	 *
	 * @param \WP_Post $post    Post.
	 * @param array    $tree    Prepared (encoded) shortcode tree.
	 * @param string   $ability Ability name for the change log.
	 * @param string   $note    Change note.
	 * @param bool     $dry     Dry run flag.
	 * @return array|\WP_Error
	 */
	private static function apply_tree( \WP_Post $post, array $tree, $ability, $note, $dry ) {
		// Names are written unescaped: a bracket in one would add a shortcode to the page.
		$invalid = ShortcodeTree::find_invalid_name( $tree );
		if ( null !== $invalid ) {
			return new \WP_Error( 'crayn_safe_attr_invalid', sprintf( 'Invalid name in the shortcode tree: %s.', $invalid ) );
		}

		$denied = self::raw_html_error( $tree, ShortcodeTree::parse( (string) $post->post_content ) );
		if ( null !== $denied ) {
			return $denied;
		}

		$content = ShortcodeTree::serialize( $tree );
		if ( ! is_string( $content ) ) {
			return new \WP_Error( 'crayn_safe_serialize_failed', 'The shortcode tree could not be serialized.' );
		}

		$before         = self::wpb_snapshot( $post->ID );
		$outline        = ShortcodeTree::outline( $tree );
		$before_outline = ShortcodeTree::outline( ShortcodeTree::parse( $before['post_content'] ) );

		if ( $before['post_content'] === $content ) {
			return array(
				'dry_run'   => (bool) $dry,
				'before'    => self::state_view( $before, $before_outline, false ),
				'after'     => self::state_view( $before, $outline, false ),
				'change_id' => 0,
				'post_id'   => (int) $post->ID,
				'outline'   => $outline,
			);
		}

		if ( $dry ) {
			$after                              = $before;
			$after['post_content']              = $content;
			$after['wpb_shortcodes_custom_css'] = self::content_shortcodes_css( $content );
			return array(
				'dry_run'   => true,
				'before'    => self::state_view( $before, $before_outline, true ),
				'after'     => self::state_view( $after, $outline, true ),
				'change_id' => 0,
				'post_id'   => (int) $post->ID,
				'outline'   => $outline,
			);
		}

		$updated = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $content ) ), true );
		if ( is_wp_error( $updated ) ) {
			return new \WP_Error( 'crayn_safe_post_update_failed', sprintf( 'Saving post %d failed: %s', (int) $post->ID, implode( ' ', $updated->get_error_messages() ) ) );
		}

		self::rebuild_css( (int) $post->ID, $content );
		self::sync_revision_css( (int) $post->ID );

		$after     = self::wpb_snapshot( $post->ID );
		$change_id = ChangeLogger::log( $ability, 'post', (int) $post->ID, $before, $after, $note );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'The change was applied to post %d (%s) but could not be logged.', (int) $post->ID, $note ) );
		}

		return array(
			'dry_run'   => false,
			'before'    => self::state_view( $before, $before_outline, false ),
			'after'     => self::state_view( $after, $outline, false ),
			'change_id' => (int) $change_id,
			'post_id'   => (int) $post->ID,
			'outline'   => $outline,
		);
	}

	/**
	 * Full WPBakery element catalog from the WPBakery map.
	 *
	 * @return array<string,array> base name => map entry.
	 */
	private static function elements_catalog() {
		if ( ! defined( 'WPB_VC_VERSION' ) || ! class_exists( 'WPBMap' ) || ! method_exists( 'WPBMap', 'getAllShortCodes' ) ) {
			return array();
		}
		if ( method_exists( 'WPBMap', 'addAllMappedShortcodes' ) ) {
			\WPBMap::addAllMappedShortcodes();
		}
		$map = \WPBMap::getAllShortCodes();
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Description of one element for wpbakery-elements output.
	 *
	 * @param string $base        Element base name.
	 * @param array  $entry       WPBakery map entry.
	 * @param bool   $with_params Include the full param list.
	 * @return array<string,mixed>
	 */
	private static function element_item( $base, array $entry, $with_params ) {
		$item = array(
			'base'         => (string) $base,
			'name'         => isset( $entry['name'] ) && is_scalar( $entry['name'] ) ? (string) $entry['name'] : '',
			'category'     => isset( $entry['category'] ) ? implode( ', ', array_filter( (array) $entry['category'], 'is_scalar' ) ) : '',
			'description'  => isset( $entry['description'] ) && is_scalar( $entry['description'] ) ? (string) $entry['description'] : '',
			'is_container' => self::is_container( $entry ),
			'as_parent'    => self::rule_label( self::placement_rule( $entry, 'as_parent' ) ),
			'as_child'     => self::rule_label( self::placement_rule( $entry, 'as_child' ) ),
		);
		if ( ! $with_params ) {
			$item['param_count'] = isset( $entry['params'] ) && is_array( $entry['params'] ) ? count( $entry['params'] ) : 0;
			return $item;
		}

		$item['params'] = array();
		foreach ( isset( $entry['params'] ) && is_array( $entry['params'] ) ? $entry['params'] : array() as $param ) {
			if ( ! is_array( $param ) || ! isset( $param['param_name'] ) ) {
				continue;
			}
			$row = array(
				'param_name' => (string) $param['param_name'],
				'type'       => isset( $param['type'] ) ? (string) $param['type'] : 'textfield',
				'heading'    => isset( $param['heading'] ) && is_scalar( $param['heading'] ) ? (string) $param['heading'] : '',
				'options'    => array(),
				'default'    => '',
			);
			if ( isset( $param['value'] ) && is_array( $param['value'] ) ) {
				// vc_map option lists are label => value, or plain value lists.
				foreach ( $param['value'] as $label => $value ) {
					if ( is_scalar( $value ) ) {
						$row['options'][] = array(
							'value' => (string) $value,
							'label' => is_int( $label ) ? (string) $value : (string) $label,
						);
					}
				}
			} elseif ( isset( $param['value'] ) && is_scalar( $param['value'] ) ) {
				$row['default'] = (string) $param['value'];
			}
			if ( isset( $param['std'] ) && is_scalar( $param['std'] ) ) {
				$row['default'] = (string) $param['std'];
			}
			$item['params'][] = $row;
		}
		return $item;
	}

	/**
	 * Encode one human-readable attribute value into WPBakery storage form,
	 * according to the param type of the attribute. Already-encoded values
	 * (values_encoded input flag) pass through untouched.
	 *
	 * @param string $type    WPBakery param type.
	 * @param mixed  $value   Human-readable (or pre-encoded) value.
	 * @param bool   $encoded True when the caller already encoded the values.
	 * @return string
	 */
	private static function prepare_value( $type, $value, $encoded ) {
		if ( is_array( $value ) ) {
			if ( 'vc_link' === $type ) {
				return ShortcodeTree::build_link( $value );
			}
			if ( 'param_group' === $type ) {
				return rawurlencode( (string) wp_json_encode( $value ) );
			}
			return (string) wp_json_encode( $value );
		}
		$value = ( null === $value ) ? '' : (string) $value;

		if ( $encoded ) {
			return $value;
		}
		// Attribute escaping itself (quotes, brackets) is done once, by the serializer.
		if ( 'textarea_raw_html' === $type ) {
			// Human HTML gets encoded; values that are already the stored form pass through.
			return ( null === ShortcodeTree::decode_raw_html( $value ) ) ? ShortcodeTree::encode_raw_html( $value ) : $value;
		}
		if ( 'vc_link' === $type ) {
			return ( '' === $value || 0 === strpos( $value, 'url:' ) ) ? $value : ShortcodeTree::build_link( array( 'url' => $value ) );
		}
		if ( 'exploded_textarea' === $type ) {
			return str_replace( array( "\r\n", "\n" ), ',', $value );
		}
		if ( 'exploded_textarea_safe' === $type ) {
			return ( '' === $value || 0 === strpos( $value, '#E-8_' ) ) ? $value : '#E-8_' . ShortcodeTree::encode_raw_html( str_replace( array( "\r\n", "\n" ), ',', $value ) );
		}
		return $value;
	}

	/**
	 * Encode a full attrs map of an element into storage form.
	 *
	 * @param string $tag     Element base name (for its param types).
	 * @param array  $attrs   Human-readable attrs.
	 * @param bool   $encoded True when values were passed already encoded.
	 * @return array<string,string>
	 */
	private static function prepare_attrs( $tag, array $attrs, $encoded ) {
		$types = self::param_types( $tag );
		$out   = array();
		foreach ( $attrs as $name => $value ) {
			$name_key = (string) $name;
			$type     = isset( $types[ $name_key ] ) ? $types[ $name_key ] : 'textfield';
			$out[ $name_key ] = self::prepare_value( $type, $value, $encoded );
		}
		return $out;
	}

	/**
	 * Is the inner content of this element base64(rawurlencode()) encoded?
	 *
	 * @param string $tag Element base name.
	 * @return bool
	 */
	private static function has_raw_content( $tag ) {
		$types = self::param_types( $tag );
		return in_array( $tag, self::RAW_CONTENT_TAGS, true ) || ( isset( $types['content'] ) && 'textarea_raw_html' === $types['content'] );
	}

	/**
	 * Prepare the inner content of an element. vc_raw_html / vc_raw_js get the
	 * base64(rawurlencode()) encoding (auto-detected, never double encoded);
	 * everything else is stored as given.
	 *
	 * @param string      $tag     Element base name.
	 * @param string|null $content Content (null = keep).
	 * @param bool        $encoded True when the caller already encoded the values.
	 * @return string|null
	 */
	private static function prepare_content( $tag, $content, $encoded ) {
		if ( null === $content ) {
			return null;
		}
		$content = (string) $content;
		if ( self::has_raw_content( $tag ) && ! $encoded ) {
			if ( null === ShortcodeTree::decode_raw_html( $content ) ) {
				return ShortcodeTree::encode_raw_html( $content );
			}
		}
		return $content;
	}

	/**
	 * Normalize one element object (recursive) into a canonical tree node and
	 * encode its human-readable values into WPBakery storage form.
	 *
	 * @param mixed $element  Element object from the ability input.
	 * @param bool  $encoded  True when values were passed already encoded.
	 * @return array|\WP_Error
	 */
	private static function prepare_node( $element, $encoded ) {
		if ( ! is_array( $element ) || ! isset( $element['tag'] ) || ! is_string( $element['tag'] ) || '' === trim( $element['tag'] ) ) {
			return new \WP_Error( 'crayn_safe_element_invalid', 'Every element needs a "tag" string.' );
		}
		$tag     = trim( (string) $element['tag'] );
		// New elements get an explicit closing tag: a bare tag followed later by a same-name
		// element with a closing tag would swallow it in WordPress' shortcode regex. Nodes
		// coming from wpbakery-get keep content null and stay bare, as stored.
		$content = ( isset( $element['content'] ) && is_string( $element['content'] ) ) ? $element['content'] : ( array_key_exists( 'content', $element ) ? null : '' );

		if ( ShortcodeTree::TEXT_TAG === $tag ) {
			return ShortcodeTree::normalize_node(
				array(
					'tag'     => ShortcodeTree::TEXT_TAG,
					'content' => (string) $content,
				)
			);
		}

		$types = self::param_types( $tag );
		$attrs = array();
		if ( isset( $element['attrs'] ) && is_array( $element['attrs'] ) ) {
			foreach ( $element['attrs'] as $name => $value ) {
				$name_key          = (string) $name;
				$type              = isset( $types[ $name_key ] ) ? $types[ $name_key ] : 'textfield';
				$attrs[ $name_key ] = self::prepare_value( $type, $value, $encoded );
			}
		}

		$children = array();
		if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
			$prepared = self::prepare_nodes( $element['children'], $encoded );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$children = $prepared;
		}
		if ( ! empty( $children ) ) {
			$content = null;
		}

		return ShortcodeTree::normalize_node(
			array(
				'tag'      => $tag,
				'attrs'    => $attrs,
				'content'    => self::prepare_content( $tag, $content, $encoded ),
				'children'   => $children,
				'self_close' => ! empty( $element['self_close'] ),
				'unclosed'   => ! empty( $element['unclosed'] ),
			)
		);
	}

	/**
	 * Normalize a whole list of nodes (recursive), with value encoding.
	 *
	 * @param mixed $input   Node list from the ability input.
	 * @param bool  $encoded True when values were passed already encoded.
	 * @return array|\WP_Error
	 */
	private static function prepare_nodes( $input, $encoded ) {
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'crayn_safe_tree_invalid', 'The tree must be a list of node objects.' );
		}
		$out = array();
		foreach ( array_values( $input ) as $item ) {
			if ( ! is_array( $item ) ) {
				return new \WP_Error( 'crayn_safe_tree_invalid', 'The tree must only contain node objects.' );
			}
			$prepared = self::prepare_node( $item, $encoded );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$out[] = $prepared;
		}
		return $out;
	}

	/**
	 * Normalized placement rule of a map entry. vc_map stores these as
	 * array( 'only' => 'a,b' ) or array( 'except' => 'a,b' ); older maps as strings.
	 *
	 * @param array  $entry Map entry.
	 * @param string $key   as_parent or as_child.
	 * @return array|null { except: bool, tags: string[] }, null without a rule.
	 */
	private static function placement_rule( array $entry, $key ) {
		if ( ! isset( $entry[ $key ] ) ) {
			return null;
		}
		$rule = $entry[ $key ];
		if ( is_array( $rule ) ) {
			if ( isset( $rule['only'] ) ) {
				$except = false;
				$list   = (string) $rule['only'];
			} elseif ( isset( $rule['except'] ) ) {
				$except = true;
				$list   = (string) $rule['except'];
			} else {
				return null;
			}
		} else {
			$rule = trim( (string) $rule );
			if ( '' === $rule ) {
				return null;
			}
			$except = 0 === stripos( $rule, 'except' );
			$list   = (string) preg_replace( '~^(only|except)\b\s*~i', '', $rule );
		}
		return array(
			'except' => $except,
			'tags'   => array_values( array_filter( array_map( 'trim', explode( ',', $list ) ), 'strlen' ) ),
		);
	}

	/**
	 * Printable form of a placement rule.
	 *
	 * @param array|null $rule Normalized rule.
	 * @return string
	 */
	private static function rule_label( $rule ) {
		if ( null === $rule ) {
			return '';
		}
		return ( $rule['except'] ? 'except: ' : 'only: ' ) . ( array() === $rule['tags'] ? '(top level)' : implode( ', ', $rule['tags'] ) );
	}

	/**
	 * Does a rule allow this tag?
	 *
	 * @param string     $tag  Tag.
	 * @param array|null $rule Normalized rule.
	 * @return bool
	 */
	private static function rule_allows( $tag, $rule ) {
		if ( null === $rule ) {
			return true;
		}
		$listed = in_array( (string) $tag, $rule['tags'], true );
		return $rule['except'] ? ! $listed : $listed;
	}

	/**
	 * Can this map entry hold other elements?
	 *
	 * @param array $entry Map entry.
	 * @return bool
	 */
	private static function is_container( array $entry ) {
		return ! empty( $entry['is_container'] ) || ! empty( $entry['as_parent'] );
	}

	/**
	 * Check one tag against one parent tag (null parent = top level).
	 *
	 * @param string      $tag        Element base name.
	 * @param string|null $parent_tag Parent base name.
	 * @return true|\WP_Error
	 */
	private static function check_pair( $tag, $parent_tag ) {
		$denied = function ( $message ) {
			return new \WP_Error( 'crayn_safe_placement_denied', $message );
		};

		if ( ShortcodeTree::TEXT_TAG === $tag ) {
			if ( null === $parent_tag || in_array( $parent_tag, array( 'vc_row', 'vc_row_inner', 'vc_section' ), true ) ) {
				return $denied( sprintf( 'A text node cannot be placed in %s. Use a vc_column_text element.', null === $parent_tag ? 'the top level' : '"' . $parent_tag . '"' ) );
			}
			return true;
		}

		$entry = self::wpb_map_entry( $tag );
		if ( null === $entry ) {
			return new \WP_Error( 'crayn_safe_element_unknown', sprintf( 'Element "%s" is not registered in WPBakery on this site.', $tag ) );
		}

		if ( null === $parent_tag ) {
			$root_tags = apply_filters( 'crayn_safe_wpbakery_root_tags', array( 'vc_row', 'vc_section', 'vc_grid_container', 'vc_flexbox_container' ) );
			if ( ! in_array( $tag, (array) $root_tags, true ) ) {
				return $denied( sprintf( '"%s" cannot be placed at the top level of a WPBakery page (allowed: %s).', $tag, implode( ', ', (array) $root_tags ) ) );
			}
			return true;
		}

		// Structure WPBakery's map does not express: columns only exist inside their row.
		$only_inside = array(
			'vc_column'       => array( 'vc_row' ),
			'vc_column_inner' => array( 'vc_row_inner' ),
		);
		if ( isset( $only_inside[ $tag ] ) && ! in_array( $parent_tag, $only_inside[ $tag ], true ) ) {
			return $denied( sprintf( '"%s" can only be placed inside %s, not "%s".', $tag, implode( ', ', $only_inside[ $tag ] ), $parent_tag ) );
		}
		$only_holds = array(
			'vc_row'       => 'vc_column',
			'vc_row_inner' => 'vc_column_inner',
		);
		if ( isset( $only_holds[ $parent_tag ] ) && $only_holds[ $parent_tag ] !== $tag ) {
			return $denied( sprintf( '"%s" can only hold %s elements, not "%s". Add it to a column.', $parent_tag, $only_holds[ $parent_tag ], $tag ) );
		}
		if ( 'vc_row' === $tag && in_array( $parent_tag, array( 'vc_column', 'vc_column_inner' ), true ) ) {
			return $denied( 'A vc_row cannot be nested in a column. Use vc_row_inner.' );
		}

		$parent_entry = self::wpb_map_entry( $parent_tag );
		if ( null === $parent_entry ) {
			return $denied( sprintf( 'The parent "%s" is not registered in WPBakery on this site; its placement rules are unknown.', $parent_tag ) );
		}
		if ( ! self::is_container( $parent_entry ) ) {
			return $denied( sprintf( '"%s" is not a container and cannot hold elements.', $parent_tag ) );
		}
		$parent_rule = self::placement_rule( $parent_entry, 'as_parent' );
		if ( ! self::rule_allows( $tag, $parent_rule ) ) {
			return $denied( sprintf( 'WPBakery does not allow "%s" inside "%s" (%s).', $tag, $parent_tag, self::rule_label( $parent_rule ) ) );
		}
		$child_rule = self::placement_rule( $entry, 'as_child' );
		if ( ! self::rule_allows( $parent_tag, $child_rule ) ) {
			return $denied( sprintf( 'WPBakery does not allow "%s" inside "%s" (%s).', $tag, $parent_tag, self::rule_label( $child_rule ) ) );
		}
		return true;
	}

	/**
	 * Validate a node against its target parent and, for new elements, its
	 * whole subtree.
	 *
	 * @param array $node        Prepared node.
	 * @param array $tree        Current tree (for parent lookup).
	 * @param array $parent_path Path of the target parent ([] = top level).
	 * @param bool  $deep        Also validate the node's children.
	 * @return true|\WP_Error
	 */
	private static function check_placement( array $node, array $tree, array $parent_path, $deep ) {
		$parent_tag = null;
		if ( array() !== $parent_path ) {
			$parent = ShortcodeTree::get_node( $tree, $parent_path );
			if ( ! is_array( $parent ) ) {
				return new \WP_Error( 'crayn_safe_path_not_found', 'The parent path does not point to an element in this page. Re-run wpbakery-get for current paths.' );
			}
			$parent_tag = (string) $parent['tag'];
		}
		$check = self::check_pair( (string) $node['tag'], $parent_tag );
		return ( true === $check && $deep ) ? self::check_children( $node ) : $check;
	}

	/**
	 * Validate the descendants of a new element.
	 *
	 * @param array $node Node.
	 * @return true|\WP_Error
	 */
	private static function check_children( array $node ) {
		foreach ( $node['children'] as $child ) {
			// Indentation between tags is not a placement.
			if ( ShortcodeTree::TEXT_TAG === $child['tag'] && '' === trim( (string) $child['content'] ) ) {
				continue;
			}
			$check = self::check_pair( (string) $child['tag'], (string) $node['tag'] );
			if ( true === $check ) {
				$check = self::check_children( $child );
			}
			if ( true !== $check ) {
				return $check;
			}
		}
		return true;
	}

	/**
	 * Output-only copy of a tree for humans: attrs are decoded, link params get
	 * parsed "link" objects and raw-html params/content get a "decoded" map.
	 * Never serialize this copy back (use the plain tree or values_encoded).
	 *
	 * @param array $tree Tree.
	 * @return array
	 */
	private static function readable_tree( array $tree ) {
		return self::readable_nodes( array_values( $tree ) );
	}

	/**
	 * Recursive walker behind readable_tree().
	 *
	 * @param array $children Child list.
	 * @return array
	 */
	private static function readable_nodes( array $children ) {
		$out = array();
		foreach ( $children as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$copy = $node;
			$tag  = isset( $node['tag'] ) ? (string) $node['tag'] : '';
			// Parser bookkeeping is of no use to a client and would double the output.
			unset( $copy['raw_attrs'], $copy['attrs_orig'] );
			foreach ( array( 'attr_junk', 'self_close', 'unclosed' ) as $flag ) {
				if ( empty( $copy[ $flag ] ) ) {
					unset( $copy[ $flag ] );
				}
			}

			if ( '' !== $tag && ShortcodeTree::TEXT_TAG !== $tag ) {
				$types         = self::param_types( $tag );
				$raw_attrs     = ( isset( $node['attrs'] ) && is_array( $node['attrs'] ) ) ? $node['attrs'] : array();
				$decoded_attrs = array();
				$decoded       = array();
				$links         = array();

				foreach ( $raw_attrs as $name => $value ) {
					$name_key = (string) $name;
					$type     = isset( $types[ $name_key ] ) ? $types[ $name_key ] : 'textfield';
					$plain    = ShortcodeTree::decode_attr( $value );

					if ( 'textarea_raw_html' === $type ) {
						$raw = ShortcodeTree::decode_raw_html( $plain );
						if ( null !== $raw ) {
							$plain               = $raw;
							$decoded[ $name_key ] = $raw;
						}
					} elseif ( 'vc_link' === $type ) {
						$links[ $name_key ] = ShortcodeTree::parse_link( $plain );
					} elseif ( 'param_group' === $type ) {
						$group = json_decode( rawurldecode( $plain ), true );
						if ( is_array( $group ) ) {
							$decoded[ $name_key ] = $group;
						}
					}
					$decoded_attrs[ $name_key ] = $plain;
				}

				$copy['attrs'] = $decoded_attrs;

				if ( self::has_raw_content( $tag ) ) {
					$raw_content         = ShortcodeTree::decode_raw_html( (string) $node['content'] );
					$decoded['content'] = ( null === $raw_content ) ? (string) $node['content'] : $raw_content;
				}
				if ( array() !== $decoded ) {
					$copy['decoded'] = $decoded;
				}
				if ( array() !== $links ) {
					$copy['link'] = $links;
				}
			}

			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$copy['children'] = self::readable_nodes( $node['children'] );
			}
			$out[] = $copy;
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Revert support (dispatched from ChangeAbilities)
	// ---------------------------------------------------------------------

	/**
	 * Current WPBakery snapshot of a post, used by ChangeAbilities to log the
	 * state right before a revert. Null when the post is gone.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function current_snapshot( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return null;
		}
		return self::wpb_snapshot( $post_id );
	}

	/**
	 * Restore a logged WPBakery change: the previous post_content plus the
	 * WPBakery customs CSS metas and editor status from the before-snapshot.
	 *
	 * Accepts the raw change-log row (with the JSON "before_data" column) or a
	 * row whose "before_data" was already decoded.
	 *
	 * @param array $row Change log row.
	 * @return string|\WP_Error Message on success.
	 */
	public static function revert( array $row ) {
		$before = ( isset( $row['before_data'] ) && is_array( $row['before_data'] ) ) ? $row['before_data'] : array();
		if ( array() === $before && isset( $row['before_data'] ) && is_string( $row['before_data'] ) && '' !== $row['before_data'] ) {
			$before = ChangeLogger::decode( $row['before_data'] );
		}
		if ( empty( $before ) || ! is_array( $before ) || ! array_key_exists( 'post_content', $before ) ) {
			return new \WP_Error( 'crayn_safe_revert_no_snapshot', 'The change log row has no WPBakery snapshot to restore.' );
		}

		$post_id = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
		if ( $post_id <= 0 && isset( $before['ID'] ) && is_numeric( $before['ID'] ) ) {
			$post_id = (int) $before['ID'];
		}
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new \WP_Error( 'crayn_safe_post_not_found', sprintf( 'Post %d does not exist anymore; nothing to restore.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot edit this content.' );
		}

		$ability = isset( $row['ability'] ) ? (string) $row['ability'] : '';
		$after   = ( isset( $row['after_data'] ) && is_string( $row['after_data'] ) ) ? ChangeLogger::decode( $row['after_data'] ) : array();
		$metas   = array( 'wpb_vc_js_status' => '_wpb_vc_js_status' );

		// Each tool restores only what it can change: the enable flag, the page CSS,
		// or the content with its generated CSS. Later edits of the others stay.
		if ( 'crayn-safe/wpbakery-page-css' === $ability ) {
			if ( is_array( $after ) && isset( $after['wpb_post_css'] ) && (string) get_post_meta( $post_id, '_wpb_post_custom_css', true ) !== (string) $after['wpb_post_css'] ) {
				return new \WP_Error( 'crayn_safe_revert_conflict', sprintf( 'The page CSS of post %d changed after this edit. Revert the newer changes first (see changes-list), newest first.', $post_id ) );
			}
			$metas = array( 'wpb_post_css' => '_wpb_post_custom_css' );
		} elseif ( 'crayn-safe/wpbakery-enable' !== $ability ) {
			// The snapshot replaces the whole page: never overwrite later edits silently.
			if ( is_array( $after ) && isset( $after['post_content'] ) && (string) get_post_field( 'post_content', $post_id, 'raw' ) !== (string) $after['post_content'] ) {
				return new \WP_Error( 'crayn_safe_revert_conflict', sprintf( 'The content of post %d changed after this edit. Revert the newer changes first (see changes-list), newest first.', $post_id ) );
			}
			$updated = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => (string) $before['post_content'] ) ), true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$metas += array(
				'wpb_shortcodes_custom_css'  => '_wpb_shortcodes_custom_css',
				'wpb_shortcodes_default_css' => '_wpb_shortcodes_default_css',
			);
			// A later wpbakery-enable changed the flag: that edit stays.
			if ( is_array( $after ) && array_key_exists( 'wpb_vc_js_status', $after ) && (string) get_post_meta( $post_id, '_wpb_vc_js_status', true ) !== (string) $after['wpb_vc_js_status'] ) {
				unset( $metas['wpb_vc_js_status'] );
			}
		}

		foreach ( $metas as $key => $meta_key ) {
			if ( ! array_key_exists( $key, $before ) ) {
				continue;
			}
			if ( '' === (string) $before[ $key ] ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, wp_slash( (string) $before[ $key ] ) );
			}
		}
		self::sync_revision_css( $post_id );

		return sprintf( 'WPBakery content of post %d restored.', $post_id );
	}

	/**
	 * Raw HTML and JS elements store base64, which kses cannot filter. A user without
	 * unfiltered_html may keep the ones already on the page but cannot add or change any.
	 *
	 * @param array $new New tree.
	 * @param array $old Current tree.
	 * @return \WP_Error|null
	 */
	public static function raw_html_error( array $new, array $old ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return null;
		}
		$existing = self::raw_elements( $old );
		foreach ( self::raw_elements( $new ) as $element ) {
			if ( ! in_array( $element, $existing, true ) ) {
				return new \WP_Error( 'crayn_safe_raw_html_denied', 'Adding or changing raw HTML or JS elements requires the unfiltered_html capability.' );
			}
		}
		return null;
	}

	/**
	 * Serialized raw HTML and JS elements of a tree.
	 *
	 * @param array $nodes Nodes.
	 * @return string[]
	 */
	private static function raw_elements( array $nodes ) {
		$found = array();
		foreach ( $nodes as $node ) {
			if ( self::has_raw_content( (string) $node['tag'] ) ) {
				$found[] = (string) ShortcodeTree::serialize( array( $node ) );
			} elseif ( ! empty( $node['children'] ) ) {
				$found = array_merge( $found, self::raw_elements( $node['children'] ) );
			}
		}
		return $found;
	}

	/**
	 * Copy the page CSS to the latest revision. On a preview WPBakery reads this meta
	 * from the latest revision (wpb_update_id_with_preview_id()), and only its own
	 * editor save writes it there. update_post_meta() would redirect to the parent.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private static function sync_revision_css( $post_id ) {
		$revisions = wp_get_post_revisions( (int) $post_id, array( 'numberposts' => 1 ) );
		$revision  = reset( $revisions );
		if ( ! $revision ) {
			return;
		}
		$css = (string) get_post_meta( (int) $post_id, '_wpb_post_custom_css', true );
		if ( '' === $css ) {
			delete_metadata( 'post', $revision->ID, '_wpb_post_custom_css' );
		} else {
			update_metadata( 'post', $revision->ID, '_wpb_post_custom_css', wp_slash( $css ) );
		}
	}
}
