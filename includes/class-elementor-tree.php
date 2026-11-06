<?php
/**
 * Pure-PHP Elementor element tree: parse, address, edit and encode the JSON
 * stored in the `_elementor_data` post meta.
 *
 * No WordPress code is needed, so the class is unit-tested with plain php
 * (tests/test-elementor-tree.php).
 *
 * Why this is not a plain json_decode()/json_encode() round trip:
 * json_decode( $json, true ) turns `{}` into `[]`, unescapes `\/` and unicode
 * escapes, and json_encode() would write them differently again. To keep every
 * element the caller did not touch byte-identical, the JSON text is scanned
 * (not decoded) and every member value keeps its original raw text. Only the
 * members and the ancestors of nodes that an operation really changes are
 * re-encoded; everything else is emitted from the original bytes.
 *
 * What is re-encoded, and how: members that change are written with
 * json_encode() default flags, which is exactly what Elementor itself does when
 * it saves (wp_json_encode( $data ) without flags, see Document::save_elements()
 * in core/base/document.php) - so `/` becomes `\/` and non-ASCII becomes
 * `\uXXXX`, the same as in data Elementor wrote. Whitespace between tokens of a
 * re-encoded container is dropped (compact JSON, as Elementor writes it).
 *
 * Node shape (assoc array, treat as opaque outside this class):
 *   {
 *     id:         string,
 *     elType:     string,
 *     widgetType: string ('' when absent),
 *     members:    list of { key, kraw, raw }  (original member order; the
 *                 "elements" member has raw = null, it is derived from children),
 *     children:   node[] | null  (null when the element has no "elements" member),
 *     src:        string | null  (original JSON text of the node while untouched)
 *   }
 * Tree shape: { nodes: node[], src: string | null }.
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor tree utilities. Every method is static and side-effect free.
 */
class ElementorTree {

	/**
	 * Keys an element created by a tool call may carry (everything else is refused).
	 *
	 * @var string[]
	 */
	const NEW_ELEMENT_KEYS = array( 'id', 'elType', 'widgetType', 'settings', 'elements', 'isInner', 'isLocked' );

	/**
	 * Member that holds the raw HTML render cache; ignored when comparing nodes.
	 */
	const HTML_CACHE_KEY = 'htmlCache';

	// ---------------------------------------------------------------------
	// JSON text scanning (no decoding)
	// ---------------------------------------------------------------------

	/**
	 * Skip JSON whitespace.
	 *
	 * @param string $s Text.
	 * @param int    $i Offset.
	 * @param int    $n Text length.
	 * @return int
	 */
	private static function ws( $s, $i, $n ) {
		while ( $i < $n ) {
			$c = $s[ $i ];
			if ( ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c ) {
				++$i;
			} else {
				break;
			}
		}
		return $i;
	}

	/**
	 * Offset just after the string that starts at $i, or -1 when it never ends.
	 *
	 * @param string $s Text.
	 * @param int    $i Offset of the opening quote.
	 * @param int    $n Text length.
	 * @return int
	 */
	private static function scan_string( $s, $i, $n ) {
		++$i;
		while ( true ) {
			$i += strcspn( $s, "\"\\", $i );
			if ( $i >= $n ) {
				return -1;
			}
			if ( '"' === $s[ $i ] ) {
				return $i + 1;
			}
			$i += 2; // Skip the escape pair.
		}
	}

	/**
	 * Offset just after the JSON value that starts at $i, or -1 on a scan error.
	 * The caller validates the whole document with json_decode() first, so this
	 * scanner only has to be correct on valid JSON (it still never loops forever).
	 *
	 * @param string $s Text.
	 * @param int    $i Offset of the value (no leading whitespace).
	 * @param int    $n Text length.
	 * @return int
	 */
	private static function scan_value( $s, $i, $n ) {
		if ( $i >= $n ) {
			return -1;
		}
		$c = $s[ $i ];
		if ( '"' === $c ) {
			return self::scan_string( $s, $i, $n );
		}
		if ( '{' === $c ) {
			$r = self::scan_object( $s, $i, $n );
			return null === $r ? -1 : $r['end'];
		}
		if ( '[' === $c ) {
			$r = self::scan_array( $s, $i, $n );
			return null === $r ? -1 : $r['end'];
		}
		// Number or literal.
		$len = strspn( $s, '+-.0123456789eEabcdefghijklmnopqrstuvwxyz', $i );
		return $len > 0 ? $i + $len : -1;
	}

	/**
	 * Scan a JSON object into its members, without decoding values.
	 *
	 * @param string $s Text.
	 * @param int    $i Offset of "{".
	 * @param int    $n Text length.
	 * @return array|null { members: list of {key,kraw,vs,ve}, end: int } or null on error.
	 */
	private static function scan_object( $s, $i, $n ) {
		$members = array();
		$i       = self::ws( $s, $i + 1, $n );
		if ( $i < $n && '}' === $s[ $i ] ) {
			return array( 'members' => $members, 'end' => $i + 1 );
		}
		while ( $i < $n ) {
			if ( '"' !== $s[ $i ] ) {
				return null;
			}
			$ke = self::scan_string( $s, $i, $n );
			if ( $ke < 0 ) {
				return null;
			}
			$kraw = substr( $s, $i, $ke - $i );
			$key  = json_decode( $kraw );
			$i    = self::ws( $s, $ke, $n );
			if ( $i >= $n || ':' !== $s[ $i ] ) {
				return null;
			}
			$vs = self::ws( $s, $i + 1, $n );
			$ve = self::scan_value( $s, $vs, $n );
			if ( $ve < 0 ) {
				return null;
			}
			$members[] = array(
				'key'  => is_string( $key ) ? $key : (string) $key,
				'kraw' => $kraw,
				'vs'   => $vs,
				've'   => $ve,
			);
			$i = self::ws( $s, $ve, $n );
			if ( $i < $n && ',' === $s[ $i ] ) {
				$i = self::ws( $s, $i + 1, $n );
				continue;
			}
			if ( $i < $n && '}' === $s[ $i ] ) {
				return array( 'members' => $members, 'end' => $i + 1 );
			}
			return null;
		}
		return null;
	}

