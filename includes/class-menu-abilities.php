<?php
/**
 * Navigation menu abilities: list, read and safely edit classic menus, their
 * items and their theme location assignments.
 *
 * Every write is planned in memory first (dry run = the plan), applied with
 * WordPress' own functions (wp_update_nav_menu_object, wp_update_nav_menu_item,
 * wp_delete_nav_menu, set_theme_mod) and logged with a snapshot of the WHOLE menu
 * (term, items, locations) before and after, so a revert is simple and exact on
 * title, url, parent and order. A revert refuses with crayn_safe_revert_conflict
 * when the menu changed after the logged write. Item and menu ids can change when
 * a deleted menu or item is recreated by a revert; the parents of the recreated
 * items are re-mapped to the new ids.
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "crayn-settings" menu abilities.
 */
class MenuAbilities extends AbilitiesBase {

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		return array(
			'crayn-safe/menu-list',
			'crayn-safe/menu-get',
			'crayn-safe/menu-create',
			'crayn-safe/menu-update',
			'crayn-safe/menu-delete',
			'crayn-safe/menu-item-add',
			'crayn-safe/menu-item-update',
			'crayn-safe/menu-item-move',
			'crayn-safe/menu-item-remove',
			'crayn-safe/menu-locations-set',
		);
	}

	// ---------------------------------------------------------------------
	// Registration
	// ---------------------------------------------------------------------

	/**
	 * Schema fragment: a menu reference.
	 *
	 * @return array<string,mixed>
	 */
	private static function menu_property() {
		return array(
			'type'        => array( 'integer', 'string' ),
			'description' => 'Menu id, slug or name (see menu-list).',
		);
	}

	/**
	 * Schema fragment: the writable fields of an item.
	 *
	 * @return array<string,mixed>
	 */
	private static function item_fields() {
		return array(
			'title'       => array( 'type' => 'string', 'description' => 'Link text. Empty on a page/post/term item = use the title of the target.' ),
			'target'      => array( 'type' => 'string', 'enum' => array( '', '_blank' ), 'description' => '"_blank" opens in a new tab.' ),
			'classes'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'CSS classes of the menu item.' ),
			'attr_title'  => array( 'type' => 'string', 'description' => 'Title attribute of the link.' ),
			'description' => array( 'type' => 'string', 'description' => 'Item description.' ),
			'xfn'         => array( 'type' => 'string', 'description' => 'XFN link relationship, e.g. "nofollow".' ),
		);
	}

	/**
	 * Output properties shared by the menu write abilities.
	 *
	 * @return array<string,mixed>
	 */
	private static function write_output() {
		return array(
			'menu_id' => array( 'type' => 'integer', 'description' => 'Id of the menu (new id for menu-create).' ),
			'item_id' => array( 'type' => 'integer', 'description' => 'Id of the added / updated / moved / removed item.' ),
			'warning' => array( 'type' => 'string' ),
			'note'    => array( 'type' => 'string' ),
		);
	}

	/**
	 * Register one ability.
	 *
	 * @param string $name        Ability name.
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @param array  $properties  Input properties.
	 * @param array  $required    Required inputs.
	 * @param string $callback    Static method name.
	 * @param bool   $write       Write ability.
	 * @param bool   $destructive Destructive hint.
	 * @param bool   $idempotent  Idempotent hint.
	 * @param array  $output      Custom output schema (reads).
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
				'category'            => 'crayn-settings',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => $required,
				),
				'output_schema'       => null === $output ? self::change_output_schema( self::write_output() ) : $output,
				'execute_callback'    => array( self::class, $callback ),
				'permission_callback' => array( self::class, 'permission' ),
				'meta'                => Safety::meta( Safety::annotations( ! $write, $destructive, $idempotent, $label ) ),
			)
		);
	}

	/**
	 * Register every menu ability.
	 *
	 * @return void
	 */
	public static function register() {
		self::define(
			'crayn-safe/menu-list',
			'List menus',
			'List the navigation menus (id, name, slug, item count, assigned theme locations) and the theme locations (slug, label, assigned menu). Read only.',
			array(),
			array(),
			'execute_menu_list',
			false,
			false,
			true,
			array(
				'type'       => 'object',
				'properties' => array(
					'menus'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'locations' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'theme'     => array( 'type' => 'string', 'description' => 'Active theme; location assignments are stored per theme.' ),
				),
			)
		);
		self::define(
			'crayn-safe/menu-get',
			'Get menu',
			'Get one menu with its items nested by parent: id, title, url, type (custom|post_type|taxonomy), object, object_id, parent, order, classes, target, attr_title, description, xfn, status, children. Read only.',
			array( 'menu' => self::menu_property() ),
			array( 'menu' ),
			'execute_menu_get',
			false,
			false,
			true,
			array(
				'type'       => 'object',
				'properties' => array(
					'menu'      => array( 'type' => 'object' ),
					'locations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'items'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'count'     => array( 'type' => 'integer' ),
				),
			)
		);
		self::define(
			'crayn-safe/menu-create',
			'Create menu',
			'Create an empty navigation menu. The name must be unique. Assign it to a theme location with menu-locations-set. Accepts dry_run; logged with change_id (revert deletes the empty menu).',
			array(
				'name'        => array( 'type' => 'string', 'description' => 'Menu name (unique).' ),
				'description' => array( 'type' => 'string', 'description' => 'Optional description.' ),
			),
			array( 'name' ),
			'execute_menu_create',
			true,
			false,
			false
		);
		self::define(
			'crayn-safe/menu-update',
			'Rename menu',
			'Change the name and/or description of a menu. The slug stays as it is (templates may reference it). Accepts dry_run; logged with change_id.',
			array(
				'menu'        => self::menu_property(),
				'name'        => array( 'type' => 'string', 'description' => 'New name (unique).' ),
				'description' => array( 'type' => 'string', 'description' => 'New description.' ),
			),
			array( 'menu' ),
			'execute_menu_update',
			true,
			false,
			true
		);
		self::define(
			'crayn-safe/menu-delete',
			'Delete menu',
			'Delete a menu with all its items; the theme locations that pointed to it become empty. The whole menu (items, parents, order, locations) is kept in the change log and change-revert recreates it. A revert gives the menu and its items NEW ids (parents are re-mapped); widgets/blocks that stored the old menu id are not re-pointed. Accepts dry_run; logged with change_id.',
			array( 'menu' => self::menu_property() ),
			array( 'menu' ),
			'execute_menu_delete',
			true,
			true,
			true
		);
		self::define(
			'crayn-safe/menu-item-add',
			'Add menu item',
			'Add an item to a menu: type "custom" (title + url), "page" or "post" (object_id; post_type for other public post types), or "term" (object_id + taxonomy, default category). The target must exist. parent is an item id of the same menu (default top level); position is 0-based among the siblings under that parent (default last). Accepts dry_run; logged with change_id.',
			array_merge(
				array(
					'menu'      => self::menu_property(),
					'type'      => array( 'type' => 'string', 'enum' => array( 'custom', 'page', 'post', 'term' ), 'description' => 'Item kind.' ),
					'url'       => array( 'type' => 'string', 'description' => 'Link for type "custom" (http(s), relative path, #anchor, mailto:, tel:).' ),
					'object_id' => array( 'type' => 'integer', 'description' => 'Page / post / term id for the other types.' ),
					'post_type' => array( 'type' => 'string', 'description' => 'For type "post": the public post type (default "post").' ),
					'taxonomy'  => array( 'type' => 'string', 'description' => 'For type "term": the taxonomy (default "category").' ),
					'parent'    => array( 'type' => 'integer', 'description' => 'Parent item id in this menu; 0 or omitted = top level.' ),
					'position'  => array( 'type' => 'integer', 'description' => '0-based position among the siblings. Omit to append.' ),
				),
				self::item_fields()
			),
			array( 'menu', 'type' ),
			'execute_menu_item_add',
			true,
			false,
			false
		);
		self::define(
			'crayn-safe/menu-item-update',
			'Update menu item',
			'Change the text, target, classes, title attribute, description or XFN of an item; "url" only for custom items. Only given fields change. Accepts dry_run; logged with change_id.',
			array_merge(
				array(
					'item_id' => array( 'type' => 'integer', 'description' => 'Menu item id (see menu-get).' ),
					'url'     => array( 'type' => 'string', 'description' => 'New link (custom items only).' ),
				),
				self::item_fields()
			),
			array( 'item_id' ),
			'execute_menu_item_update',
			true,
			false,
			true
		);
		self::define(
			'crayn-safe/menu-item-move',
			'Move menu item',
			'Move an item (with its sub-items) to another parent of the same menu and/or position. parent 0 = top level; position is 0-based among the new siblings (omit = last). Moving an item into itself or its own descendants is refused. Accepts dry_run; logged with change_id.',
			array(
				'item_id'  => array( 'type' => 'integer', 'description' => 'Menu item id.' ),
				'parent'   => array( 'type' => 'integer', 'description' => 'New parent item id; 0 = top level.' ),
				'position' => array( 'type' => 'integer', 'description' => '0-based position among the new siblings. Omit to append.' ),
			),
			array( 'item_id', 'parent' ),
			'execute_menu_item_move',
			true,
			false,
			false
		);
		self::define(
			'crayn-safe/menu-item-remove',
			'Remove menu item',
			'Remove an item from its menu. Its sub-items move up to the removed item\'s parent. The item and the previous parents are kept in the change log; change-revert restores the item (with a new id) and the parents of its children. Accepts dry_run; logged with change_id.',
			array( 'item_id' => array( 'type' => 'integer', 'description' => 'Menu item id.' ) ),
			array( 'item_id' ),
			'execute_menu_item_remove',
			true,
			true,
			true
		);
		self::define(
			'crayn-safe/menu-locations-set',
			'Set menu locations',
			'Assign menus to theme locations or clear them. "locations" maps a registered location slug to a menu (id, slug or name), or to 0 / "" to unassign. Unknown locations and menus are refused. The assignment is stored per theme. Accepts dry_run; logged with change_id (revert restores exactly the touched locations).',
			array(
				'locations' => array(
					'type'        => 'object',
					'description' => 'Map location slug => menu id|slug|name, or 0 to unassign. Locations: menu-list.',
				),
			),
			array( 'locations' ),
			'execute_menu_locations_set',
			true,
			false,
			true,
			null
		);
	}

	/**
	 * Permission for every menu ability.
	 *
	 * @param array $input Optional input.
	 * @return bool
	 */
	public static function permission( $input = array() ) {
		return current_user_can( 'edit_theme_options' ) && null === Safety::guard( 'crayn-settings', false );
	}

	/**
	 * Common start of every execute: guard + capability.
	 *
	 * @param bool $write True for writes.
	 * @param bool $dry   Dry run.
	 * @return \WP_Error|null
	 */
	private static function begin( $write, $dry = false ) {
		$guard = Safety::guard( 'crayn-settings', $write, $dry );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'Editing menus requires the edit_theme_options capability.' );
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Pure model helpers (no WordPress calls)
	// ---------------------------------------------------------------------

	/**
	 * An empty snapshot (a menu that does not exist).
	 *
	 * @return array
	 */
	private static function empty_snapshot() {
		return array(
			'menu'      => null,
			'items'     => array(),
			'locations' => array(),
		);
	}

	/**
	 * Index items by id.
	 *
	 * @param array $items Items.
	 * @return array<int,array>
	 */
	private static function by_id( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			$out[ (int) $item['id'] ] = $item;
		}
		return $out;
	}

	/**
	 * Siblings of a parent, sorted by order then id.
	 *
	 * @param array $items  Items.
	 * @param int   $parent Parent item id (0 = top level).
	 * @return array
	 */
	private static function siblings( array $items, $parent ) {
		$ids = self::by_id( $items );
		$out = array();
		foreach ( $items as $item ) {
			$p = (int) $item['parent'];
			// An item whose parent is not in the menu counts as a top-level item.
			if ( 0 !== $p && ! isset( $ids[ $p ] ) ) {
				$p = 0;
			}
			if ( $p === (int) $parent ) {
				$out[] = $item;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				if ( (int) $a['order'] !== (int) $b['order'] ) {
					return (int) $a['order'] < (int) $b['order'] ? -1 : 1;
				}
				return (int) $a['id'] < (int) $b['id'] ? -1 : 1;
			}
		);
		return $out;
	}

	/**
	 * Ids of all descendants of an item.
	 *
	 * @param array $items Items.
	 * @param int   $id    Item id.
	 * @return int[]
	 */
	private static function descendants( array $items, $id ) {
		$out = array();
		foreach ( self::siblings( $items, (int) $id ) as $child ) {
			$out[] = (int) $child['id'];
			$out   = array_merge( $out, self::descendants( $items, (int) $child['id'] ) );
		}
		return $out;
	}

	/**
	 * Renumber the order of all items 1..n in depth-first order (parents before
	 * children, the order WordPress' menu walker needs), repairing orphans.
	 *
	 * @param array $items Items.
	 * @return array Items sorted by the new order.
	 */
	private static function canonicalize( array $items ) {
		$ids = self::by_id( $items );
		foreach ( $ids as $id => $item ) {
			if ( 0 !== (int) $item['parent'] && ! isset( $ids[ (int) $item['parent'] ] ) ) {
				$ids[ $id ]['parent'] = 0;
			}
		}
		$items = array_values( $ids );
		$out   = array();
		$n     = 1;
		self::walk( $items, 0, $n, $out );
		return $out;
	}

	/**
	 * Depth-first walk assigning orders.
	 *
	 * @param array $items  Items.
	 * @param int   $parent Parent id.
	 * @param int   $n      Counter (by ref).
	 * @param array $out    Collector (by ref).
	 * @return void
	 */
	private static function walk( array $items, $parent, &$n, array &$out ) {
		foreach ( self::siblings( $items, $parent ) as $item ) {
			$item['order'] = $n++;
			$out[]         = $item;
			self::walk( $items, (int) $item['id'], $n, $out );
		}
	}

	/**
	 * Put an item under a parent at a sibling index (null = last), then renumber.
	 *
	 * @param array    $items  Items (the item is among them).
	 * @param int      $id     Item id.
	 * @param int      $parent New parent id.
	 * @param int|null $index  0-based position among the new siblings.
	 * @return array Items.
	 */
	private static function place( array $items, $id, $parent, $index ) {
		$map            = self::by_id( $items );
		$map[ $id ]['parent'] = (int) $parent;
		$items          = array_values( $map );
		$sibs           = array();
		foreach ( self::siblings( $items, (int) $parent ) as $s ) {
			if ( (int) $s['id'] !== (int) $id ) {
				$sibs[] = (int) $s['id'];
			}
		}
		if ( null === $index || $index >= count( $sibs ) ) {
			$sibs[] = (int) $id;
		} else {
			array_splice( $sibs, max( 0, (int) $index ), 0, array( (int) $id ) );
		}
		foreach ( $sibs as $pos => $sid ) {
			$map[ $sid ]['order'] = $pos + 1;
		}
		return self::canonicalize( array_values( $map ) );
	}

	/**
	 * Does an item array differ from another in anything but its id?
	 *
	 * @param array $a Item.
	 * @param array $b Item.
	 * @return bool
	 */
	private static function item_differs( array $a, array $b ) {
		unset( $a['id'], $b['id'] );
		return wp_json_encode( $a ) !== wp_json_encode( $b );
	}

	/**
	 * Compare two values by their JSON text.
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * Snapshot with item ids replaced by their position, for comparing a restored
	 * menu whose items got new ids. Custom items carry their own id as object_id; that is ignored.
	 *
	 * @param array $snapshot Snapshot.
	 * @return array
	 */
	private static function normalized( array $snapshot ) {
		$items = self::canonical_sorted( $snapshot['items'] );
		$ord   = array();
		foreach ( $items as $i => $item ) {
			$ord[ (int) $item['id'] ] = $i + 1;
		}
		$out = array();
		foreach ( $items as $item ) {
			$item['id']     = $ord[ (int) $item['id'] ];
			$item['parent'] = ( 0 !== (int) $item['parent'] && isset( $ord[ (int) $item['parent'] ] ) ) ? $ord[ (int) $item['parent'] ] : 0;
			if ( 'custom' === $item['type'] ) {
				$item['object_id'] = 0;
			}
			$out[] = $item;
		}
		$snapshot['items'] = $out;
		if ( is_array( $snapshot['menu'] ) ) {
			unset( $snapshot['menu']['term_id'] );
		}
		return $snapshot;
	}

	/**
	 * Items sorted by order then id.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	private static function canonical_sorted( array $items ) {
		usort(
			$items,
			static function ( $a, $b ) {
				if ( (int) $a['order'] !== (int) $b['order'] ) {
					return (int) $a['order'] < (int) $b['order'] ? -1 : 1;
				}
				return (int) $a['id'] < (int) $b['id'] ? -1 : 1;
			}
		);
		return $items;
	}

	// ---------------------------------------------------------------------
	// Reading menus
	// ---------------------------------------------------------------------

	/**
	 * Resolve a menu reference (id, slug or name) to its term.
	 *
	 * @param mixed $ref Reference.
	 * @return \WP_Term|\WP_Error
	 */
	private static function resolve_menu( $ref ) {
		if ( null === $ref || '' === $ref || false === $ref || array() === $ref || is_array( $ref ) || is_object( $ref ) ) {
			return new \WP_Error( 'crayn_safe_menu_required', 'A menu (id, slug or name) is required. See menu-list.' );
		}
		$menu = wp_get_nav_menu_object( is_numeric( $ref ) ? (int) $ref : (string) $ref );
		if ( ! $menu || is_wp_error( $menu ) ) {
			return new \WP_Error( 'crayn_safe_menu_not_found', sprintf( 'Menu "%s" does not exist. See menu-list.', is_scalar( $ref ) ? (string) $ref : '' ) );
		}
		return $menu;
	}

	/**
	 * Slugs of the theme locations that point to a menu.
	 *
	 * @param int $menu_id Menu id.
	 * @return string[]
	 */
	private static function locations_of( $menu_id ) {
		$out = array();
		foreach ( get_nav_menu_locations() as $slug => $id ) {
			if ( (int) $id === (int) $menu_id ) {
				$out[] = (string) $slug;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Raw stored state of one menu item.
	 *
	 * @param \WP_Post $post Item post.
	 * @return array
	 */
	private static function item_from_post( \WP_Post $post ) {
		$id      = (int) $post->ID;
		$classes = get_post_meta( $id, '_menu_item_classes', true );
		// WordPress stores [""] for an item without classes; treat empty entries as no class.
		$classes = is_array( $classes ) ? array_values( array_filter( array_map( 'strval', array_filter( $classes, 'is_scalar' ) ), 'strlen' ) ) : array();
		return array(
			'id'          => $id,
			'status'      => (string) $post->post_status,
			'order'       => (int) $post->menu_order,
			'parent'      => (int) get_post_meta( $id, '_menu_item_menu_item_parent', true ),
			'type'        => (string) get_post_meta( $id, '_menu_item_type', true ),
			'object'      => (string) get_post_meta( $id, '_menu_item_object', true ),
			'object_id'   => (int) get_post_meta( $id, '_menu_item_object_id', true ),
			'url'         => (string) get_post_meta( $id, '_menu_item_url', true ),
			'target'      => (string) get_post_meta( $id, '_menu_item_target', true ),
			'classes'     => $classes,
			'xfn'         => (string) get_post_meta( $id, '_menu_item_xfn', true ),
			'title'       => (string) $post->post_title,
			'description' => (string) $post->post_content,
			'attr_title'  => (string) $post->post_excerpt,
		);
	}

	/**
	 * Snapshot of a whole menu: term fields, all items (published and draft) in
	 * stored order, and the locations assigned to it. Null when the menu does not exist.
	 *
	 * @param int $menu_id Menu term id.
	 * @return array|null
	 */
	private static function snapshot( $menu_id ) {
		$menu = wp_get_nav_menu_object( (int) $menu_id );
		if ( ! $menu || is_wp_error( $menu ) ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'        => 'nav_menu_item',
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => -1,
				'orderby'          => 'menu_order ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Menu items are only linked to their menu through the nav_menu taxonomy.
					array(
						'taxonomy' => 'nav_menu',
						'field'    => 'term_id',
						'terms'    => array( (int) $menu->term_id ),
					),
				),
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			$items[] = self::item_from_post( $post );
		}
		return array(
			'menu'      => array(
				'term_id'     => (int) $menu->term_id,
				'name'        => (string) $menu->name,
				'slug'        => (string) $menu->slug,
				'description' => (string) $menu->description,
			),
			'items'     => $items,
			'locations' => self::locations_of( (int) $menu->term_id ),
		);
	}

	/**
	 * Current snapshot of a menu for the revert's own log row.
	 *
	 * @param string $ability Ability of the reverted change.
	 * @param int    $menu_id Menu id (0 for locations).
	 * @return array|null
	 */
	public static function current_snapshot( $ability, $menu_id ) {
		if ( 'crayn-safe/menu-locations-set' === $ability ) {
			return array( 'locations' => self::current_locations_raw() );
		}
		$snap = self::snapshot( (int) $menu_id );
		return null === $snap ? self::empty_snapshot() : $snap;
	}

	/**
	 * The menu locations theme mod as slug => int (absent slugs are not listed).
	 *
	 * @return array<string,int>
	 */
	private static function current_locations_raw() {
		$out = array();
		foreach ( get_nav_menu_locations() as $slug => $id ) {
			$out[ (string) $slug ] = (int) $id;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Compact view of a snapshot for results (a missing menu gives an empty view).
	 *
	 * @param array|null $snapshot Snapshot.
	 * @return array
	 */
	private static function view( $snapshot ) {
		if ( null === $snapshot || null === $snapshot['menu'] ) {
			return array(
				'menu'      => null,
				'locations' => array(),
				'items'     => 0,
				'outline'   => array(),
			);
		}
		$lines = array();
		self::outline_lines( $snapshot['items'], 0, 0, $lines );
		return array(
			'menu'      => $snapshot['menu'],
			'locations' => $snapshot['locations'],
			'items'     => count( $snapshot['items'] ),
			'outline'   => $lines,
		);
	}

	/**
	 * Indented outline lines of a menu.
	 *
	 * @param array $items  Items.
	 * @param int   $parent Parent id.
	 * @param int   $depth  Depth.
	 * @param array $lines  Collector (by ref).
	 * @return void
	 */
	private static function outline_lines( array $items, $parent, $depth, array &$lines ) {
		foreach ( self::siblings( $items, $parent ) as $item ) {
			$label   = '' !== $item['title'] ? $item['title'] : '(' . $item['object'] . ' ' . $item['object_id'] . ')';
			$lines[] = str_repeat( '  ', $depth ) . ( (int) $item['id'] > 0 ? '[' . (int) $item['id'] . '] ' : '[new] ' ) . $label . ' <' . $item['type'] . ( '' !== $item['url'] ? ' ' . $item['url'] : '' ) . '>' . ( 'draft' === $item['status'] ? ' (draft)' : '' );
			self::outline_lines( $items, (int) $item['id'], $depth + 1, $lines );
		}
	}

	/**
	 * Execute menu-list.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_list( $input ) {
		$error = self::begin( false );
		if ( null !== $error ) {
			return $error;
		}
		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = array(
				'id'          => (int) $menu->term_id,
				'name'        => (string) $menu->name,
				'slug'        => (string) $menu->slug,
				'description' => (string) $menu->description,
				'item_count'  => (int) $menu->count,
				'locations'   => self::locations_of( (int) $menu->term_id ),
			);
		}
		$assigned  = get_nav_menu_locations();
		$locations = array();
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$mid         = isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0;
			$menu        = $mid > 0 ? wp_get_nav_menu_object( $mid ) : false;
			$locations[] = array(
				'slug'      => (string) $slug,
				'label'     => (string) $label,
				'menu_id'   => $menu ? $mid : 0,
				'menu_name' => $menu ? (string) $menu->name : '',
			);
		}
		return array(
			'menus'     => $menus,
			'locations' => $locations,
			'theme'     => (string) get_stylesheet(),
		);
	}

	/**
	 * Execute menu-get.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_get( $input ) {
		$error = self::begin( false );
		if ( null !== $error ) {
			return $error;
		}
		$menu = self::resolve_menu( isset( $input['menu'] ) ? $input['menu'] : null );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$snap = self::snapshot( (int) $menu->term_id );
		if ( null === $snap ) {
			return new \WP_Error( 'crayn_safe_menu_not_found', 'The menu disappeared while it was read.' );
		}
		return array(
			'menu'      => $snap['menu'],
			'locations' => $snap['locations'],
			'items'     => self::nested( $snap['items'], 0 ),
			'count'     => count( $snap['items'] ),
		);
	}

	/**
	 * Nested item list for menu-get. title/url are the displayed values (what
	 * wp_setup_nav_menu_item() resolves for page/post/term items).
	 *
	 * @param array $items  Items.
	 * @param int   $parent Parent id.
	 * @return array
	 */
	private static function nested( array $items, $parent ) {
		$out = array();
		foreach ( self::siblings( $items, $parent ) as $item ) {
			$shown_title = $item['title'];
			$shown_url   = $item['url'];
			$post        = get_post( (int) $item['id'] );
			if ( $post instanceof \WP_Post ) {
				$decorated = wp_setup_nav_menu_item( $post );
				if ( is_object( $decorated ) ) {
					$shown_title = isset( $decorated->title ) ? (string) $decorated->title : $shown_title;
					$shown_url   = isset( $decorated->url ) ? (string) $decorated->url : $shown_url;
				}
			}
			$out[] = array(
				'id'          => (int) $item['id'],
				'title'       => $shown_title,
				'custom_title' => $item['title'],
				'url'         => $shown_url,
				'type'        => $item['type'],
				'object'      => $item['object'],
				'object_id'   => 'custom' === $item['type'] ? 0 : (int) $item['object_id'],
				'parent'      => (int) $item['parent'],
				'order'       => (int) $item['order'],
				'classes'     => $item['classes'],
				'target'      => $item['target'],
				'attr_title'  => $item['attr_title'],
				'description' => trim( $item['description'] ),
				'xfn'         => $item['xfn'],
				'status'      => $item['status'],
				'children'    => self::nested( $items, (int) $item['id'] ),
			);
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Applying a plan
	// ---------------------------------------------------------------------

	/**
	 * Arguments of wp_update_nav_menu_item() for a planned item. Title, description
	 * and title attribute are slashed: the function passes them to wp_insert_post(),
	 * which unslashes (wp-includes/nav-menu.php:405-407, 525-546).
	 *
	 * @param array $item   Planned item.
	 * @param int   $parent Actual parent id.
	 * @return array
	 */
	private static function item_args( array $item, $parent ) {
		return array(
			'menu-item-type'        => $item['type'],
			'menu-item-object'      => $item['object'],
			'menu-item-object-id'   => (int) $item['object_id'],
			'menu-item-parent-id'   => (int) $parent,
			'menu-item-position'    => (int) $item['order'],
			'menu-item-title'       => wp_slash( $item['title'] ),
			'menu-item-url'         => $item['url'],
			'menu-item-description' => wp_slash( $item['description'] ),
			'menu-item-attr-title'  => wp_slash( $item['attr_title'] ),
			'menu-item-target'      => $item['target'],
			'menu-item-classes'     => implode( ' ', $item['classes'] ),
			'menu-item-xfn'         => $item['xfn'],
			// New items default to draft in wp_update_nav_menu_item(); keep the planned status.
			'menu-item-status'      => 'draft' === $item['status'] ? 'draft' : 'publish',
		);
	}

	/**
	 * Bring the items of a menu from the current list to the wanted list: delete the
	 * missing, create the new (negative or unknown ids), update what differs, and
	 * map parents to the real ids.
	 *
	 * @param int   $menu_id Menu term id.
	 * @param array $current Current items.
	 * @param array $want    Wanted items (ids that are not current are created).
	 * @param bool  $restore Reverting: recreate a deleted item under its old id. Items
	 *                       with an equal menu_order are read back by id, so a new id
	 *                       would change their order.
	 * @return array|\WP_Error Map wanted id => real id.
	 */
	private static function sync_items( $menu_id, array $current, array $want, $restore = false ) {
		$cur = self::by_id( $current );
		$wnt = self::by_id( $want );
		$map = array();

		foreach ( array_keys( $cur ) as $id ) {
			if ( ! isset( $wnt[ $id ] ) ) {
				$deleted = wp_delete_post( (int) $id, true );
				if ( ! $deleted ) {
					return new \WP_Error( 'crayn_safe_menu_item_delete_failed', sprintf( 'Menu item %d could not be deleted.', (int) $id ) );
				}
			}
		}

		// Create missing items first (flat), so parents can be mapped afterwards.
		foreach ( $want as $item ) {
			$wid = (int) $item['id'];
			if ( isset( $cur[ $wid ] ) ) {
				$map[ $wid ] = $wid;
				continue;
			}
			$reuse = 0;
			if ( $restore && $wid > 0 && ! get_post( $wid ) ) {
				$reuse = (int) wp_insert_post(
					array(
						'import_id'   => $wid,
						'post_type'   => 'nav_menu_item',
						'post_status' => 'draft',
						'post_title'  => wp_slash( $item['title'] ),
					)
				);
			}
			$new = wp_update_nav_menu_item( (int) $menu_id, $reuse, self::item_args( $item, 0 ) );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			if ( ! $new ) {
				return new \WP_Error( 'crayn_safe_menu_item_create_failed', 'A menu item could not be created.' );
			}
			$map[ $wid ] = (int) $new;
		}

		// Write everything that is new or differs, with the real parent ids.
		foreach ( $want as $item ) {
			$wid    = (int) $item['id'];
			$real   = $map[ $wid ];
			$parent = ( (int) $item['parent'] > 0 && isset( $map[ (int) $item['parent'] ] ) ) ? $map[ (int) $item['parent'] ] : 0;
			if ( isset( $cur[ $wid ] ) ) {
				$planned           = $item;
				$planned['parent'] = $parent;
				if ( ! self::item_differs( $cur[ $wid ], $planned ) ) {
					continue;
				}
			}
			$updated = wp_update_nav_menu_item( (int) $menu_id, $real, self::item_args( $item, $parent ) );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			if ( 0 === (int) $item['order'] ) {
				// Position 0 means "append" to wp_update_nav_menu_item(); store the real 0.
				wp_update_post( array( 'ID' => $real, 'menu_order' => 0 ) );
			}
		}
		return $map;
	}

	/**
	 * Write the menu term fields (name, description, slug) if they differ.
	 *
	 * @param int   $menu_id Menu id.
	 * @param array $fields  { name, slug, description }.
	 * @return true|\WP_Error
	 */
	private static function sync_menu_fields( $menu_id, array $fields ) {
		$menu = wp_get_nav_menu_object( (int) $menu_id );
		if ( ! $menu || is_wp_error( $menu ) ) {
			return new \WP_Error( 'crayn_safe_menu_not_found', 'The menu does not exist.' );
		}
		if ( $menu->name !== $fields['name'] || $menu->description !== $fields['description'] ) {
			$res = wp_update_nav_menu_object(
				(int) $menu_id,
				array(
					'menu-name'   => wp_slash( $fields['name'] ),
					'description' => wp_slash( $fields['description'] ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		// wp_update_nav_menu_object() regenerates the slug from the name; restore the wanted one.
		$menu = wp_get_nav_menu_object( (int) $menu_id );
		if ( $menu && $menu->slug !== $fields['slug'] ) {
			$res = wp_update_term( (int) $menu_id, 'nav_menu', array( 'slug' => $fields['slug'] ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		return true;
	}

	/**
	 * Set or clear entries of the nav_menu_locations theme mod.
	 *
	 * @param array $changes Map slug => menu id (int), or null to remove the entry.
	 * @return void
	 */
	private static function write_locations( array $changes ) {
		$map = get_nav_menu_locations();
		foreach ( $changes as $slug => $id ) {
			if ( null === $id ) {
				unset( $map[ $slug ] );
			} else {
				$map[ $slug ] = (int) $id;
			}
		}
		set_theme_mod( 'nav_menu_locations', $map );
	}

	/**
	 * Run the common tail of an item/menu write that keeps the menu id:
	 * snapshot, plan, dry run, apply, verify, log.
	 *
	 * @param int      $menu_id Menu id.
	 * @param string   $ability Ability name.
	 * @param callable $plan    Receives the current snapshot, returns the wanted snapshot or WP_Error.
	 * @param string   $note    Log note.
	 * @param bool     $dry     Dry run.
	 * @param array    $extra   Extra output keys.
	 * @return array|\WP_Error
	 */
	private static function apply_menu_change( $menu_id, $ability, $plan, $note, $dry, array $extra = array() ) {
		$before = self::snapshot( (int) $menu_id );
		if ( null === $before ) {
			return new \WP_Error( 'crayn_safe_menu_not_found', 'The menu does not exist.' );
		}
		$want = call_user_func( $plan, $before );
		if ( is_wp_error( $want ) ) {
			return $want;
		}
		$base = array_merge( array( 'menu_id' => (int) $menu_id ), $extra );
		if ( isset( $base['item_id'] ) && (int) $base['item_id'] < 0 ) {
			$base['item_id'] = 0; // A new item has no id before it is written.
		}

		if ( self::same( $before, $want ) ) {
			return array_merge(
				$base,
				array(
					'dry_run'   => (bool) $dry,
					'before'    => self::view( $before ),
					'after'     => self::view( $before ),
					'change_id' => 0,
					'note'      => 'Nothing to change.',
				)
			);
		}
		if ( $dry ) {
			return array_merge(
				$base,
				array(
					'dry_run'   => true,
					'before'    => self::view( $before ),
					'after'     => self::view( $want ),
					'change_id' => 0,
				)
			);
		}

		$fields = self::sync_menu_fields( (int) $menu_id, $want['menu'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$map = self::sync_items( (int) $menu_id, $before['items'], $want['items'] );
		if ( is_wp_error( $map ) ) {
			return new \WP_Error( 'crayn_safe_menu_apply_failed', 'The menu was only partly changed: ' . $map->get_error_message() . ' Check it with menu-get.' );
		}
		$after = self::snapshot( (int) $menu_id );
		if ( null === $after ) {
			return new \WP_Error( 'crayn_safe_menu_apply_failed', 'The menu could not be read back after the write. Check it with menu-get.' );
		}
		// The change is applied, so it is logged whatever the read-back shows.
		$change_id = ChangeLogger::log( $ability, 'menu', (int) $menu_id, $before, $after, $note );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( 'Menu ' . (int) $menu_id . ': ' . $note . '.' );
		}
		// Real ids of new items.
		if ( isset( $extra['item_id'] ) && (int) $extra['item_id'] < 0 && isset( $map[ (int) $extra['item_id'] ] ) ) {
			$base['item_id'] = (int) $map[ (int) $extra['item_id'] ];
		}
		$result = array_merge(
			$base,
			array(
				'dry_run'   => false,
				'before'    => self::view( $before ),
				'after'     => self::view( $after ),
				'change_id' => (int) $change_id,
			)
		);
		if ( ! self::same( self::normalized( $after ), self::normalized( $want ) ) ) {
			$result['warning'] = 'The stored menu differs from the plan (WordPress filtered or normalized some text). The logged change holds what was really stored.';
		}
		return $result;
	}

	// ---------------------------------------------------------------------
	// Menu writes
	// ---------------------------------------------------------------------

	/**
	 * Validate a menu name.
	 *
	 * @param mixed $name      Name input.
	 * @param int   $exclude   Menu id that may already have this name.
	 * @return string|\WP_Error
	 */
	private static function clean_menu_name( $name, $exclude = 0 ) {
		$clean = trim( sanitize_text_field( is_scalar( $name ) ? (string) $name : '' ) );
		if ( '' === $clean ) {
			return new \WP_Error( 'crayn_safe_menu_name_required', 'A non-empty menu name is required.' );
		}
		if ( strlen( $clean ) > 200 ) {
			return new \WP_Error( 'crayn_safe_menu_name_invalid', 'The menu name is too long (200 characters at most).' );
		}
		$existing = get_term_by( 'name', $clean, 'nav_menu' );
		if ( $existing && ! is_wp_error( $existing ) && (int) $existing->term_id !== (int) $exclude ) {
			return new \WP_Error( 'crayn_safe_menu_exists', sprintf( 'A menu named "%s" already exists (id %d).', $clean, (int) $existing->term_id ) );
		}
		return $clean;
	}

	/**
	 * Execute menu-create.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_create( $input ) {
		$dry   = Safety::dry_run( $input );
		$error = self::begin( true, $dry );
		if ( null !== $error ) {
			return $error;
		}
		$name = self::clean_menu_name( isset( $input['name'] ) ? $input['name'] : '' );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$description = isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '';
		$planned     = array(
			'menu'      => array( 'term_id' => 0, 'name' => $name, 'slug' => sanitize_title( $name ), 'description' => $description ),
			'items'     => array(),
			'locations' => array(),
		);
		if ( $dry ) {
			return array(
				'dry_run'   => true,
				'menu_id'   => 0,
				'before'    => null,
				'after'     => self::view( $planned ),
				'change_id' => 0,
			);
		}
		$id = wp_update_nav_menu_object(
			0,
			array(
				'menu-name'   => wp_slash( $name ),
				'description' => wp_slash( $description ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$after = self::snapshot( (int) $id );
		if ( null === $after ) {
			return new \WP_Error( 'crayn_safe_menu_apply_failed', 'The menu could not be read back after it was created.' );
		}
		$change_id = ChangeLogger::log( 'crayn-safe/menu-create', 'menu', (int) $id, self::empty_snapshot(), $after, sprintf( 'menu "%s" created', $name ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Menu %d was created.', (int) $id ) );
		}
		return array(
			'dry_run'   => false,
			'menu_id'   => (int) $id,
			'before'    => null,
			'after'     => self::view( $after ),
			'change_id' => (int) $change_id,
		);
	}

	/**
	 * Execute menu-update.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_update( $input ) {
		$dry   = Safety::dry_run( $input );
		$error = self::begin( true, $dry );
		if ( null !== $error ) {
			return $error;
		}
		$menu = self::resolve_menu( isset( $input['menu'] ) ? $input['menu'] : null );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		if ( ! isset( $input['name'] ) && ! isset( $input['description'] ) ) {
			return new \WP_Error( 'crayn_safe_nothing_to_do', 'Give "name" and/or "description".' );
		}
		$name = null;
		if ( isset( $input['name'] ) ) {
			$name = self::clean_menu_name( $input['name'], (int) $menu->term_id );
			if ( is_wp_error( $name ) ) {
				return $name;
			}
		}
		$description = isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : null;

		return self::apply_menu_change(
			(int) $menu->term_id,
			'crayn-safe/menu-update',
			static function ( $snap ) use ( $name, $description ) {
				if ( null !== $name ) {
					$snap['menu']['name'] = $name;
				}
				if ( null !== $description ) {
					$snap['menu']['description'] = $description;
				}
				return $snap;
			},
			sprintf( 'menu %d updated', (int) $menu->term_id ),
			$dry
		);
	}

	/**
	 * Execute menu-delete.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_delete( $input ) {
		$dry   = Safety::dry_run( $input );
		$error = self::begin( true, $dry );
		if ( null !== $error ) {
			return $error;
		}
		$menu = self::resolve_menu( isset( $input['menu'] ) ? $input['menu'] : null );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$before = self::snapshot( (int) $menu->term_id );
		if ( null === $before ) {
			return new \WP_Error( 'crayn_safe_menu_not_found', 'The menu does not exist.' );
		}
		$note = 'change-revert recreates the menu and its items with NEW ids (parents are re-mapped); widgets or blocks that stored the old menu id are not re-pointed.';
		if ( $dry ) {
			return array(
				'dry_run'   => true,
				'menu_id'   => (int) $menu->term_id,
				'before'    => self::view( $before ),
				'after'     => self::view( null ),
				'change_id' => 0,
				'note'      => $note,
			);
		}
		$deleted = wp_delete_nav_menu( (int) $menu->term_id );
		if ( ! $deleted || is_wp_error( $deleted ) ) {
			return new \WP_Error( 'crayn_safe_menu_delete_failed', 'The menu could not be deleted.' );
		}
		if ( null !== self::snapshot( (int) $menu->term_id ) ) {
			return new \WP_Error( 'crayn_safe_menu_delete_failed', 'The menu still exists after the delete.' );
		}
		$change_id = ChangeLogger::log( 'crayn-safe/menu-delete', 'menu', (int) $menu->term_id, $before, self::empty_snapshot(), sprintf( 'menu "%s" (%d items) deleted', $menu->name, count( $before['items'] ) ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Menu %d was deleted.', (int) $menu->term_id ) );
		}
		return array(
			'dry_run'   => false,
			'menu_id'   => (int) $menu->term_id,
			'before'    => self::view( $before ),
			'after'     => self::view( null ),
			'change_id' => (int) $change_id,
			'note'      => $note,
			'warning'   => array() === $before['locations'] ? '' : 'The theme location(s) ' . implode( ', ', $before['locations'] ) . ' have no menu now.',
		);
	}

	// ---------------------------------------------------------------------
	// Item writes
	// ---------------------------------------------------------------------

	/**
	 * Read the item fields shared by add and update from the input, sanitized.
	 * Only keys present in the input are returned.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	private static function item_field_changes( array $input ) {
		$out = array();
		if ( isset( $input['title'] ) ) {
			$out['title'] = trim( sanitize_text_field( (string) $input['title'] ) );
		}
		if ( isset( $input['attr_title'] ) ) {
			$out['attr_title'] = trim( sanitize_text_field( (string) $input['attr_title'] ) );
		}
		if ( isset( $input['description'] ) ) {
			$out['description'] = sanitize_textarea_field( (string) $input['description'] );
		}
		if ( isset( $input['target'] ) ) {
			if ( ! in_array( (string) $input['target'], array( '', '_blank' ), true ) ) {
				return new \WP_Error( 'crayn_safe_menu_item_invalid', 'target must be "" or "_blank".' );
			}
			$out['target'] = (string) $input['target'];
		}
		if ( isset( $input['classes'] ) ) {
			$list = is_array( $input['classes'] ) ? $input['classes'] : explode( ' ', (string) $input['classes'] );
			$out['classes'] = array_values( array_filter( array_map( 'sanitize_html_class', array_map( 'strval', $list ) ), 'strlen' ) );
		}
		if ( isset( $input['xfn'] ) ) {
			$tokens     = array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) $input['xfn'] ) ), 'strlen' );
			$out['xfn'] = implode( ' ', $tokens );
		}
		return $out;
	}

	/**
	 * A link for a custom item: http(s), relative path, anchor, mailto:, tel:.
	 *
	 * @param mixed $url URL input.
	 * @return string|\WP_Error
	 */
	private static function clean_url( $url ) {
		$raw = trim( is_scalar( $url ) ? (string) $url : '' );
		if ( '' === $raw ) {
			return new \WP_Error( 'crayn_safe_menu_item_invalid', 'A custom item needs a url.' );
		}
		$clean = sanitize_url( $raw, array( 'http', 'https', 'mailto', 'tel' ) );
		if ( '' === $clean ) {
			return new \WP_Error( 'crayn_safe_menu_item_invalid', sprintf( 'The url "%s" is not a valid link (allowed: http(s), relative paths, #anchors, mailto:, tel:).', $raw ) );
		}
		return $clean;
	}

	/**
	 * Resolve the target of a non-custom item and validate it exists.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error { type, object, object_id, default_title }
	 */
	private static function resolve_target( array $input ) {
		$type      = (string) $input['type'];
		$object_id = isset( $input['object_id'] ) ? (int) $input['object_id'] : 0;
		if ( $object_id <= 0 ) {
			return new \WP_Error( 'crayn_safe_menu_item_invalid', sprintf( 'A "%s" item needs object_id.', $type ) );
		}
		if ( 'page' === $type || 'post' === $type ) {
			$post_type = 'page' === $type ? 'page' : ( isset( $input['post_type'] ) && '' !== $input['post_type'] ? sanitize_key( (string) $input['post_type'] ) : 'post' );
			$pto       = get_post_type_object( $post_type );
			if ( ! $pto || ( empty( $pto->public ) && empty( $pto->show_in_nav_menus ) ) ) {
				return new \WP_Error( 'crayn_safe_menu_item_invalid', sprintf( 'Post type "%s" does not exist or is not public.', $post_type ) );
			}
			$post = get_post( $object_id );
			if ( ! $post instanceof \WP_Post || $post->post_type !== $post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				return new \WP_Error( 'crayn_safe_menu_item_invalid', sprintf( 'There is no %s with id %d.', $post_type, $object_id ) );
			}
			// A menu item shows the title and link: a private post stays hidden from those who cannot read it.
			if ( ! current_user_can( 'read_post', $object_id ) ) {
				return new \WP_Error( 'crayn_safe_permission_denied', sprintf( 'You cannot read %s %d.', $post_type, $object_id ) );
			}
			return array(
				'type'          => 'post_type',
				'object'        => $post_type,
				'object_id'     => $object_id,
				'default_title' => (string) $post->post_title,
			);
		}
		$taxonomy = isset( $input['taxonomy'] ) && '' !== $input['taxonomy'] ? sanitize_key( (string) $input['taxonomy'] ) : 'category';
		$tax      = get_taxonomy( $taxonomy );
		if ( ! $tax || ( empty( $tax->public ) && empty( $tax->show_in_nav_menus ) ) ) {
			return new \WP_Error( 'crayn_safe_menu_item_invalid', sprintf( 'Taxonomy "%s" does not exist or is not public.', $taxonomy ) );
		}
		$term = get_term( $object_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'crayn_safe_menu_item_invalid', sprintf( 'There is no %s term with id %d.', $taxonomy, $object_id ) );
		}
		return array(
			'type'          => 'taxonomy',
			'object'        => $taxonomy,
			'object_id'     => $object_id,
			'default_title' => (string) $term->name,
		);
	}

	/**
	 * Execute menu-item-add.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_item_add( $input ) {
		$dry   = Safety::dry_run( $input );
		$error = self::begin( true, $dry );
		if ( null !== $error ) {
			return $error;
		}
		$menu = self::resolve_menu( isset( $input['menu'] ) ? $input['menu'] : null );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$type = isset( $input['type'] ) ? (string) $input['type'] : '';
		if ( ! in_array( $type, array( 'custom', 'page', 'post', 'term' ), true ) ) {
			return new \WP_Error( 'crayn_safe_menu_item_invalid', 'type must be custom, page, post or term.' );
		}
		$fields = self::item_field_changes( $input );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$item = array(
			'id'          => -1,
			'status'      => 'publish',
			'order'       => 0,
			'parent'      => 0,
			'type'        => 'custom',
			'object'      => 'custom',
			'object_id'   => 0,
			'url'         => '',
			'target'      => '',
			'classes'     => array(),
			'xfn'         => '',
			'title'       => '',
			'description' => '',
			'attr_title'  => '',
		);
		if ( 'custom' === $type ) {
			$url = self::clean_url( isset( $input['url'] ) ? $input['url'] : '' );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			if ( ! isset( $fields['title'] ) || '' === $fields['title'] ) {
				return new \WP_Error( 'crayn_safe_menu_item_invalid', 'A custom item needs a title.' );
			}
			$item['url'] = $url;
		} else {
			$target = self::resolve_target( $input );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$item['type']      = $target['type'];
			$item['object']    = $target['object'];
			$item['object_id'] = $target['object_id'];
		}
		$item = array_merge( $item, $fields );
		// A title equal to the target's own title is stored empty by WordPress (nav-menu.php:514-516); mirror it.
		if ( 'custom' !== $type && isset( $target ) && is_array( $target ) && $item['title'] === $target['default_title'] ) {
			$item['title'] = '';
		}
		if ( 'custom' !== $type && '' === $item['title'] && '' === $item['description'] ) {
			$item['description'] = ' '; // nav-menu.php:518-521: keeps WordPress from dropping the post.
		}

		$parent   = isset( $input['parent'] ) ? (int) $input['parent'] : 0;
		$position = ( isset( $input['position'] ) && is_numeric( $input['position'] ) ) ? max( 0, (int) $input['position'] ) : null;

		return self::apply_menu_change(
			(int) $menu->term_id,
			'crayn-safe/menu-item-add',
			static function ( $snap ) use ( $item, $parent, $position ) {
				$ids = self::by_id( $snap['items'] );
				if ( $parent > 0 && ! isset( $ids[ $parent ] ) ) {
					return new \WP_Error( 'crayn_safe_menu_item_invalid', sprintf( 'Parent item %d is not an item of this menu.', $parent ) );
				}
				$snap['items'][] = $item;
				$snap['items']   = self::place( $snap['items'], -1, $parent, $position );
				return $snap;
			},
			sprintf( '%s item added to menu %d', $type, (int) $menu->term_id ),
			$dry,
			array( 'item_id' => -1 )
		);
	}

	/**
	 * Load an item (and its menu) by item id.
	 *
	 * @param mixed $item_id Item id input.
	 * @return array|\WP_Error { item (array), menu_id }
	 */
	private static function load_item( $item_id ) {
		$item_id = (int) $item_id;
		$post    = $item_id > 0 ? get_post( $item_id ) : null;
		if ( ! $post instanceof \WP_Post || 'nav_menu_item' !== $post->post_type ) {
			return new \WP_Error( 'crayn_safe_menu_item_not_found', sprintf( 'Menu item %d does not exist. See menu-get.', $item_id ) );
		}
		$menu_ids = wp_get_object_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $menu_ids ) || empty( $menu_ids ) ) {
			return new \WP_Error( 'crayn_safe_menu_item_orphaned', sprintf( 'Menu item %d does not belong to a menu.', $item_id ) );
		}
		return array(
			'item'    => self::item_from_post( $post ),
			'menu_id' => (int) $menu_ids[0],
		);
	}

	/**
	 * Execute menu-item-update.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_item_update( $input ) {
		$dry   = Safety::dry_run( $input );
		$error = self::begin( true, $dry );
		if ( null !== $error ) {
			return $error;
		}
		$loaded = self::load_item( isset( $input['item_id'] ) ? $input['item_id'] : 0 );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$changes = self::item_field_changes( $input );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		if ( isset( $input['url'] ) ) {
			if ( 'custom' !== $loaded['item']['type'] ) {
				return new \WP_Error( 'crayn_safe_menu_item_invalid', 'Only custom items have their own url; this item points to a page, post or term.' );
			}
			$url = self::clean_url( $input['url'] );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$changes['url'] = $url;
		}
		if ( array() === $changes ) {
			return new \WP_Error( 'crayn_safe_nothing_to_do', 'Give at least one field to change.' );
		}
		$item_id = (int) $loaded['item']['id'];
		if ( 'custom' === $loaded['item']['type'] && isset( $changes['title'] ) && '' === $changes['title'] ) {
			return new \WP_Error( 'crayn_safe_menu_item_invalid', 'A custom item needs a title.' );
		}

		return self::apply_menu_change(
			$loaded['menu_id'],
			'crayn-safe/menu-item-update',
			static function ( $snap ) use ( $item_id, $changes ) {
				foreach ( $snap['items'] as $i => $item ) {
					if ( (int) $item['id'] === $item_id ) {
						$merged = array_merge( $item, $changes );
						if ( 'custom' !== $merged['type'] && '' === $merged['title'] && '' === trim( $merged['description'] ) ) {
							$merged['description'] = ' ';
						}
						$snap['items'][ $i ] = $merged;
					}
				}
				return $snap;
			},
			sprintf( 'menu item %d updated (%s)', $item_id, implode( ', ', array_keys( $changes ) ) ),
			$dry,
			array( 'item_id' => $item_id )
		);
	}

	/**
	 * Execute menu-item-move.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_item_move( $input ) {
		$dry   = Safety::dry_run( $input );
		$error = self::begin( true, $dry );
		if ( null !== $error ) {
			return $error;
		}
		$loaded = self::load_item( isset( $input['item_id'] ) ? $input['item_id'] : 0 );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$item_id  = (int) $loaded['item']['id'];
		$parent   = isset( $input['parent'] ) ? (int) $input['parent'] : 0;
		$position = ( isset( $input['position'] ) && is_numeric( $input['position'] ) ) ? max( 0, (int) $input['position'] ) : null;

		return self::apply_menu_change(
			$loaded['menu_id'],
			'crayn-safe/menu-item-move',
			static function ( $snap ) use ( $item_id, $parent, $position ) {
				$ids = self::by_id( $snap['items'] );
				if ( $parent > 0 && ! isset( $ids[ $parent ] ) ) {
					return new \WP_Error( 'crayn_safe_menu_item_invalid', sprintf( 'Parent item %d is not an item of the same menu.', $parent ) );
				}
				if ( $parent === $item_id || in_array( $parent, self::descendants( $snap['items'], $item_id ), true ) ) {
					return new \WP_Error( 'crayn_safe_menu_cycle', 'An item cannot be moved into itself or one of its own sub-items.' );
				}
				$snap['items'] = self::place( $snap['items'], $item_id, $parent, $position );
				return $snap;
			},
			sprintf( 'menu item %d moved under %d', $item_id, $parent ),
			$dry,
			array( 'item_id' => $item_id )
		);
	}

	/**
	 * Execute menu-item-remove.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_item_remove( $input ) {
		$dry   = Safety::dry_run( $input );
		$error = self::begin( true, $dry );
		if ( null !== $error ) {
			return $error;
		}
		$loaded = self::load_item( isset( $input['item_id'] ) ? $input['item_id'] : 0 );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$item_id = (int) $loaded['item']['id'];

		return self::apply_menu_change(
			$loaded['menu_id'],
			'crayn-safe/menu-item-remove',
			static function ( $snap ) use ( $item_id ) {
				$ids = self::by_id( $snap['items'] );
				if ( ! isset( $ids[ $item_id ] ) ) {
					return new \WP_Error( 'crayn_safe_menu_item_not_found', 'The item is not in this menu.' );
				}
				$up = (int) $ids[ $item_id ]['parent'];
				if ( 0 !== $up && ! isset( $ids[ $up ] ) ) {
					$up = 0;
				}
				$items = array();
				foreach ( $snap['items'] as $item ) {
					if ( (int) $item['id'] === $item_id ) {
						continue;
					}
					if ( (int) $item['parent'] === $item_id ) {
						$item['parent'] = $up; // The sub-items move up one level.
					}
					$items[] = $item;
				}
				$snap['items'] = self::canonicalize( $items );
				return $snap;
			},
			sprintf( 'menu item %d removed', $item_id ),
			$dry,
			array( 'item_id' => $item_id )
		);
	}

	// ---------------------------------------------------------------------
	// Locations
	// ---------------------------------------------------------------------

	/**
	 * Execute menu-locations-set.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_menu_locations_set( $input ) {
		$dry   = Safety::dry_run( $input );
		$error = self::begin( true, $dry );
		if ( null !== $error ) {
			return $error;
		}
		if ( ! isset( $input['locations'] ) || ! is_array( $input['locations'] ) || array() === $input['locations'] ) {
			return new \WP_Error( 'crayn_safe_locations_required', 'Give "locations": an object of location slug => menu (id, slug or name), or 0 to unassign.' );
		}
		$registered = get_registered_nav_menus();
		$current    = self::current_locations_raw();
		$wanted     = array();
		$problems   = array();
		foreach ( $input['locations'] as $slug => $ref ) {
			$slug = (string) $slug;
			if ( ! isset( $registered[ $slug ] ) ) {
				$problems[] = sprintf( 'Location "%s" is not registered by the active theme (registered: %s).', $slug, array() === $registered ? 'none' : implode( ', ', array_keys( $registered ) ) );
				continue;
			}
			if ( 0 === $ref || '0' === $ref || '' === $ref || null === $ref || false === $ref ) {
				$wanted[ $slug ] = 0;
				continue;
			}
			$menu = self::resolve_menu( $ref );
			if ( is_wp_error( $menu ) ) {
				$problems[] = sprintf( 'Location "%s": %s', $slug, $menu->get_error_message() );
				continue;
			}
			$wanted[ $slug ] = (int) $menu->term_id;
		}
		if ( array() !== $problems ) {
			return new \WP_Error( 'crayn_safe_locations_invalid', 'Nothing was changed. ' . implode( ' ', $problems ) );
		}

		$before = array();
		$after  = array();
		foreach ( $wanted as $slug => $id ) {
			$old = isset( $current[ $slug ] ) ? $current[ $slug ] : null;
			if ( (int) $old === (int) $id && ( null !== $old || 0 === $id ) ) {
				continue; // Already as wanted.
			}
			$before[ $slug ] = $old;
			$after[ $slug ]  = $id;
		}
		$result = array(
			'dry_run'   => (bool) $dry,
			'before'    => (object) $before,
			'after'     => (object) $after,
			'change_id' => 0,
			'note'      => 'Locations are stored per theme (' . get_stylesheet() . ').',
		);
		if ( array() === $after || $dry ) {
			if ( array() === $after ) {
				$result['note'] = 'Nothing to change. ' . $result['note'];
			}
			return $result;
		}
		self::write_locations( $after );
		$now = self::current_locations_raw();
		foreach ( $after as $slug => $id ) {
			$got = isset( $now[ $slug ] ) ? $now[ $slug ] : 0;
			if ( (int) $got !== (int) $id ) {
				self::write_locations( $before );
				return new \WP_Error( 'crayn_safe_locations_failed', sprintf( 'The assignment of "%s" was not stored; the touched locations were restored.', $slug ) );
			}
		}
		$change_id = ChangeLogger::log( 'crayn-safe/menu-locations-set', 'menu', 0, array( 'locations' => $before, 'theme' => get_stylesheet() ), array( 'locations' => $after ), 'menu locations changed: ' . implode( ', ', array_keys( $after ) ) );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( 'Menu locations changed: ' . implode( ', ', array_keys( $after ) ) . '.' );
		}
		$result['change_id'] = (int) $change_id;
		return $result;
	}

	// ---------------------------------------------------------------------
	// Revert (dispatched from ChangeAbilities)
	// ---------------------------------------------------------------------

	/**
	 * Restore a logged menu change. Refuses with crayn_safe_revert_conflict when
	 * the menu (or the touched locations) changed after the logged write.
	 *
	 * @param array $row Change log row (raw columns).
	 * @return string|\WP_Error
	 */
	public static function revert( array $row ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'Reverting menu changes requires the edit_theme_options capability.' );
		}
		$ability = isset( $row['ability'] ) ? (string) $row['ability'] : '';
		$before  = ChangeLogger::decode( isset( $row['before_data'] ) ? $row['before_data'] : '' );
		$after   = ChangeLogger::decode( isset( $row['after_data'] ) ? $row['after_data'] : '' );

		if ( 'crayn-safe/menu-locations-set' === $ability ) {
			return self::revert_locations( $before, $after );
		}
		foreach ( array( $before, $after ) as $snap ) {
			if ( ! array_key_exists( 'menu', $snap ) || ! isset( $snap['items'] ) || ! isset( $snap['locations'] ) ) {
				return new \WP_Error( 'crayn_safe_revert_no_snapshot', 'The change log row has no complete menu snapshot to restore.' );
			}
		}

		// 1. Menu created by the change: delete it again.
		if ( null === $before['menu'] ) {
			if ( null === $after['menu'] ) {
				return new \WP_Error( 'crayn_safe_revert_no_snapshot', 'The change log row has no menu to act on.' );
			}
			$id  = (int) $after['menu']['term_id'];
			$cur = self::snapshot( $id );
			if ( null === $cur || ! self::same( $cur, $after ) ) {
				return self::menu_conflict( $id );
			}
			$deleted = wp_delete_nav_menu( $id );
			if ( ! $deleted || is_wp_error( $deleted ) ) {
				return new \WP_Error( 'crayn_safe_menu_delete_failed', 'The menu could not be deleted.' );
			}
			return sprintf( 'Menu %d (created by the reverted change) deleted.', $id );
		}

		// 2. Menu deleted by the change: recreate it with its items and locations.
		if ( null === $after['menu'] ) {
			return self::recreate_menu( $before );
		}

		// 3. Menu or items changed: bring the current menu back to the before snapshot.
		$id  = (int) $before['menu']['term_id'];
		$cur = self::snapshot( $id );
		if ( null === $cur || ! self::same( $cur, $after ) ) {
			return self::menu_conflict( $id );
		}
		$fields = self::sync_menu_fields( $id, $before['menu'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$map = self::sync_items( $id, $cur['items'], $before['items'], true );
		if ( is_wp_error( $map ) ) {
			return new \WP_Error( 'crayn_safe_revert_failed', 'The menu was only partly restored: ' . $map->get_error_message() );
		}
		$now = self::snapshot( $id );
		if ( null === $now ) {
			return new \WP_Error( 'crayn_safe_revert_failed', 'The menu could not be read back after the restore. Check it with menu-get.' );
		}
		$differs = self::same( self::normalized( $now ), self::normalized( $before ) ) ? '' : ' Note: the stored menu differs slightly from the snapshot (WordPress filtered or normalized some text).';
		$renumbered = 0;
		foreach ( $map as $old => $new ) {
			if ( (int) $old !== (int) $new ) {
				$renumbered++;
			}
		}
		return sprintf( 'Menu %d restored (%d items)%s.%s', $id, count( $before['items'] ), $renumbered > 0 ? sprintf( '; %d item(s) were recreated with new ids', $renumbered ) : '', $differs );
	}

	/**
	 * Conflict error for a menu that changed after the logged write.
	 *
	 * @param int $menu_id Menu id.
	 * @return \WP_Error
	 */
	private static function menu_conflict( $menu_id ) {
		return new \WP_Error(
			'crayn_safe_revert_conflict',
			sprintf( 'Menu %d changed after this edit (or no longer exists). Revert the newer changes first (see changes-list), newest first.', (int) $menu_id )
		);
	}

	/**
	 * Recreate a deleted menu with its items and theme locations.
	 *
	 * @param array $before Snapshot before the delete.
	 * @return string|\WP_Error
	 */
	private static function recreate_menu( array $before ) {
		$old_id = (int) $before['menu']['term_id'];
		if ( null !== self::snapshot( $old_id ) ) {
			return self::menu_conflict( $old_id );
		}
		$same_name = get_term_by( 'name', $before['menu']['name'], 'nav_menu' );
		if ( $same_name && ! is_wp_error( $same_name ) ) {
			return new \WP_Error( 'crayn_safe_revert_conflict', sprintf( 'A menu named "%s" exists now (id %d), so the deleted menu cannot be recreated. Rename or delete that menu first.', $before['menu']['name'], (int) $same_name->term_id ) );
		}
		$registered = get_registered_nav_menus();
		$locations  = array();
		foreach ( $before['locations'] as $slug ) {
			$current = self::current_locations_raw();
			if ( isset( $current[ $slug ] ) && $current[ $slug ] > 0 && null !== self::snapshot( $current[ $slug ] ) ) {
				return new \WP_Error( 'crayn_safe_revert_conflict', sprintf( 'Location "%s" has another menu assigned now. Unassign it first (menu-locations-set).', $slug ) );
			}
			if ( isset( $registered[ $slug ] ) ) {
				$locations[ $slug ] = true;
			}
		}

		$new_id = wp_update_nav_menu_object(
			0,
			array(
				'menu-name'   => wp_slash( $before['menu']['name'] ),
				'description' => wp_slash( $before['menu']['description'] ),
			)
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		$fields = self::sync_menu_fields( (int) $new_id, $before['menu'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$map = self::sync_items( (int) $new_id, array(), $before['items'], true );
		if ( is_wp_error( $map ) ) {
			return new \WP_Error( 'crayn_safe_revert_failed', 'The menu was recreated (id ' . (int) $new_id . ') but its items were only partly restored: ' . $map->get_error_message() );
		}
		if ( array() !== $locations ) {
			$changes = array();
			foreach ( array_keys( $locations ) as $slug ) {
				$changes[ $slug ] = (int) $new_id;
			}
			self::write_locations( $changes );
		}
		$now  = self::snapshot( (int) $new_id );
		$want = $before;
		$want['locations'] = array_values( array_intersect( $before['locations'], array_keys( $registered ) ) );
		sort( $want['locations'] );
		if ( null === $now ) {
			return new \WP_Error( 'crayn_safe_revert_failed', sprintf( 'The recreated menu (id %d) could not be read back. Check it with menu-get.', (int) $new_id ) );
		}
		$differs = self::same( self::normalized( $now ), self::normalized( $want ) ) ? '' : ' Note: the stored menu differs slightly from the snapshot (WordPress filtered or normalized some text).';
		return sprintf( 'Menu "%s" recreated with %d item(s). The menu id changed from %d to %d (item ids changed too; parents were re-mapped).%s', $before['menu']['name'], count( $before['items'] ), $old_id, (int) $new_id, $differs );
	}

	/**
	 * Restore the touched menu locations.
	 *
	 * @param array $before Before: { locations: slug => id|null }.
	 * @param array $after  After:  { locations: slug => id }.
	 * @return string|\WP_Error
	 */
	private static function revert_locations( array $before, array $after ) {
		if ( ! isset( $before['locations'], $after['locations'] ) || ! is_array( $before['locations'] ) || ! is_array( $after['locations'] ) ) {
			return new \WP_Error( 'crayn_safe_revert_no_snapshot', 'The change log row has no location snapshot to restore.' );
		}
		// Locations belong to a theme: restoring them on another theme would change the wrong one.
		if ( isset( $before['theme'] ) && get_stylesheet() !== $before['theme'] ) {
			return new \WP_Error( 'crayn_safe_revert_conflict', sprintf( 'These locations belong to theme "%s", which is no longer active. Switch back to it to revert this change.', (string) $before['theme'] ) );
		}
		$current = self::current_locations_raw();
		foreach ( $after['locations'] as $slug => $id ) {
			$got = isset( $current[ $slug ] ) ? $current[ $slug ] : 0;
			if ( (int) $got !== (int) $id ) {
				return new \WP_Error( 'crayn_safe_revert_conflict', sprintf( 'Location "%s" was changed after this edit. Revert the newer changes first (see changes-list), newest first.', $slug ) );
			}
		}
		$changes = array();
		foreach ( $before['locations'] as $slug => $id ) {
			$changes[ (string) $slug ] = null === $id ? null : (int) $id;
		}
		self::write_locations( $changes );
		$now = self::current_locations_raw();
		foreach ( $changes as $slug => $id ) {
			$got = isset( $now[ $slug ] ) ? $now[ $slug ] : null;
			if ( $got !== $id ) {
				return new \WP_Error( 'crayn_safe_revert_failed', sprintf( 'Location "%s" did not read back as before.', $slug ) );
			}
		}
		return sprintf( 'Restored %d menu location(s): %s.', count( $changes ), implode( ', ', array_keys( $changes ) ) );
	}
}
