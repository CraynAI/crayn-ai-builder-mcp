<?php
/**
 * Pure-PHP WPBakery (js_composer) shortcode tree parser and editor.
 *
 * No WordPress code is required for parse/serialize or the path-based editing
 * helpers, so they can be unit-tested with plain PHP. Only the attribute
 * escaping rules of WPBakery are reproduced here:
 *
 * - Attribute values stored in post_content cannot contain raw `"` `[` `]`.
 *   WPBakery encodes them (vc_value_to_safe / vc_value_from_safe):
 *       `"`  ->  '``'   (two backticks)
 *       `[`  ->  '`{`'  (brace between two backticks)
 *       `]`  ->  '`}`'  (brace between two backticks)
 *   Values may also be stored as '#E-8_' + base64( rawurlencode( $raw ) ).
 * - Encoded params (vc_raw_html, vc_raw_js, textarea_raw_html) store
 *   base64( rawurlencode( $raw ) ) in the shortcode content region.
 * - Links (href / vc_link params) store `url:...|title:...|target:...` with
 *   rawurlencoded pieces.
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

/**
 * Shortcode tree utilities.
 *
 * Node shape (assoc array):
 *   {
 *     tag:        string   (or "#text" for text nodes),
 *     attrs:      array<string,string>  (decoded, human-readable values),
 *     content:    string|null  (null for self-closing elements and for
 *                  elements whose inner content is represented by children),
 *     children:   node[]   (all inner nodes incl. text nodes),
 *     self_close: bool     (true when the source wrote an explicit `/]`),
 *     unclosed:   bool     (true when the source had no closing tag but non-
 *                  empty inner raw content; serialize() reproduces it verbatim)
 *   }
 *
 * Paths are arrays of 0-based indexes over addressable children only:
 * shortcode nodes plus NON-whitespace "#text" nodes. Whitespace-only text
 * nodes (indentation between tags) are ignored by indexing to keep paths
 * stable and readable, e.g. [1,0,2] = 2nd top-level row -> 1st column ->
 * 3rd element.
 */
class ShortcodeTree {

	/**
	 * Tag used for text nodes.
	 *
	 * @var string
	 */
	const TEXT_TAG = '#text';

	/**
	 * Valid tag and attribute names; the parser accepts the same pattern.
	 */
	const NAME_PATTERN = '~^[a-zA-Z_][a-zA-Z0-9_\-]*$~D';

	/**
	 * Maximum parse nesting depth (malformed-input guard).
	 *
	 * @var int
	 */
	const MAX_DEPTH = 150;