	/**
	 * Scan a JSON array into item ranges, without decoding values.
	 *
	 * @param string $s Text.
	 * @param int    $i Offset of "[".
	 * @param int    $n Text length.
	 * @return array|null { items: list of {vs,ve}, end: int } or null on error.
	 */
	private static function scan_array( $s, $i, $n ) {
		$items = array();
		$i     = self::ws( $s, $i + 1, $n );
		if ( $i < $n && ']' === $s[ $i ] ) {
			return array( 'items' => $items, 'end' => $i + 1 );
		}
		while ( $i < $n ) {
			$ve = self::scan_value( $s, $i, $n );
			if ( $ve < 0 ) {
				return null;
			}
			$items[] = array( 'vs' => $i, 've' => $ve );
			$i       = self::ws( $s, $ve, $n );
			if ( $i < $n && ',' === $s[ $i ] ) {
				$i = self::ws( $s, $i + 1, $n );
				continue;
			}
			if ( $i < $n && ']' === $s[ $i ] ) {
				return array( 'items' => $items, 'end' => $i + 1 );
			}
			return null;
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Parse / encode
	// ---------------------------------------------------------------------

	/**
	 * Parse the JSON text of the Elementor data meta.
	 *
	 * @param mixed $json Meta value (string). An empty string is an empty page.
	 * @return array|string Tree, or an error message.
	 */
	public static function parse( $json ) {
		if ( ! is_string( $json ) ) {
			return 'The Elementor data is not a string.';
		}
		if ( '' === trim( $json ) ) {
			return array(
				'nodes' => array(),
				'src'   => $json,
			);
		}

		$decoded = json_decode( $json );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return 'The Elementor data is not valid JSON: ' . json_last_error_msg() . '.';
		}
		if ( ! is_array( $decoded ) ) {
			return 'The Elementor data must be a JSON list of elements.';
		}

		$n   = strlen( $json );
		$i   = self::ws( $json, 0, $n );
		$arr = self::scan_array( $json, $i, $n );
		if ( null === $arr ) {
			return 'The Elementor data could not be scanned.';
		}

		$nodes = array();
		foreach ( $arr['items'] as $k => $item ) {
			$node = self::parse_node( $json, $item['vs'], $item['ve'], (string) $k );
			if ( is_string( $node ) ) {
				return $node;
			}
			$nodes[] = $node;
		}

		return array(
			'nodes' => $nodes,
			'src'   => $json,
		);
	}

	/**
	 * Parse one element object (recursive).
	 *
	 * @param string $s     Whole JSON text.
	 * @param int    $start Start offset of the element object.
	 * @param int    $end   End offset (exclusive).
	 * @param string $where Human path for error messages.
	 * @return array|string Node, or an error message.
	 */
	private static function parse_node( $s, $start, $end, $where ) {
		if ( '{' !== $s[ $start ] ) {
			return sprintf( 'The element at position %s is not an object.', $where );
		}
		$obj = self::scan_object( $s, $start, strlen( $s ) );
		if ( null === $obj ) {
			return sprintf( 'The element at position %s could not be scanned.', $where );
		}

		$members    = array();
		$children   = null;
		$id         = null;
		$el_type    = null;
		$widget_typ = '';
		$seen       = array();

		foreach ( $obj['members'] as $m ) {
			if ( isset( $seen[ $m['key'] ] ) ) {
				return sprintf( 'The element at position %s has the duplicate member "%s".', $where, $m['key'] );
			}
			$seen[ $m['key'] ] = true;
			$raw               = substr( $s, $m['vs'], $m['ve'] - $m['vs'] );

			if ( 'elements' === $m['key'] ) {
				if ( '[' !== $raw[0] ) {
					return sprintf( 'The "elements" of the element at position %s is not a list.', $where );
				}
				$arr = self::scan_array( $s, $m['vs'], strlen( $s ) );
				if ( null === $arr ) {
					return sprintf( 'The "elements" of the element at position %s could not be scanned.', $where );
				}
				$children = array();
				foreach ( $arr['items'] as $k => $item ) {
					$child = self::parse_node( $s, $item['vs'], $item['ve'], $where . '.' . $k );
					if ( is_string( $child ) ) {
						return $child;
					}
					$children[] = $child;
				}
				$members[] = array(
					'key'  => 'elements',
					'kraw' => $m['kraw'],
					'raw'  => null,
				);
				continue;
			}

			if ( 'id' === $m['key'] ) {
				$v = json_decode( $raw );
				if ( is_string( $v ) || is_int( $v ) ) {
					$id = (string) $v;
				}
			} elseif ( 'elType' === $m['key'] ) {
				$v = json_decode( $raw );
				if ( is_string( $v ) ) {
					$el_type = $v;
				}
			} elseif ( 'widgetType' === $m['key'] ) {
				$v = json_decode( $raw );
				if ( is_string( $v ) ) {
					$widget_typ = $v;
				}
			}
			$members[] = array(
				'key'  => $m['key'],
				'kraw' => $m['kraw'],
				'raw'  => $raw,
			);
		}

		if ( null === $id || '' === $id ) {
			return sprintf( 'The element at position %s has no "id".', $where );
		}
		if ( null === $el_type || '' === $el_type ) {
			return sprintf( 'The element "%s" has no "elType".', $id );
		}

		return array(
			'id'         => $id,
			'elType'     => $el_type,
			'widgetType' => $widget_typ,
			'members'    => $members,
			'children'   => $children,
			'src'        => substr( $s, $start, $end - $start ),
		);
	}

	/**
	 * Encode a tree back to the JSON text for the Elementor data meta.
	 * An untouched tree is returned byte for byte.
	 *
	 * @param array $tree Tree.
	 * @return string
	 */
	public static function encode( array $tree ) {
		if ( isset( $tree['src'] ) && null !== $tree['src'] ) {
			return $tree['src'];
		}
		$parts = array();
		foreach ( $tree['nodes'] as $node ) {
			$parts[] = self::encode_node( $node );
		}
		return '[' . implode( ',', $parts ) . ']';
	}

	/**
	 * Encode one node (recursive).
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private static function encode_node( array $node ) {
		if ( null !== $node['src'] ) {
			return $node['src'];
		}
		$parts = array();
		foreach ( $node['members'] as $m ) {
			if ( 'elements' === $m['key'] ) {
				$kids = array();
				foreach ( (array) $node['children'] as $child ) {
					$kids[] = self::encode_node( $child );
				}
				$value = '[' . implode( ',', $kids ) . ']';
			} else {
				$value = $m['raw'];
			}
			$parts[] = $m['kraw'] . ':' . $value;
		}
		return '{' . implode( ',', $parts ) . '}';
	}

	/**
	 * JSON-encode a PHP value the way Elementor saves data (json_encode, no flags).
	 * Returns null when the value cannot be encoded (e.g. invalid UTF-8).
	 *
	 * @param mixed $value Value. stdClass encodes as an object even when empty.
	 * @return string|null
	 */
	public static function encode_value( $value ) {
		$out = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure PHP class, runs without WordPress; the flags (none) match wp_json_encode() as used by Elementor.
		return false === $out ? null : $out;
	}

	// ---------------------------------------------------------------------
	// Member helpers
	// ---------------------------------------------------------------------

	/**
	 * Index of a member by key, or -1.
	 *
	 * @param array  $node Node.
	 * @param string $key  Member key.
	 * @return int
	 */
	private static function member_index( array $node, $key ) {
		foreach ( $node['members'] as $i => $m ) {
			if ( $key === $m['key'] ) {
				return (int) $i;
			}
		}
		return -1;
	}

	/**
	 * Decoded (assoc) value of a member, or $default when absent.
	 *
	 * @param array  $node    Node.
	 * @param string $key     Member key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function member_value( array $node, $key, $default = null ) {
		$i = self::member_index( $node, $key );
		if ( $i < 0 || null === $node['members'][ $i ]['raw'] ) {
			return $default;
		}
		return json_decode( $node['members'][ $i ]['raw'], true );
	}

	/**
	 * Set a member to a raw JSON text (replace in place, or append).
	 *
	 * @param array  $node Node.
	 * @param string $key  Member key.
	 * @param string $raw  Raw JSON text.
	 * @return array Node (src cleared).
	 */
	private static function with_member_raw( array $node, $key, $raw ) {
		$i = self::member_index( $node, $key );
		if ( $i < 0 ) {
			$node['members'][] = array(
				'key'  => $key,
				'kraw' => self::encode_value( $key ),
				'raw'  => $raw,
			);
		} else {
			$node['members'][ $i ]['raw'] = $raw;
		}
		$node['src'] = null;
		return $node;
	}

	// ---------------------------------------------------------------------
	// Addressing
	// ---------------------------------------------------------------------

	/**
	 * All element ids of a tree as an id => true map.
	 *
	 * @param array $tree Tree.
	 * @return array<string,bool>
	 */
	public static function ids( array $tree ) {
		$out = array();
		self::collect_ids( $tree['nodes'], $out );
		return $out;
	}

	/**
	 * Collect ids of a node list (recursive).
	 *
	 * @param array $nodes Nodes.
	 * @param array $out   Collector (by ref).
	 * @return void
	 */
	private static function collect_ids( array $nodes, array &$out ) {
		foreach ( $nodes as $node ) {
			$out[ $node['id'] ] = true;
			if ( ! empty( $node['children'] ) ) {
				self::collect_ids( $node['children'], $out );
			}
		}
	}

	/**
	 * Path (list of 0-based child indexes) of the element with this id.
	 *
	 * @param array  $tree Tree.
	 * @param string $id   Element id.
	 * @return int[]|null
	 */
	public static function find( array $tree, $id ) {
		return self::find_in( $tree['nodes'], (string) $id, array() );
	}

	/**
	 * Recursive path search.
	 *
	 * @param array  $nodes  Nodes.
	 * @param string $id     Element id.
	 * @param array  $prefix Path so far.
	 * @return int[]|null
	 */
	private static function find_in( array $nodes, $id, array $prefix ) {
		foreach ( $nodes as $i => $node ) {
			$path = array_merge( $prefix, array( (int) $i ) );
			if ( $node['id'] === $id ) {
				return $path;
			}
			if ( ! empty( $node['children'] ) ) {
				$found = self::find_in( $node['children'], $id, $path );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Node at a path, or null.
	 *
	 * @param array $tree Tree.
	 * @param array $path Path.
	 * @return array|null
	 */
	public static function node_at( array $tree, array $path ) {
		$nodes = $tree['nodes'];
		$node  = null;
		foreach ( $path as $idx ) {
			if ( ! isset( $nodes[ $idx ] ) ) {
				return null;
			}
			$node  = $nodes[ $idx ];
			$nodes = (array) $node['children'];
		}
		return $node;
	}

	/**
	 * Node by id, or null.
	 *
	 * @param array  $tree Tree.
	 * @param string $id   Element id.
	 * @return array|null
	 */
	public static function get( array $tree, $id ) {
		$path = self::find( $tree, $id );
		return null === $path ? null : self::node_at( $tree, $path );
	}

	/**
	 * Id of the parent of the node at $path (null for a top-level node).
	 *
	 * @param array $tree Tree.
	 * @param array $path Path of the node.
	 * @return string|null
	 */
	public static function parent_id( array $tree, array $path ) {
		if ( count( $path ) < 2 ) {
			return null;
		}
		$parent = self::node_at( $tree, array_slice( $path, 0, -1 ) );
		return null === $parent ? null : $parent['id'];
	}

	/**
	 * Id, elType and widgetType of a node (cheap summary for callers).
	 *
	 * @param array $node Node.
	 * @return array{id:string,elType:string,widgetType:string}
	 */
	public static function node_info( array $node ) {
		return array(
			'id'         => $node['id'],
			'elType'     => $node['elType'],
			'widgetType' => $node['widgetType'],
		);
	}

	/**
	 * Children of a node (empty list when it has no "elements" member).
	 *
	 * @param array $node Node.
	 * @return array
	 */
	public static function children( array $node ) {
		return is_array( $node['children'] ) ? $node['children'] : array();
	}

	/**
	 * Rebuild the children list at $parent_path through a callback, clearing
	 * the cached source text of every node on the way down. $parent_path = []
	 * means the top-level list.
	 *
	 * @param array    $nodes       Node list.
	 * @param int[]    $parent_path Path of the parent whose children change.
	 * @param callable $fn          Receives the child list, returns the new list or null.
	 * @return array|null New node list, or null when the path is invalid / fn failed.
	 */
	private static function rebuild_children( array $nodes, array $parent_path, $fn ) {
		if ( array() === $parent_path ) {
			return call_user_func( $fn, $nodes );
		}
		$idx = array_shift( $parent_path );
		if ( ! isset( $nodes[ $idx ] ) ) {
			return null;
		}
		$node = $nodes[ $idx ];
		if ( ! is_array( $node['children'] ) ) {
			// A node without an "elements" member can only get one when its (empty) list is rebuilt.
			if ( array() !== $parent_path ) {
				return null;
			}
			$node['children'] = array();
			$node['members'][] = array(
				'key'  => 'elements',
				'kraw' => '"elements"',
				'raw'  => null,
			);
		}
		$new = self::rebuild_children( $node['children'], $parent_path, $fn );
		if ( null === $new ) {
			return null;
		}
		$node['children'] = $new;
		$node['src']      = null;
		$nodes[ $idx ]    = $node;
		return $nodes;
	}

	/**
	 * Apply rebuild_children to a tree.
	 *
	 * @param array    $tree        Tree.
	 * @param int[]    $parent_path Parent path.
	 * @param callable $fn          Callback.
	 * @return array|null Tree or null.
	 */
	private static function tree_rebuild( array $tree, array $parent_path, $fn ) {
		$nodes = self::rebuild_children( $tree['nodes'], $parent_path, $fn );
		if ( null === $nodes ) {
			return null;
		}
		return array(
			'nodes' => array_values( $nodes ),
			'src'   => null,
		);
	}

	/**
	 * Insert a node into a list at an index (null or too large = append).
	 *
	 * @param array    $list  Node list.
	 * @param int|null $index Position.
	 * @param array    $node  Node.
	 * @return array
	 */
	private static function splice_in( array $list, $index, array $node ) {
		$list = array_values( $list );
		if ( null === $index || $index >= count( $list ) ) {
			$list[] = $node;
			return $list;
		}
		array_splice( $list, max( 0, (int) $index ), 0, array( $node ) );
		return $list;
	}

	// ---------------------------------------------------------------------
	// Element types and placement rules
	// ---------------------------------------------------------------------

	/**
	 * Is this a V4 atomic element (elType "e-..." or a widget whose widgetType is "e-...")?
	 * Atomic elements keep typed props and local styles that this tool does not edit.
	 *
	 * @param array|string $node_or_type Node, or an elType string.
	 * @param string       $widget_type  Widget type when a string elType is given.
	 * @return bool
	 */
	public static function is_atomic( $node_or_type, $widget_type = '' ) {
		if ( is_array( $node_or_type ) ) {
			$el_type     = (string) $node_or_type['elType'];
			$widget_type = (string) $node_or_type['widgetType'];
		} else {
			$el_type = (string) $node_or_type;
		}
		if ( 0 === strpos( $el_type, 'e-' ) ) {
			return true;
		}
		return 'widget' === $el_type && 0 === strpos( (string) $widget_type, 'e-' );
	}

	/**
	 * Does the node or any descendant use an atomic element?
	 *
	 * @param array $node Node.
	 * @return bool
	 */
	public static function contains_atomic( array $node ) {
		if ( self::is_atomic( $node ) ) {
			return true;
		}
		foreach ( self::children( $node ) as $child ) {
			if ( self::contains_atomic( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does the tree use any atomic element?
	 *
	 * @param array $tree Tree.
	 * @return bool
	 */
	public static function tree_has_atomic( array $tree ) {
		foreach ( $tree['nodes'] as $node ) {
			if ( self::contains_atomic( $node ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Structural placement rule, mirroring the editor's isValidChild() of each
	 * element model (assets/js/editor.js: Document, Section, Column, Container,
	 * Widget; see SONUC-MODULLER.md for the line numbers). The widget registry
	 * and the atomic "allowed_child_types" are checked by the caller.
	 *
	 * @param array|null $parent      Parent node, or null for the top level.
	 * @param string     $el_type     Child elType.
	 * @param bool       $child_inner Child isInner flag (only matters for sections in columns).
	 * @return string|null Error message, or null when the placement is allowed.
	 */
	public static function placement_error( $parent, $el_type, $child_inner = false ) {
		$atomic_child = 0 === strpos( (string) $el_type, 'e-' );

		if ( null === $parent ) {
			if ( 'widget' === $el_type ) {
				return 'A widget cannot be placed at the top level. Put it inside a container (or a section and column).';
			}
			if ( 'column' === $el_type ) {
				return 'A column can only be placed inside a section.';
			}
			return null;
		}

		$parent_type = (string) $parent['elType'];
		switch ( $parent_type ) {
			case 'widget':
				return 'A widget cannot contain other elements.';
			case 'section':
				return 'column' === $el_type ? null : 'A section can only contain columns.';
			case 'column':
				if ( 'widget' === $el_type || 'container' === $el_type ) {
					return null;
				}
				if ( 'section' === $el_type ) {
					return $child_inner ? null : 'A section inside a column must be an inner section (isInner true).';
				}
				return $atomic_child ? 'A column can only contain widgets, containers and inner sections.' : 'A column cannot contain a column.';
			case 'container':
				if ( 'section' === $el_type || 'column' === $el_type ) {
					return 'A container cannot contain a section or a column.';
				}
				return null;
			default:
				// Atomic (V4) parent: sections and columns never fit; the registry's allowed_child_types decides the rest.
				if ( 'section' === $el_type || 'column' === $el_type ) {
					return 'An atomic (V4) element cannot contain a section or a column.';
				}
				return null;
		}
	}

	// ---------------------------------------------------------------------
	// Id generation
	// ---------------------------------------------------------------------

	/**
	 * New element id in Elementor's format: lower-case hex of a random number.
	 * Elementor's PHP generator is Utils::generate_random_string() = dechex( rand() )
	 * (includes/utils.php:394) and the editor's is Math.random().toString(16).substr(2, 7)
	 * (assets/js/common.js:215-219, getUniqueId); this uses 7 hex digits, like the editor,
	 * and never returns an id in $used.
	 *
	 * @param array         $used Map of ids in use (id => true).
	 * @param callable|null $rand Optional integer source (tests).
	 * @return string
	 */
	public static function generate_id( array $used, $rand = null ) {
		for ( $try = 0; $try < 200; $try++ ) {
			$n = null === $rand ? random_int( 0x1000000, 0xfffffff ) : (int) call_user_func( $rand );
			$id = dechex( $n );
			if ( ! isset( $used[ $id ] ) ) {
				return $id;
			}
		}
		// Practically unreachable: walk upwards from a random start until a free id is found.
		$n = null === $rand ? random_int( 0x1000000, 0xfffffff ) : 0x1000000;
		while ( isset( $used[ dechex( $n ) ] ) ) {
			++$n;
		}
		return dechex( $n );
	}

	// ---------------------------------------------------------------------
	// Building nodes from input (always fresh ids)
	// ---------------------------------------------------------------------

	/**
	 * Build a node (and its subtree) from an input element. Ids in the input are
	 * ignored: every node gets a fresh unique id. isInner is computed from the
	 * real nesting. The widget registry is not consulted here.
	 *
	 * @param mixed         $element     Element input ({elType, widgetType?, settings?, elements?}).
	 * @param array|null    $parent      Parent node (null = top level).
	 * @param array         $used        Ids in use (by ref, extended).
	 * @param callable|null $rand        Optional integer source.
	 * @param bool          $parent_inner isInner of the parent (for columns).
	 * @param string        $where        Position for messages.
	 * @return array|string Node, or an error message.
	 */
	public static function build_node( $element, $parent, array &$used, $rand = null, $parent_inner = false, $where = 'element' ) {
		if ( ! is_array( $element ) ) {
			return sprintf( 'The %s must be an object.', $where );
		}
		$extra = array_diff( array_keys( $element ), self::NEW_ELEMENT_KEYS );
		if ( array() !== $extra ) {
			return sprintf( 'The %s has unsupported key(s): %s. Allowed: %s.', $where, implode( ', ', array_map( 'strval', $extra ) ), implode( ', ', self::NEW_ELEMENT_KEYS ) );
		}
		$el_type = isset( $element['elType'] ) && is_string( $element['elType'] ) ? trim( $element['elType'] ) : '';
		if ( '' === $el_type ) {
			return sprintf( 'The %s needs an "elType" (section, column, container or widget).', $where );
		}
		$widget_type = '';
		if ( 'widget' === $el_type ) {
			$widget_type = isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ? trim( $element['widgetType'] ) : '';
			if ( '' === $widget_type ) {
				return sprintf( 'The %s is a widget and needs a "widgetType".', $where );
			}
		} elseif ( isset( $element['widgetType'] ) && '' !== $element['widgetType'] ) {
			return sprintf( 'The %s is not a widget, so it cannot have a "widgetType".', $where );
		}

		$is_inner = false;
		if ( 'section' === $el_type || 'container' === $el_type ) {
			$is_inner = null !== $parent;
		} elseif ( 'column' === $el_type ) {
			$is_inner = (bool) $parent_inner;
		}

		$placement = self::placement_error( $parent, $el_type, $is_inner );
		if ( null !== $placement ) {
			return sprintf( 'The %s (%s) cannot be placed here: %s', $where, 'widget' === $el_type ? $widget_type : $el_type, $placement );
		}

		$settings = array();
		if ( isset( $element['settings'] ) ) {
			if ( ! is_array( $element['settings'] ) && ! ( $element['settings'] instanceof \stdClass ) ) {
				return sprintf( 'The settings of the %s must be an object.', $where );
			}
			$settings = $element['settings'];
		}
		if ( is_array( $settings ) && array() !== $settings && array_keys( $settings ) === range( 0, count( $settings ) - 1 ) ) {
			return sprintf( 'The settings of the %s must be an object, not a list.', $where );
		}
		$settings_raw = ( is_array( $settings ) && array() === $settings ) ? '[]' : self::encode_value( $settings );
		if ( null === $settings_raw ) {
			return sprintf( 'The settings of the %s cannot be encoded as JSON (invalid UTF-8?).', $where );
		}

		$id           = self::generate_id( $used, $rand );
		$used[ $id ] = true;

		$node = array(
			'id'         => $id,
			'elType'     => $el_type,
			'widgetType' => $widget_type,
			'members'    => array(),
			'children'   => array(),
			'src'        => null,
		);

		$node['members'][] = self::new_member( 'id', self::encode_value( $id ) );
		$node['members'][] = self::new_member( 'elType', self::encode_value( $el_type ) );
		$node['members'][] = self::new_member( 'settings', $settings_raw );
		$node['members'][] = self::new_member( 'elements', null );
		if ( 'widget' === $el_type ) {
			$node['members'][] = self::new_member( 'widgetType', self::encode_value( $widget_type ) );
		} else {
			$node['members'][] = self::new_member( 'isInner', $is_inner ? 'true' : 'false' );
		}
		if ( ! empty( $element['isLocked'] ) ) {
			$node['members'][] = self::new_member( 'isLocked', 'true' );
		}

		$kids = isset( $element['elements'] ) ? $element['elements'] : array();
		if ( ! is_array( $kids ) ) {
			return sprintf( 'The "elements" of the %s must be a list.', $where );
		}
		if ( array() !== $kids && 'widget' === $el_type ) {
			return sprintf( 'The %s is a widget and cannot contain elements.', $where );
		}
		foreach ( array_values( $kids ) as $k => $child_in ) {
			$child = self::build_node( $child_in, $node, $used, $rand, $is_inner, $where . '.elements[' . $k . ']' );
			if ( is_string( $child ) ) {
				return $child;
			}
			$node['children'][] = $child;
		}

		return $node;
	}

	/**
	 * Member constructor.
	 *
	 * @param string      $key Key.
	 * @param string|null $raw Raw JSON value (null for "elements").
	 * @return array
	 */
	private static function new_member( $key, $raw ) {
		return array(
			'key'  => $key,
			'kraw' => self::encode_value( $key ),
			'raw'  => $raw,
		);
	}

	// ---------------------------------------------------------------------
	// Operations
	// ---------------------------------------------------------------------

	/**
	 * Insert a new element (with children) under a parent id (null = top level).
	 *
	 * @param array         $tree      Tree.
	 * @param string|null   $parent_id Parent element id, null or '' for the top level.
	 * @param int|null      $index     Position among the siblings, null = append.
	 * @param mixed         $element   Element input.
	 * @param callable|null $rand      Optional integer source (tests).
	 * @return array|string { tree, id } or an error message.
	 */
	public static function insert( array $tree, $parent_id, $index, $element, $rand = null ) {
		$parent = null;
		$path   = array();
		if ( null !== $parent_id && '' !== $parent_id ) {
			$path = self::find( $tree, $parent_id );
			if ( null === $path ) {
				return sprintf( 'The parent element "%s" does not exist on this page.', $parent_id );
			}
			$parent = self::node_at( $tree, $path );
		}

		$used = self::ids( $tree );
		$node = self::build_node( $element, $parent, $used, $rand, null !== $parent && self::inner_flag( $parent ) );
		if ( is_string( $node ) ) {
			return $node;
		}
		$new = self::tree_rebuild(
			$tree,
			$path,
			function ( $list ) use ( $index, $node ) {
				return self::splice_in( $list, $index, $node );
			}
		);
		if ( null === $new ) {
			return 'The element could not be inserted.';
		}
		return array(
			'tree' => $new,
			'id'   => $node['id'],
		);
	}

	/**
	 * Value of a parent's isInner flag (used for the columns of an inner section).
	 *
	 * @param array $parent Parent node.
	 * @return bool
	 */
	private static function inner_flag( array $parent ) {
		return true === self::member_value( $parent, 'isInner', false );
	}

	/**
	 * Merge settings into an element: given keys are replaced as a whole value
	 * (no deep merge), other keys keep their original bytes, and keys in
	 * $remove are dropped.
	 *
	 * @param array  $tree   Tree.
	 * @param string $id     Element id.
	 * @param array  $set    Settings to set (key => value).
	 * @param array  $remove Settings keys to remove.
	 * @return array|string Tree, or an error message.
	 */
	public static function update_settings( array $tree, $id, array $set, array $remove = array() ) {
		$path = self::find( $tree, $id );
		if ( null === $path ) {
			return sprintf( 'The element "%s" does not exist on this page.', $id );
		}
		$node = self::node_at( $tree, $path );

		$raw = self::member_index( $node, 'settings' ) >= 0 ? $node['members'][ self::member_index( $node, 'settings' ) ]['raw'] : '[]';
		$new = self::merge_settings_raw( (string) $raw, $set, $remove );
		if ( is_string( $new ) && 0 === strpos( $new, 'ERR:' ) ) {
			return substr( $new, 4 );
		}
		if ( null === $new ) {
			return 'The settings cannot be encoded as JSON (invalid UTF-8?).';
		}
		if ( $new === (string) $raw ) {
			return $tree; // Nothing effectively changed: keep every byte.
		}

		$parent_path = array_slice( $path, 0, -1 );
		$last        = $path[ count( $path ) - 1 ];
		$result      = self::tree_rebuild(
			$tree,
			$parent_path,
			function ( $list ) use ( $last, $new ) {
				$list[ $last ] = self::with_member_raw( $list[ $last ], 'settings', $new );
				return $list;
			}
		);
		return null === $result ? 'The settings could not be updated.' : $result;
	}

	/**
	 * Merge into the raw JSON text of a settings value.
	 *
	 * @param string $raw    Raw settings JSON ("{...}", "[]" or "null").
	 * @param array  $set    Keys to set.
	 * @param array  $remove Keys to remove.
	 * @return string|null New raw JSON; "ERR:message" on a usage error; null when encoding failed.
	 */
	public static function merge_settings_raw( $raw, array $set, array $remove ) {
		$original = $raw;
		$raw      = trim( $raw );
		$members  = array();
		if ( '' === $raw || 'null' === $raw || '[]' === $raw ) {
			$members = array();
		} elseif ( '{' === $raw[0] ) {
			$obj = self::scan_object( $raw, 0, strlen( $raw ) );
			if ( null === $obj ) {
				return 'ERR:The existing settings of this element could not be scanned.';
			}
			foreach ( $obj['members'] as $m ) {
				$members[] = array(
					'key'  => $m['key'],
					'kraw' => $m['kraw'],
					'raw'  => substr( $raw, $m['vs'], $m['ve'] - $m['vs'] ),
				);
			}
		} else {
			return 'ERR:The existing settings of this element are neither an object nor empty.';
		}

		$changed = false;
		foreach ( $remove as $key ) {
			$key = (string) $key;
			foreach ( $members as $i => $m ) {
				if ( $m['key'] === $key ) {
					unset( $members[ $i ] );
					$changed = true;
				}
			}
		}
		$members = array_values( $members );

		foreach ( $set as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key ) {
				return 'ERR:A settings key cannot be empty.';
			}
			$enc = self::encode_value( $value );
			if ( null === $enc ) {
				return null;
			}
			$found = false;
			foreach ( $members as $i => $m ) {
				if ( $m['key'] !== $key ) {
					continue;
				}
				$found = true;
				// Same value (whatever its text form): keep the original bytes.
				if ( ! self::deep_equal( json_decode( $m['raw'], true ), self::normalize_input( $value ) ) ) {
					$members[ $i ]['raw'] = $enc;
					$changed              = true;
				}
				break;
			}
			if ( ! $found ) {
				$members[] = array(
					'key'  => $key,
					'kraw' => self::encode_value( $key ),
					'raw'  => $enc,
				);
				$changed = true;
			}
		}

		if ( ! $changed ) {
			return $original;
		}
		if ( array() === $members ) {
			// Elementor writes an empty settings value as [] (an empty PHP array).
			return '[]';
		}
		$parts = array();
		foreach ( $members as $m ) {
			$parts[] = $m['kraw'] . ':' . $m['raw'];
		}
		return '{' . implode( ',', $parts ) . '}';
	}

	/**
	 * Remove an element (and its children).
	 *
	 * @param array  $tree Tree.
	 * @param string $id   Element id.
	 * @return array|string Tree, or an error message.
	 */
	public static function remove( array $tree, $id ) {
		$path = self::find( $tree, $id );
		if ( null === $path ) {
			return sprintf( 'The element "%s" does not exist on this page.', $id );
		}
		$last   = $path[ count( $path ) - 1 ];
		$result = self::tree_rebuild(
			$tree,
			array_slice( $path, 0, -1 ),
			function ( $list ) use ( $last ) {
				unset( $list[ $last ] );
				return array_values( $list );
			}
		);
		return null === $result ? 'The element could not be removed.' : $result;
	}

	/**
	 * Move an element to another parent (null = top level) at a position.
	 * Refuses to move an element into itself or its own descendants and applies
	 * the placement rules. A moved section/container/column gets the isInner flag
	 * of its new nesting.
	 *
	 * @param array       $tree          Tree.
	 * @param string      $id            Element to move.
	 * @param string|null $new_parent_id New parent id, null or '' for the top level.
	 * @param int|null    $index         Position among the new siblings, null = append.
	 * @return array|string Tree, or an error message.
	 */
	public static function move( array $tree, $id, $new_parent_id, $index ) {
		$path = self::find( $tree, $id );
		if ( null === $path ) {
			return sprintf( 'The element "%s" does not exist on this page.', $id );
		}
		$node = self::node_at( $tree, $path );

		$target_path = array();
		$target      = null;
		if ( null !== $new_parent_id && '' !== $new_parent_id ) {
			$target_path = self::find( $tree, $new_parent_id );
			if ( null === $target_path ) {
				return sprintf( 'The target parent "%s" does not exist on this page.', $new_parent_id );
			}
			if ( array_slice( $target_path, 0, count( $path ) ) === $path ) {
				return 'An element cannot be moved into itself or one of its own descendants.';
			}
			$target = self::node_at( $tree, $target_path );
		}

		$target_inner = null !== $target && ( 'column' === $target['elType'] ? true : self::inner_flag( $target ) );
		$is_inner     = self::wants_inner( $node, $target, $target_inner );
		$error        = self::placement_error( $target, $node['elType'], $is_inner );
		if ( null !== $error ) {
			return sprintf( 'Cannot move "%s" there: %s', $id, $error );
		}

		// Remove first, then insert into the (re-located) target.
		$without = self::remove( $tree, $id );
		if ( is_string( $without ) ) {
			return $without;
		}
		$target_path_after = array();
		if ( null !== $target ) {
			$target_path_after = self::find( $without, $new_parent_id );
			if ( null === $target_path_after ) {
				return 'The target parent could not be located after the move.';
			}
		}

		$moved  = self::set_nesting( $node, $target, $target_inner );
		$result = self::tree_rebuild(
			$without,
			$target_path_after,
			function ( $list ) use ( $index, $moved ) {
				return self::splice_in( $list, $index, $moved );
			}
		);
		return null === $result ? 'The element could not be moved.' : $result;
	}

	/**
	 * isInner value an element should get at its new place.
	 *
	 * @param array      $node         Node.
	 * @param array|null $target       New parent.
	 * @param bool       $target_inner isInner of a column target / the target.
	 * @return bool
	 */
	private static function wants_inner( array $node, $target, $target_inner ) {
		if ( 'section' === $node['elType'] || 'container' === $node['elType'] ) {
			return null !== $target;
		}
		if ( 'column' === $node['elType'] ) {
			return (bool) $target_inner;
		}
		return false;
	}

	/**
	 * Update the isInner member of a moved node (and the columns of a moved section).
	 * Atomic elements are left untouched.
	 *
	 * @param array      $node         Node.
	 * @param array|null $target       New parent.
	 * @param bool       $target_inner isInner of a column target / the target.
	 * @return array Node.
	 */
	private static function set_nesting( array $node, $target, $target_inner ) {
		if ( self::is_atomic( $node ) ) {
			return $node;
		}
		if ( 'widget' === $node['elType'] ) {
			return $node;
		}
		$want = self::wants_inner( $node, $target, $target_inner );
		if ( $want !== self::inner_flag( $node ) ) {
			$node = self::with_member_raw( $node, 'isInner', $want ? 'true' : 'false' );
		}
		if ( 'section' === $node['elType'] && is_array( $node['children'] ) ) {
			$kids = array();
			foreach ( $node['children'] as $child ) {
				$kids[] = self::set_nesting( $child, $node, $want );
			}
			if ( $kids !== $node['children'] ) {
				$node['children'] = $kids;
				$node['src']      = null;
			}
		}
		return $node;
	}

	/**
	 * Duplicate an element right after the original, inside the same parent.
	 * Every element of the copy gets a fresh unique id; everything else (settings,
	 * unknown members) is copied from the original bytes.
	 *
	 * @param array         $tree Tree.
	 * @param string        $id   Element id.
	 * @param callable|null $rand Optional integer source (tests).
	 * @return array|string { tree, id } or an error message.
	 */
	public static function duplicate( array $tree, $id, $rand = null ) {
		$path = self::find( $tree, $id );
		if ( null === $path ) {
			return sprintf( 'The element "%s" does not exist on this page.', $id );
		}
		$node = self::node_at( $tree, $path );
		$used = self::ids( $tree );
		$copy = self::clone_with_new_ids( $node, $used, $rand );

		$last   = $path[ count( $path ) - 1 ];
		$result = self::tree_rebuild(
			$tree,
			array_slice( $path, 0, -1 ),
			function ( $list ) use ( $last, $copy ) {
				return self::splice_in( $list, $last + 1, $copy );
			}
		);
		if ( null === $result ) {
			return 'The element could not be duplicated.';
		}
		return array(
			'tree' => $result,
			'id'   => $copy['id'],
		);
	}

	/**
	 * Copy a node and its subtree giving every element a new id.
	 *
	 * @param array         $node Node.
	 * @param array         $used Ids in use (by ref, extended).
	 * @param callable|null $rand Optional integer source.
	 * @return array Node.
	 */
	private static function clone_with_new_ids( array $node, array &$used, $rand ) {
		$id           = self::generate_id( $used, $rand );
		$used[ $id ] = true;
		$node['id']   = $id;
		$node['src']  = null;
		$node         = self::with_member_raw( $node, 'id', self::encode_value( $id ) );
		if ( is_array( $node['children'] ) ) {
			$kids = array();
			foreach ( $node['children'] as $child ) {
				$kids[] = self::clone_with_new_ids( $child, $used, $rand );
			}
			$node['children'] = $kids;
		}
		return $node;
	}

	// ---------------------------------------------------------------------
	// Replacing a whole tree (reuses untouched bytes)
	// ---------------------------------------------------------------------

	/**
	 * Build a tree from a full list of element arrays (as returned by to_array()),
	 * reusing the original bytes of every element/member that did not change
	 * compared with $old. Ids in the input are kept when valid and unique, and
	 * generated otherwise. The widget registry is not consulted here.
	 *
	 * @param mixed         $list Input list of elements.
	 * @param array         $old  Current tree (for byte reuse).
	 * @param callable|null $rand Optional integer source.
	 * @return array|string Tree, or an error message.
	 */
	public static function from_array( $list, array $old, $rand = null ) {
		if ( ! is_array( $list ) ) {
			return 'The tree must be a list of elements.';
		}
		$old_map = array();
		self::index_nodes( $old['nodes'], $old_map );

		$claimed = array();
		self::claim_input_ids( array_values( $list ), $claimed, $dups );
		if ( ! empty( $dups ) ) {
			return sprintf( 'The tree uses the element id "%s" more than once.', reset( $dups ) );
		}

		$used  = $claimed;
		$nodes = array();
		foreach ( array_values( $list ) as $k => $el ) {
			$node = self::from_array_node( $el, null, false, $old_map, $used, $rand, 'elements[' . $k . ']' );
			if ( is_string( $node ) ) {
				return $node;
			}
			$nodes[] = $node;
		}
		return array(
			'nodes' => $nodes,
			'src'   => null,
		);
	}

	/**
	 * Map of id => node for a node list (recursive).
	 *
	 * @param array $nodes Nodes.
	 * @param array $map   Collector (by ref).
	 * @return void
	 */
	private static function index_nodes( array $nodes, array &$map ) {
		foreach ( $nodes as $node ) {
			$map[ $node['id'] ] = $node;
			if ( ! empty( $node['children'] ) ) {
				self::index_nodes( $node['children'], $map );
			}
		}
	}

	/**
	 * Collect the valid ids used by an input list; report duplicates.
	 *
	 * @param array $list    Input elements.
	 * @param array $claimed Collector id => true (by ref).
	 * @param mixed $dups    Duplicate ids (by ref, set to a list).
	 * @return void
	 */
	private static function claim_input_ids( array $list, array &$claimed, &$dups ) {
		if ( ! isset( $dups ) ) {
			$dups = array();
		}
		foreach ( $list as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) && is_string( $el['id'] ) && self::valid_id( $el['id'] ) ) {
				if ( isset( $claimed[ $el['id'] ] ) ) {
					$dups[] = $el['id'];
				}
				$claimed[ $el['id'] ] = true;
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::claim_input_ids( array_values( $el['elements'] ), $claimed, $dups );
			}
		}
	}

	/**
	 * Is this string acceptable as an element id?
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	public static function valid_id( $id ) {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,40}$/', (string) $id );
	}

	/**
	 * One element of from_array() (recursive).
	 *
	 * @param mixed         $el           Input element.
	 * @param array|null    $parent       Parent node (new).
	 * @param bool          $parent_inner Parent isInner (columns).
	 * @param array         $old_map      id => old node.
	 * @param array         $used         Ids in use (by ref).
	 * @param callable|null $rand         Optional integer source.
	 * @param string        $where        Position for messages.
	 * @return array|string Node, or an error message.
	 */
	private static function from_array_node( $el, $parent, $parent_inner, array $old_map, array &$used, $rand, $where ) {
		if ( ! is_array( $el ) ) {
			return sprintf( 'The %s must be an object.', $where );
		}
		$el_type = isset( $el['elType'] ) && is_string( $el['elType'] ) ? trim( $el['elType'] ) : '';
		if ( '' === $el_type ) {
			return sprintf( 'The %s needs an "elType".', $where );
		}
		$widget_type = isset( $el['widgetType'] ) && is_string( $el['widgetType'] ) ? trim( $el['widgetType'] ) : '';
		if ( 'widget' === $el_type && '' === $widget_type ) {
			return sprintf( 'The %s is a widget and needs a "widgetType".', $where );
		}

		$id = ( isset( $el['id'] ) && is_string( $el['id'] ) && self::valid_id( $el['id'] ) ) ? $el['id'] : '';
		if ( '' === $id ) {
			$id           = self::generate_id( $used, $rand );
			$used[ $id ] = true;
		}

		$old = isset( $old_map[ $id ] ) ? $old_map[ $id ] : null;
		if ( null !== $old && self::input_equals_node( $el, $old ) ) {
			// The same placement rules still apply to a reused subtree.
			$inner = 'section' === $el_type || 'container' === $el_type ? null !== $parent : ( 'column' === $el_type ? (bool) $parent_inner : false );
			$error = self::placement_error( $parent, $el_type, $inner );
			if ( null !== $error ) {
				return sprintf( 'The %s (%s) cannot be placed here: %s', $where, 'widget' === $el_type ? $widget_type : $el_type, $error );
			}
			if ( 'widget' !== $el_type && $inner !== self::inner_flag( $old ) && ! self::is_atomic( $old ) ) {
				return self::build_changed_node( $el, $old, $parent, $parent_inner, $old_map, $used, $rand, $where, $id, $el_type, $widget_type );
			}
			return $old;
		}

		return self::build_changed_node( $el, $old, $parent, $parent_inner, $old_map, $used, $rand, $where, $id, $el_type, $widget_type );
	}

	/**
	 * Build a node for an input element that differs from (or has no) old node.
	 *
	 * @param array         $el           Input element.
	 * @param array|null    $old          Old node with the same id, if any.
	 * @param array|null    $parent       Parent node.
	 * @param bool          $parent_inner Parent isInner.
	 * @param array         $old_map      id => old node.
	 * @param array         $used         Ids in use (by ref).
	 * @param callable|null $rand         Optional integer source.
	 * @param string        $where        Position for messages.
	 * @param string        $id           Element id.
	 * @param string        $el_type      elType.
	 * @param string        $widget_type  widgetType.
	 * @return array|string Node, or an error message.
	 */
	private static function build_changed_node( array $el, $old, $parent, $parent_inner, array $old_map, array &$used, $rand, $where, $id, $el_type, $widget_type ) {
		$is_inner = false;
		if ( 'section' === $el_type || 'container' === $el_type ) {
			$is_inner = null !== $parent;
		} elseif ( 'column' === $el_type ) {
			$is_inner = (bool) $parent_inner;
		}
		$error = self::placement_error( $parent, $el_type, $is_inner );
		if ( null !== $error ) {
			return sprintf( 'The %s (%s) cannot be placed here: %s', $where, 'widget' === $el_type ? $widget_type : $el_type, $error );
		}
		if ( 'widget' === $el_type && ! empty( $el['elements'] ) ) {
			return sprintf( 'The %s is a widget and cannot contain elements.', $where );
		}

		// Member order: the old order when the element existed, else Elementor's own order.
		$order = array();
		if ( null !== $old ) {
			foreach ( $old['members'] as $m ) {
				$order[] = $m['key'];
			}
		} else {
			$order = array( 'id', 'elType', 'settings', 'elements', 'widget' === $el_type ? 'widgetType' : 'isInner' );
		}
		foreach ( array_keys( $el ) as $key ) {
			if ( ! in_array( (string) $key, $order, true ) ) {
				$order[] = (string) $key;
			}
		}

		$node = array(
			'id'         => $id,
			'elType'     => $el_type,
			'widgetType' => $widget_type,
			'members'    => array(),
			'children'   => null,
			'src'        => null,
		);

		foreach ( $order as $key ) {
			if ( self::HTML_CACHE_KEY === $key ) {
				continue; // Render cache: never carried into a replaced tree.
			}
			if ( 'id' === $key ) {
				$node['members'][] = self::member_for( $old, 'id', $id );
				continue;
			}
			if ( 'elements' === $key ) {
				$node['members'][] = self::new_member( 'elements', null );
				$node['children']  = array();
				$kids              = isset( $el['elements'] ) && is_array( $el['elements'] ) ? array_values( $el['elements'] ) : array();
				foreach ( $kids as $k => $child_in ) {
					$child = self::from_array_node( $child_in, array_merge( $node, array( 'children' => array() ) ), $is_inner, $old_map, $used, $rand, $where . '.elements[' . $k . ']' );
					if ( is_string( $child ) ) {
						return $child;
					}
					$node['children'][] = $child;
				}
				continue;
			}
			if ( 'isInner' === $key ) {
				if ( 'widget' === $el_type ) {
					continue;
				}
				$node['members'][] = self::member_for( $old, 'isInner', $is_inner );
				continue;
			}
			if ( 'settings' === $key ) {
				$settings = isset( $el['settings'] ) ? $el['settings'] : array();
				if ( ! is_array( $settings ) && ! ( $settings instanceof \stdClass ) ) {
					return sprintf( 'The settings of the %s must be an object.', $where );
				}
				if ( is_array( $settings ) && array() !== $settings && array_keys( $settings ) === range( 0, count( $settings ) - 1 ) ) {
					return sprintf( 'The settings of the %s must be an object, not a list.', $where );
				}
				$member = self::member_for( $old, 'settings', $settings );
				if ( null === $member['raw'] ) {
					return sprintf( 'The settings of the %s cannot be encoded as JSON (invalid UTF-8?).', $where );
				}
				$node['members'][] = $member;
				continue;
			}
			if ( 'widgetType' === $key ) {
				if ( 'widget' !== $el_type ) {
					continue;
				}
				$node['members'][] = self::member_for( $old, 'widgetType', $widget_type );
				continue;
			}
			if ( 'elType' === $key ) {
				$node['members'][] = self::member_for( $old, 'elType', $el_type );
				continue;
			}
			if ( ! array_key_exists( $key, $el ) ) {
				continue; // Member of the old element that the input dropped.
			}
			$member = self::member_for( $old, $key, $el[ $key ] );
			if ( null === $member['raw'] ) {
				return sprintf( 'The "%s" of the %s cannot be encoded as JSON.', $key, $where );
			}
			$node['members'][] = $member;
		}

		// A container type needs its elements member even when the input had none.
		if ( 'widget' !== $el_type && null === $node['children'] && array_key_exists( 'elements', $el ) ) {
			$node['children'] = array();
		}
		if ( 'widget' !== $el_type && null === $node['children'] ) {
			$node['members'][] = self::new_member( 'elements', null );
			$node['children']  = array();
		}
		if ( 'widget' !== $el_type && self::member_index( $node, 'isInner' ) < 0 ) {
			$node['members'][] = self::new_member( 'isInner', $is_inner ? 'true' : 'false' );
		}
		return $node;
	}

	/**
	 * Member for $key: the old raw text when the old member decodes to the same
	 * value, otherwise the new value encoded.
	 *
	 * @param array|null $old     Old node.
	 * @param string     $key     Member key.
	 * @param mixed      $value   New value.
	 * @return array Member ({key,kraw,raw}); raw null on encode failure.
	 */
	private static function member_for( $old, $key, $value ) {
		if ( null !== $old ) {
			$i = self::member_index( $old, $key );
			if ( $i >= 0 && null !== $old['members'][ $i ]['raw'] ) {
				$current = json_decode( $old['members'][ $i ]['raw'], true );
				$same    = self::deep_equal( $current, self::normalize_input( $value ) );
				if ( $same ) {
					return $old['members'][ $i ];
				}
				return array(
					'key'  => $key,
					'kraw' => $old['members'][ $i ]['kraw'],
					'raw'  => self::raw_for_value( $key, $value ),
				);
			}
		}
		return array(
			'key'  => $key,
			'kraw' => self::encode_value( $key ),
			'raw'  => self::raw_for_value( $key, $value ),
		);
	}

	/**
	 * Raw JSON for a member value; an empty "settings" array is written as [] like Elementor does.
	 *
	 * @param string $key   Member key.
	 * @param mixed  $value Value.
	 * @return string|null
	 */
	private static function raw_for_value( $key, $value ) {
		if ( 'settings' === $key && is_array( $value ) && array() === $value ) {
			return '[]';
		}
		return self::encode_value( $value );
	}

	/**
	 * Turn stdClass values into assoc arrays so they compare with json_decode( ..., true ).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function normalize_input( $value ) {
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::normalize_input( $v );
			}
		}
		return $value;
	}

	/**
	 * Order-insensitive deep equality for decoded JSON (scalars compared strictly).
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 * @return bool
	 */
	private static function deep_equal( $a, $b ) {
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) ) {
				return false;
			}
			foreach ( $a as $k => $v ) {
				if ( ! array_key_exists( $k, $b ) || ! self::deep_equal( $v, $b[ $k ] ) ) {
					return false;
				}
			}
			return true;
		}
		return $a === $b;
	}

	/**
	 * Does an input element describe exactly the same data as an existing node
	 * (ignoring the render cache member and key order)?
	 *
	 * @param array $el   Input element.
	 * @param array $node Existing node.
	 * @return bool
	 */
	private static function input_equals_node( array $el, array $node ) {
		$a = self::normalize_input( $el );
		$b = self::to_array_node( $node );
		unset( $a[ self::HTML_CACHE_KEY ], $b[ self::HTML_CACHE_KEY ] );
		return self::deep_equal( self::strip_cache( $a ), self::strip_cache( $b ) );
	}

	/**
	 * Remove the render cache member recursively from an element array.
	 *
	 * @param array $el Element array.
	 * @return array
	 */
	private static function strip_cache( array $el ) {
		unset( $el[ self::HTML_CACHE_KEY ] );
		if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
			foreach ( $el['elements'] as $k => $child ) {
				if ( is_array( $child ) ) {
					$el['elements'][ $k ] = self::strip_cache( $child );
				}
			}
		}
		return $el;
	}

	// ---------------------------------------------------------------------
	// Reading
	// ---------------------------------------------------------------------

	/**
	 * Readable form of a tree: a list of element arrays (decoded values, so an
	 * empty settings object reads as []). Suitable as input of from_array().
	 *
	 * @param array $tree Tree.
	 * @return array
	 */
	public static function to_array( array $tree ) {
		$out = array();
		foreach ( $tree['nodes'] as $node ) {
			$out[] = self::to_array_node( $node );
		}
		return $out;
	}

	/**
	 * Readable form of one node, in member order, without the render cache.
	 *
	 * @param array $node Node.
	 * @return array
	 */
	public static function to_array_node( array $node ) {
		$out = array();
		foreach ( $node['members'] as $m ) {
			if ( self::HTML_CACHE_KEY === $m['key'] ) {
				continue;
			}
			if ( 'elements' === $m['key'] ) {
				$kids = array();
				foreach ( (array) $node['children'] as $child ) {
					$kids[] = self::to_array_node( $child );
				}
				$out['elements'] = $kids;
				continue;
			}
			$out[ $m['key'] ] = json_decode( $m['raw'], true );
		}
		return $out;
	}

	/**
	 * Compact outline, one line per element:
	 * "<indent>[path] id type preview".
	 *
	 * @param array $tree Tree.
	 * @return string[]
	 */
	public static function outline( array $tree ) {
		$out = array();
		self::outline_nodes( $tree['nodes'], '', 0, $out );
		return $out;
	}

	/**
	 * Recursive outline builder.
	 *
	 * @param array  $nodes  Nodes.
	 * @param string $prefix Path prefix.
	 * @param int    $depth  Depth.
	 * @param array  $out    Collector (by ref).
	 * @return void
	 */
	private static function outline_nodes( array $nodes, $prefix, $depth, array &$out ) {
		foreach ( array_values( $nodes ) as $i => $node ) {
			$path = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$type = $node['elType'];
			if ( '' !== $node['widgetType'] ) {
				$type .= ':' . $node['widgetType'];
			}
			if ( self::is_atomic( $node ) ) {
				$type .= ' [atomic]';
			}
			if ( ! empty( $node['children'] ) ) {
				$count   = count( $node['children'] );
				$preview = '(+ ' . $count . ' element' . ( 1 === $count ? '' : 's' ) . ')';
			} else {
				$preview = self::preview( $node );
			}
			$out[] = str_repeat( '  ', $depth ) . '[' . $path . '] ' . $node['id'] . ' ' . $type . ( '' !== $preview ? ' ' . $preview : '' );
			if ( ! empty( $node['children'] ) ) {
				self::outline_nodes( $node['children'], $path, $depth + 1, $out );
			}
		}
	}

	/**
	 * Short visible text of a widget (<= 80 bytes on a UTF-8 boundary).
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private static function preview( array $node ) {
		$settings = self::member_value( $node, 'settings', array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		foreach ( array( 'title', 'editor', 'text', 'title_text', 'heading', 'description_text', 'button_text', 'html', 'shortcode', 'alert_title', 'paragraph', 'content' ) as $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}
			$text = self::leaf_text( $settings[ $key ], 0 );
			if ( '' === $text ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure PHP class: wp_strip_all_tags() is not available outside WordPress.
			$stripped  = strip_tags( $text );
			$collapsed = preg_replace( '~\s+~u', ' ', $stripped );
			$text      = trim( null === $collapsed ? $stripped : $collapsed );
			if ( '' === $text ) {
				continue;
			}
			if ( strlen( $text ) > 80 ) {
				$text = preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', substr( $text, 0, 79 ) ) . '…';
			}
			return $key . '="' . $text . '"';
		}
		return '';
	}

	/**
	 * First string found in a setting value (plain string or a V4 typed prop).
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth guard.
	 * @return string
	 */
	private static function leaf_text( $value, $depth ) {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) || $depth > 4 ) {
			return '';
		}
		foreach ( array( 'value', 'content' ) as $k ) {
			if ( isset( $value[ $k ] ) ) {
				$t = self::leaf_text( $value[ $k ], $depth + 1 );
				if ( '' !== $t ) {
					return $t;
				}
			}
		}
		return '';
	}
}