	/**
	 * Parse WPBakery content into a node tree.
	 *
	 * @param string $content Raw post_content.
	 * @return array Node list (the tree root is a list of nodes).
	 */
	public static function parse( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return array();
		}
		return self::parse_nodes( $content, 0 );
	}

	/**
	 * Serialize a tree back to shortcode content. serialize( parse( x ) ) === x
	 * for normal WPBakery output.
	 *
	 * @param array $tree Node list.
	 * @return string
	 */
	public static function serialize( array $tree ) {
		$out = '';
		foreach ( $tree as $node ) {
			$out .= self::serialize_node( $node );
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Parser internals
	// ---------------------------------------------------------------------

	/**
	 * Parse a string region into nodes.
	 *
	 * Each opening tag looks for its own balanced closing tag up front
	 * (find_close). Without one it is a bare self-closing element, which is how
	 * WPBakery stores elements without inner content. This keeps parsing
	 * polynomial: a failed match never re-parses the rest of the page.
	 *
	 * @param string $s     String to parse.
	 * @param int    $depth Current nesting depth.
	 * @return array Parsed nodes.
	 */
	private static function parse_nodes( $s, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return array( self::text_node( $s ) );
		}

		$len   = strlen( $s );
		$nodes = array();
		$buf   = '';
		$pos   = 0;

		while ( $pos < $len ) {
			$next = strpos( $s, '[', $pos );
			if ( false === $next ) {
				$buf .= substr( $s, $pos );
				break;
			}
			$buf .= substr( $s, $pos, $next - $pos );
			$pos  = $next;

			$open = self::match_open( $s, $pos );
			if ( null === $open ) {
				// A stray closing tag or a plain bracket stays literal text.
				$close = self::match_close( $s, $pos );
				$end   = null !== $close ? $close['end'] : $pos + 1;
				$buf  .= substr( $s, $pos, $end - $pos );
				$pos   = $end;
				continue;
			}

			if ( '' !== $buf ) {
				$nodes[] = self::text_node( $buf );
				$buf     = '';
			}

			if ( $open['self_close'] ) {
				$node               = self::build_element( $open['tag'], $open['attr_str'], array(), null );
				$node['self_close'] = true;
				$nodes[]            = $node;
				$pos                = $open['end'];
				continue;
			}

			$close = self::find_close( $s, $open['tag'], $open['end'] );
			if ( null === $close ) {
				$nodes[] = self::build_element( $open['tag'], $open['attr_str'], array(), null );
				$pos     = $open['end'];
				continue;
			}

			$inner    = substr( $s, $open['end'], $close['start'] - $open['end'] );
			$children = self::parse_nodes( $inner, $depth + 1 );
			$has_el   = self::has_element_child( $children );
			$nodes[]  = self::build_element( $open['tag'], $open['attr_str'], $has_el ? $children : array(), $has_el ? null : $inner );
			$pos      = $close['end'];
		}

		if ( '' !== $buf ) {
			$nodes[] = self::text_node( $buf );
		}
		return $nodes;
	}

	/**
	 * Find the balanced closing tag of an element, counting same-name openings.
	 *
	 * @param string $s    Subject.
	 * @param string $tag  Tag name.
	 * @param int    $from Offset just after the opening tag.
	 * @return array|null { start, end } of the closing tag, null when there is none.
	 */
	private static function find_close( $s, $tag, $from ) {
		$pattern = '~\[(/?)' . preg_quote( $tag, '~' ) . '(?=[\s\]/])([^\[\]]*)\]~';
		if ( ! preg_match_all( $pattern, $s, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $from ) ) {
			return null;
		}
		$depth = 1;
		foreach ( $matches as $m ) {
			if ( '/' === $m[1][0] ) {
				$depth--;
				if ( 0 === $depth ) {
					return array(
						'start' => $m[0][1],
						'end'   => $m[0][1] + strlen( $m[0][0] ),
					);
				}
			} elseif ( '/' !== substr( rtrim( $m[2][0] ), -1 ) ) {
				$depth++;
			}
		}
		return null;
	}

	/**
	 * Match an opening shortcode tag at an exact offset.
	 *
	 * @param string $s     Subject.
	 * @param int    $p     Offset.
	 * @return array|null { tag, attr_str, self_close, end }
	 */
	private static function match_open( $s, $p ) {
		if ( $p >= strlen( $s ) || '[' !== $s[ $p ] ) {
			return null;
		}
		$ok = preg_match( '~\G\[([a-zA-Z_][a-zA-Z0-9_\-]*)([^\[\]]*?)(/?)\]~', $s, $m, 0, $p );
		if ( 1 !== $ok ) {
			return null;
		}
		return array(
			'tag'        => $m[1],
			'attr_str'   => $m[2],
			'self_close' => ( '/' === $m[3] ),
			'end'        => $p + strlen( $m[0] ),
		);
	}

	/**
	 * Match a closing shortcode tag at an exact offset.
	 *
	 * @param string $s Subject.
	 * @param int    $p Offset.
	 * @return array|null { tag, end }
	 */
	private static function match_close( $s, $p ) {
		if ( $p >= strlen( $s ) || '[' !== $s[ $p ] ) {
			return null;
		}
		$ok = preg_match( '~\G\[/([a-zA-Z_][a-zA-Z0-9_\-]*)\s*\]~', $s, $m, 0, $p );
		if ( 1 !== $ok ) {
			return null;
		}
		return array(
			'tag' => $m[1],
			'end' => $p + strlen( $m[0] ),
		);
	}

	/**
	 * Parse an attribute string into a decoded assoc array.
	 *
	 * @param string $str Raw attribute substring between tag name and close.
	 * @return array<string,string>
	 */
	private static function parse_attrs( $str ) {
		$attrs = array();
		if ( '' === trim( (string) $str ) ) {
			return $attrs;
		}
		preg_match_all(
			'~([a-zA-Z_][a-zA-Z0-9_\-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\'"\[\]]+))~',
			(string) $str,
			$matches,
			PREG_SET_ORDER
		);
		foreach ( $matches as $m ) {
			$value = '';
			// PHP delivers unmatched alternatives as empty strings, so check the
			// unquoted branch first (it can only match when it consumed 1+ chars),
			// then double-quoted, then single-quoted.
			if ( array_key_exists( 4, $m ) && '' !== $m[4] ) {
				$value = $m[4];
			} elseif ( array_key_exists( 2, $m ) && '' !== $m[2] ) {
				$value = $m[2];
			} elseif ( array_key_exists( 3, $m ) && '' !== $m[3] ) {
				$value = $m[3];
			}
			$attrs[ $m[1] ] = self::decode_attr( $value );
		}
		return $attrs;
	}

	/**
	 * Parse an attribute string into a decoded assoc array. Returns the attrs
	 * and, when nothing matched and the string has content, the raw "junk"
	 * text so malformed pseudo-tags survive byte-exact.
	 *
	 * @param string $str Raw attribute substring between tag name and close.
	 * @return array { attrs: array<string,string>, junk: string }
	 */
	private static function parse_attrs_with_junk( $str ) {
		$attrs = self::parse_attrs( $str );
		$junk  = '';
		if ( array() === $attrs && '' !== trim( (string) $str ) ) {
			$junk = (string) $str;
		}
		return array(
			'attrs' => $attrs,
			'junk'  => $junk,
		);
	}

	/**
	 * Build an element node from parse state.
	 *
	 * @param string           $tag      Tag name.
	 * @param string           $attr_str Raw attribute string.
	 * @param array            $children Parsed children.
	 * @param string|null|null $content  Raw inner content (null when represented by children / self-closing).
	 * @return array
	 */
	private static function build_element( $tag, $attr_str, array $children, $content ) {
		$parsed = self::parse_attrs_with_junk( $attr_str );
		return array(
			'tag'        => (string) $tag,
			'attrs'      => $parsed['attrs'],
			'attr_junk'  => $parsed['junk'],
			'raw_attrs'  => (string) $attr_str,
			'attrs_orig' => $parsed['attrs'],
			'content'    => $content,
			'children'   => array_values( $children ),
			'self_close' => false,
			'unclosed'   => false,
		);
	}

	/**
	 * Create a text node.
	 *
	 * @param string $text Raw text.
	 * @return array
	 */
	private static function text_node( $text ) {
		return array(
			'tag'        => self::TEXT_TAG,
			'attrs'      => array(),
			'content'    => (string) $text,
			'children'   => array(),
			'self_close' => false,
			'unclosed'   => false,
		);
	}

	/**
	 * Does a child list contain at least one shortcode node?
	 *
	 * @param array $children Children.
	 * @return bool
	 */
	private static function has_element_child( array $children ) {
		foreach ( $children as $child ) {
			if ( isset( $child['tag'] ) && self::TEXT_TAG !== $child['tag'] ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------
	// Serializer
	// ---------------------------------------------------------------------

	/**
	 * Serialize one node.
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private static function serialize_node( array $node ) {
		$node = self::normalize_node( $node );

		if ( self::TEXT_TAG === $node['tag'] ) {
			return (string) $node['content'];
		}

		// Untouched attributes are written back byte-exact; only edited nodes are rebuilt.
		$untouched = null !== $node['raw_attrs'] && $node['attrs'] === $node['attrs_orig'];
		$open      = '[' . $node['tag'] . ( $untouched ? $node['raw_attrs'] : $node['attr_junk'] . self::serialize_attrs( $node['attrs'] ) );

		// Self-closing (no inner content at all).
		if ( null === $node['content'] && array() === $node['children'] ) {
			return $open . ( $node['self_close'] ? ( $untouched ? '/]' : ' /]' ) : ']' );
		}

		// Inner content represented verbatim (unclosed elements and leaf content).
		$inner = ( array() !== $node['children'] ) ? self::serialize( $node['children'] ) : ( (string) $node['content'] );

		if ( $node['unclosed'] ) {
			return $open . ']' . $inner;
		}

		return $open . ']' . $inner . '[/' . $node['tag'] . ']';
	}

	/**
	 * First tag or attribute name that would change the page structure when written.
	 *
	 * @param array $nodes Nodes.
	 * @return string|null Description of the first invalid name, null when all are valid.
	 */
	public static function find_invalid_name( array $nodes ) {
		foreach ( $nodes as $node ) {
			$tag = isset( $node['tag'] ) ? (string) $node['tag'] : '';
			if ( self::TEXT_TAG !== $tag && ! preg_match( self::NAME_PATTERN, $tag ) ) {
				return 'tag "' . $tag . '"';
			}
			foreach ( array_keys( isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array() ) as $key ) {
				if ( ! preg_match( self::NAME_PATTERN, (string) $key ) ) {
					return 'attribute "' . $key . '"';
				}
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$found = self::find_invalid_name( $node['children'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * All element tags of a tree, without text nodes.
	 *
	 * @param array $nodes Nodes.
	 * @return string[]
	 */
	public static function collect_tags( array $nodes ) {
		$tags = array();
		foreach ( $nodes as $node ) {
			if ( isset( $node['tag'] ) && self::TEXT_TAG !== $node['tag'] ) {
				$tags[] = (string) $node['tag'];
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$tags = array_merge( $tags, self::collect_tags( $node['children'] ) );
			}
		}
		return array_values( array_unique( $tags ) );
	}

	/**
	 * Serialize an attribute map (safe-escaped, double quoted).
	 *
	 * @param array $attrs Attrs.
	 * @return string
	 */
	private static function serialize_attrs( array $attrs ) {
		$out = '';
		foreach ( $attrs as $key => $value ) {
			$out .= ' ' . $key . '="' . self::encode_attr( $value ) . '"';
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Safe value helpers (WPBakery compatible)
	// ---------------------------------------------------------------------

	/**
	 * Encode a value into the WPBakery safe-attribute form:
	 * " -> ``, [ -> `{, ] -> `}.
	 *
	 * @param string $value Human-readable value.
	 * @return string
	 */
	public static function encode_attr( $value ) {
		return str_replace(
			array( '"', '[', ']' ),
			array( '``', '`{`', '`}`' ),
			(string) $value
		);
	}

	/**
	 * Decode a WPBakery safe-attribute value back to the human-readable form
	 * (same rules and order as vc_value_from_safe).
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function decode_attr( $value ) {
		$value = (string) $value;
		if ( 0 === strpos( $value, '#E-8_' ) ) {
			$value = rawurldecode( (string) base64_decode( substr( $value, 5 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		return str_replace(
			array( '`{`', '`}`', '``' ),
			array( '[', ']', '"' ),
			$value
		);
	}

	/**
	 * Encode a value into raw-html storage (base64 of rawurlencode), used by
	 * vc_raw_html, vc_raw_js and textarea_raw_html params.
	 *
	 * @param string $raw Raw text.
	 * @return string
	 */
	public static function encode_raw_html( $raw ) {
		return base64_encode( rawurlencode( (string) $raw ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode raw-html storage. Returns null when the value does not look
	 * like base64( rawurlencode( ... ) ).
	 *
	 * @param string $encoded Stored value.
	 * @return string|null
	 */
	public static function decode_raw_html( $encoded ) {
		$encoded = (string) $encoded;
		if ( '' === $encoded ) {
			return '';
		}
		$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		// Stored values are percent-encoded ASCII once base64 is removed; anything else is plain text.
		if ( false === $decoded || ! preg_match( '/^[A-Za-z0-9\-_.~!*\'()%]*$/', $decoded ) ) {
			return null;
		}
		$raw = rawurldecode( $decoded );
		// Sanity: result must be valid UTF-8 text.
		if ( '' !== $raw && ! preg_match( '//u', $raw ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Parse a WPBakery link attribute value (url:...|title:...|target:...)
	 * into an assoc array. Individual pieces are rawurldecoded.
	 *
	 * @param string $raw Stored link value (already attr-decoded).
	 * @return array<string,string>
	 */
	public static function parse_link( $raw ) {
		$link = array();
		foreach ( explode( '|', (string) $raw ) as $piece ) {
			$pos = strpos( $piece, ':' );
			if ( false === $pos ) {
				continue;
			}
			$key           = substr( $piece, 0, $pos );
			$link[ $key ] = rawurldecode( substr( $piece, $pos + 1 ) );
		}
		return $link;
	}

	/**
	 * Build a WPBakery link attribute value from an assoc array.
	 *
	 * @param array $link { url, title, target, rel, ... }.
	 * @return string
	 */
	public static function build_link( array $link ) {
		$pieces = array();
		foreach ( $link as $key => $value ) {
			$pieces[] = $key . ':' . rawurlencode( (string) $value );
		}
		return implode( '|', $pieces );
	}

	// ---------------------------------------------------------------------
	// Node helpers
	// ---------------------------------------------------------------------

	/**
	 * Normalize a node to the canonical shape (defensive for user input).
	 *
	 * @param array $node Node.
	 * @return array
	 */
	public static function normalize_node( array $node ) {
		$tag = isset( $node['tag'] ) ? (string) $node['tag'] : '';
		if ( '' === $tag ) {
			$tag = self::TEXT_TAG;
		}
		$attrs      = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		$normalized = array();
		foreach ( $attrs as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$normalized[ (string) $key ] = (string) $value;
				continue;
			}
			// Non-scalar values (e.g. a passed-through link object) are stored as JSON.
			$encoded                     = function_exists( 'json_encode' ) ? json_encode( $value ) : '';
			$normalized[ (string) $key ] = ( false === $encoded ) ? '' : (string) $encoded;
		}

		$content = null;
		if ( array_key_exists( 'content', $node ) && null !== $node['content'] ) {
			$content = (string) $node['content'];
		}

		$children = array();
		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				if ( is_array( $child ) ) {
					$children[] = self::normalize_node( $child );
				}
			}
		}

		return array(
			'tag'        => $tag,
			'attrs'      => $normalized,
			'attr_junk'  => isset( $node['attr_junk'] ) ? (string) $node['attr_junk'] : '',
			'raw_attrs'  => isset( $node['raw_attrs'] ) ? (string) $node['raw_attrs'] : null,
			'attrs_orig' => ( isset( $node['attrs_orig'] ) && is_array( $node['attrs_orig'] ) ) ? $node['attrs_orig'] : null,
			'content'    => $content,
			'children'   => $children,
			'self_close' => ! empty( $node['self_close'] ),
			'unclosed'   => ! empty( $node['unclosed'] ),
		);
	}

	/**
	 * Is this node a whitespace-only text node (ignored by path indexing)?
	 *
	 * @param array $node Node.
	 * @return bool
	 */
	private static function is_ws_text( array $node ) {
		if ( ! isset( $node['tag'] ) || self::TEXT_TAG !== $node['tag'] ) {
			return false;
		}
		$content = isset( $node['content'] ) ? (string) $node['content'] : '';
		return ( '' === trim( $content, " \t\r\n\0\x0B\f" ) );
	}

	/**
	 * Build the addressable-index -> physical-key map of a child list.
	 *
	 * @param array $children Child list.
	 * @return array { map: array<int,int>, count: int }
	 */
	private static function index_map( array $children ) {
		$map   = array();
		$count = 0;
		foreach ( $children as $key => $child ) {
			if ( is_array( $child ) && self::is_ws_text( $child ) ) {
				continue;
			}
			$map[ $count ] = $key;
			$count++;
		}
		return array(
			'map'   => $map,
			'count' => $count,
		);
	}

	/**
	 * Return a reference to the node at $path inside $tree.
	 * An empty path yields a reference to the top-level list itself.
	 *
	 * @param array $tree Top-level list (by ref chain).
	 * @param array $path Path.
	 * @return array|null
	 */
	private static function &node_ref( array &$tree, array $path ) {
		$list = &$tree;
		$n    = count( $path );
		for ( $i = 0; $i < $n; $i++ ) {
			$im  = self::index_map( $list );
			$idx = (int) $path[ $i ];
			if ( $idx < 0 || $idx >= $im['count'] ) {
				$nil = null;
				return $nil;
			}
			$node = &$list[ $im['map'][ $idx ] ];
			if ( $i + 1 < $n ) {
				if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) ) {
					$nil = null;
					return $nil;
				}
				$list = &$node['children'];
			} else {
				return $node;
			}
		}
		return $list;
	}

	// ---------------------------------------------------------------------
	// Path addressing and editing
	// ---------------------------------------------------------------------

	/**
	 * Get the node at $path. Empty path returns the whole top-level list.
	 *
	 * @param array $tree Tree (list of nodes).
	 * @param array $path Path of 0-based indexes over addressable children.
	 * @return array|null Node, the top list (empty path), or null when not found.
	 */
	public static function get_node( array $tree, array $path ) {
		$tree = array_values( $tree );
		if ( array() === $path ) {
			return $tree;
		}
		$node = self::node_ref( $tree, $path );
		return is_array( $node ) ? $node : null;
	}

	/**
	 * Insert $node under the parent at $parent_path at $index
	 * (null / out-of-range = append). Returns the modified tree or null on failure.
	 *
	 * @param array      $tree        Tree.
	 * @param array      $parent_path Path of the parent container ([] = top level).
	 * @param int|null   $index       Addressable index (optional, default append).
	 * @param array      $node        Node to insert.
	 * @return array|null
	 */
	public static function insert_node( array $tree, array $parent_path, $index, array $node ) {
		$tree = array_values( $tree );
		$node = self::normalize_node( $node );
		if ( self::TEXT_TAG === $node['tag'] && null === $node['content'] ) {
			return null;
		}

		if ( array() === $parent_path ) {
			return self::splice_insert( $tree, $index, $node );
		}

		$parent = &self::node_ref( $tree, $parent_path );
		if ( null === $parent || self::TEXT_TAG === $parent['tag'] ) {
			return null;
		}

		// Leaf nodes become containers on first insert; the old content is kept as a text node.
		if ( null !== $parent['content'] && array() === $parent['children'] ) {
			$parent['children']   = ( '' !== (string) $parent['content'] ) ? array( self::text_node( (string) $parent['content'] ) ) : array();
			$parent['content']    = null;
			$parent['unclosed']   = false;
			$parent['self_close'] = false;
		}

		$parent['children'] = self::splice_insert( $parent['children'], $index, $node );
		return $tree;
	}

	/**
	 * Splice a node into a child list at an addressable index (append when null / too large).
	 *
	 * @param array     $children Child list.
	 * @param int|null  $index    Addressable index.
	 * @param array     $node     Node.
	 * @return array
	 */
	private static function splice_insert( array $children, $index, array $node ) {
		$im = self::index_map( $children );
		if ( null === $index || $index < 0 || $index >= $im['count'] ) {
			$children[] = $node;
			return array_values( $children );
		}
		$phys = $im['map'][ (int) $index ];
		array_splice( $children, $phys, 0, array( $node ) );
		return array_values( $children );
	}

	/**
	 * Update the node at $path: merge attrs, remove attrs, and optionally
	 * replace the content. Returns the modified tree or null on failure.
	 *
	 * @param array        $tree         Tree.
	 * @param array        $path         Path.
	 * @param array|null   $attrs_merge  Attrs to set/merge (human-readable values).
	 * @param string|null  $content      New content (null = keep).
	 * @param array|string $remove_attrs Attr names to remove.
	 * @return array|null
	 */
	public static function update_node( array $tree, array $path, $attrs_merge, $content, $remove_attrs = array() ) {
		$tree = array_values( $tree );
		if ( array() === $path ) {
			return null;
		}
		$node = &self::node_ref( $tree, $path );
		if ( null === $node ) {
			return null;
		}

		// Text nodes only ever accept new content.
		if ( self::TEXT_TAG === $node['tag'] ) {
			if ( null !== $content ) {
				$node['content'] = (string) $content;
			}
			return $tree;
		}

		if ( is_array( $attrs_merge ) ) {
			foreach ( $attrs_merge as $key => $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					$node['attrs'][ (string) $key ] = (string) $value;
					continue;
				}
				// Non-scalar values (e.g. a passed-through link object) are stored as JSON.
				$encoded                         = json_encode( $value );
				$node['attrs'][ (string) $key ] = ( false === $encoded ) ? '' : (string) $encoded;
			}
		}
		foreach ( (array) $remove_attrs as $key ) {
			unset( $node['attrs'][ (string) $key ] );
		}

		if ( null !== $content ) {
			$split = self::split_content( (string) $content );
			if ( $split['has_elements'] ) {
				$node['children'] = $split['children'];
				$node['content']  = null;
			} else {
				$node['children'] = array();
				$node['content']  = $split['content'];
			}
			$node['unclosed']   = false;
			$node['self_close'] = false;
		}

		return $tree;
	}

	/**
	 * Move the node at $from_path under the parent at $to_parent_path at $index.
	 * Returns the modified tree or null on failure (bad paths, move into own subtree).
	 *
	 * @param array    $tree           Tree.
	 * @param array    $from_path      Path of the node to move.
	 * @param array    $to_parent_path Target parent path ([] = top level).
	 * @param int|null $index          Addressable index in the target (null = append).
	 * @return array|null
	 */
	public static function move_node( array $tree, array $from_path, array $to_parent_path, $index = null ) {
		$tree = array_values( $tree );
		if ( array() === $from_path ) {
			return null;
		}
		if ( self::is_within_path( $to_parent_path, $from_path ) ) {
			return null; // Target parent is the node itself or inside its subtree.
		}

		$node = self::get_node( $tree, $from_path );
		if ( null === $node ) {
			return null;
		}

		$removed = self::remove_node( $tree, $from_path );
		if ( null === $removed ) {
			return null;
		}

		// Removing the node shifts later siblings, which may lie on the target path.
		$level = count( $from_path ) - 1;
		if ( count( $to_parent_path ) > $level
			&& array_slice( $to_parent_path, 0, $level ) === array_slice( $from_path, 0, $level )
			&& (int) $to_parent_path[ $level ] > (int) $from_path[ $level ] ) {
			$to_parent_path[ $level ] = (int) $to_parent_path[ $level ] - 1;
		}

		return self::insert_node( $removed, $to_parent_path, $index, $node );
	}

	/**
	 * Is candidate path the same as, or inside the subtree rooted at, $base_path?
	 *
	 * @param array $ancestor_path Candidate parent path.
	 * @param array $base_path     Path of the moved node.
	 * @return bool
	 */
	private static function is_within_path( array $ancestor_path, array $base_path ) {
		if ( count( $ancestor_path ) < count( $base_path ) ) {
			return false;
		}
		foreach ( $base_path as $i => $value ) {
			if ( (int) $value !== (int) $ancestor_path[ $i ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Remove the node at $path. Returns the modified tree or null on failure.
	 *
	 * @param array $tree Tree.
	 * @param array $path Path.
	 * @return array|null
	 */
	public static function remove_node( array $tree, array $path ) {
		$tree = array_values( $tree );
		if ( array() === $path ) {
			return null;
		}
		$last_index  = (int) end( $path );
		$parent_path = array_slice( $path, 0, -1 );

		if ( array() === $parent_path ) {
			$children = self::splice_remove( $tree, $last_index );
			return null === $children ? null : $children;
		}

		$parent = &self::node_ref( $tree, $parent_path );
		if ( null === $parent || self::TEXT_TAG === $parent['tag'] ) {
			return null;
		}
		$count_before = self::index_map( $parent['children'] )['count'];
		$removed      = self::splice_remove( $parent['children'], $last_index );
		if ( null !== $removed ) {
			$parent['children'] = $removed;
			// A container that had exactly one child must not degrade into a
			// self-closing element when that child is removed: keep the
			// explicit "empty container" form.
			if ( 1 === $count_before && null === $parent['content'] && array() === $parent['children'] && ! $parent['self_close'] ) {
				$parent['content'] = '';
			}
			return $tree;
		}
		return null;
	}

	/**
	 * Remove an addressable child from a list; returns the new list or null when out of range.
	 *
	 * @param array $children Child list.
	 * @param int   $index    Addressable index.
	 * @return array|null
	 */
	private static function splice_remove( array $children, $index ) {
		$im = self::index_map( $children );
		if ( $index < 0 || $index >= $im['count'] ) {
			return null;
		}
		$phys = $im['map'][ $index ];
		unset( $children[ $phys ] );
		return array_values( $children );
	}

	/**
	 * Split a (new) content string into children and/or content for a node.
	 *
	 * @param string $str Raw content.
	 * @return array { children: array, content: string|null, has_elements: bool }
	 */
	private static function split_content( $str ) {
		$nodes = self::parse_nodes( (string) $str, 0 );
		foreach ( $nodes as $node ) {
			if ( isset( $node['tag'] ) && self::TEXT_TAG !== $node['tag'] ) {
				return array(
					'children'     => array_values( $nodes ),
					'content'      => null,
					'has_elements' => true,
				);
			}
		}
		return array(
			'children'     => array(),
			'content'      => (string) $str,
			'has_elements' => false,
		);
	}

	// ---------------------------------------------------------------------
	// Outline
	// ---------------------------------------------------------------------

	/**
	 * Compact human-readable summary: one line per addressable node with
	 * path, tag and a short text preview (<= 80 chars).
	 *
	 * @param array $tree Tree.
	 * @return string[]
	 */
	public static function outline( array $tree ) {
		$out = array();
		self::outline_nodes( array_values( $tree ), '', $out );
		return $out;
	}

	/**
	 * Shorten text on a UTF-8 character boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum number of bytes before the ellipsis.
	 * @return string
	 */
	private static function cut_text( $text, $max ) {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = substr( $text, 0, max( 0, $max - 1 ) );
		return preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut ) . '…';
	}

	/**
	 * Recursive outline builder.
	 *
	 * @param array  $children Child list.
	 * @param string $prefix   Path prefix.
	 * @param array  $out      Collector (by ref).
	 * @return void
	 */
	private static function outline_nodes( array $children, $prefix, array &$out ) {
		$im = self::index_map( $children );
		for ( $i = 0; $i < $im['count']; $i++ ) {
			$node = $children[ $im['map'][ $i ] ];
			$path = ( '' === $prefix ) ? (string) $i : $prefix . '.' . $i;

			$content = isset( $node['content'] ) ? (string) $node['content'] : '';
			if ( isset( $node['tag'] ) && self::TEXT_TAG === $node['tag'] ) {
				$preview = trim( preg_replace( '~\s+~s', ' ', $content ) );
			} elseif ( ! empty( $node['children'] ) ) {
				$n       = count( $node['children'] );
				$preview = '(+ ' . $n . ' node' . ( 1 === $n ? '' : 's' ) . ')';
			} else {
				if ( in_array( $node['tag'], array( 'vc_raw_html', 'vc_raw_js' ), true ) && null !== self::decode_raw_html( $content ) ) {
					$content = self::decode_raw_html( $content );
				}
				// wp_strip_all_tags when WordPress is loaded; the fallback keeps this file
				// runnable with plain php (tests/ use it standalone).
				if ( function_exists( 'wp_strip_all_tags' ) ) {
					$preview = trim( preg_replace( '~\s+~s', ' ', wp_strip_all_tags( $content ) ) );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone fallback: wp_strip_all_tags() is not available outside WordPress.
					$preview = trim( preg_replace( '~\s+~s', ' ', strip_tags( $content ) ) );
				}
				// Many elements keep their visible text in attributes.
				if ( '' === $preview && isset( $node['attrs'] ) && is_array( $node['attrs'] ) ) {
					foreach ( array( 'title', 'text', 'heading', 'label', 'image', 'link' ) as $attr ) {
						if ( isset( $node['attrs'][ $attr ] ) && '' !== (string) $node['attrs'][ $attr ] ) {
							$preview = $attr . '=' . $node['attrs'][ $attr ];
							break;
						}
					}
				}
			}
			if ( '' === $preview ) {
				$preview = '(empty)';
			}

			$line = '[' . $path . '] ' . ( isset( $node['tag'] ) ? $node['tag'] : '?' );
			$max  = 80 - strlen( $line ) - 3;
			if ( $max > 0 ) {
				$preview = self::cut_text( $preview, $max );
				$line   .= ' · ' . $preview;
			}
			$out[] = $line;

			if ( ! empty( $node['children'] ) && ( ! isset( $node['tag'] ) || self::TEXT_TAG !== $node['tag'] ) ) {
				self::outline_nodes( $node['children'], $path, $out );
			}
		}
	}
}
