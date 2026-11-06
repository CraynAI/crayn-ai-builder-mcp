<?php
/**
 * Content abilities: posts, media and terms.
 *
 * @package CraynSafeMcp
 */

namespace CraynSafeMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "crayn-content" category abilities.
 */
class ContentAbilities extends AbilitiesBase {

	/**
	 * Ability names exposed on the custom MCP server.
	 *
	 * @return string[]
	 */
	public static function ability_names() {
		return array(
			'crayn-safe/posts-list',
			'crayn-safe/post-get',
			'crayn-safe/post-create',
			'crayn-safe/post-update',
			'crayn-safe/post-trash',
			'crayn-safe/media-list',
			'crayn-safe/media-upload-from-url',
			'crayn-safe/terms-list',
			'crayn-safe/term-create',
		);
	}

	/**
	 * Register every content ability.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_posts_list();
		self::register_post_get();
		self::register_post_create();
		self::register_post_update();
		self::register_post_trash();
		self::register_media_list();
		self::register_media_upload_from_url();
		self::register_terms_list();
		self::register_term_create();
	}

	/**
	 * Optional "rankmath" input property (only when Rank Math is active).
	 *
	 * @return array
	 */
	private static function rankmath_property() {
		return RankMathAbilities::is_active() ? array( 'rankmath' => RankMathAbilities::nested_input_schema() ) : array();
	}

	/**
	 * Plan the optional "rankmath" input against the current raw meta.
	 *
	 * @param array $input Ability input.
	 * @param array $raw   Current raw Rank Math meta (empty for new posts).
	 * @return array|\WP_Error|null Plan, error, or null when the input has no rankmath object.
	 */
	private static function plan_rankmath( $input, array $raw ) {
		if ( empty( $input['rankmath'] ) || ! is_array( $input['rankmath'] ) ) {
			return null;
		}
		if ( ! RankMathAbilities::is_active() ) {
			return new \WP_Error( 'crayn_safe_no_rankmath', 'The rankmath input needs Rank Math to be active.' );
		}
		return RankMathAbilities::plan( $input['rankmath'], $raw );
	}

	/**
	 * Validate a post type for creation and the publish capability for the requested status.
	 *
	 * @param string $post_type Post type.
	 * @param string $status    Requested status.
	 * @return \WP_Error|null
	 */
	private static function check_create_caps( $post_type, $status ) {
		$pto = get_post_type_object( $post_type );
		if ( ! $pto || empty( $pto->show_ui ) || 'attachment' === $post_type ) {
			return new \WP_Error( 'crayn_safe_bad_post_type', sprintf( 'Post type "%s" is not available for creation.', $post_type ) );
		}
		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot create posts of this type.' );
		}
		return self::check_publish_cap( $pto, $status );
	}

	/**
	 * Publishing (or privately publishing) needs the publish capability.
	 *
	 * @param \WP_Post_Type $pto    Post type object.
	 * @param string        $status Requested status.
	 * @return \WP_Error|null
	 */
	private static function check_publish_cap( $pto, $status ) {
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $pto->cap->publish_posts ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You are not allowed to publish posts of this type.' );
		}
		return null;
	}

	/**
	 * Posts status whitelist for inserts/updates.
	 *
	 * @return string[]
	 */
	private static function allowed_statuses() {
		return array( 'publish', 'draft', 'pending', 'private' );
	}

	/**
	 * crayn-safe/posts-list
	 *
	 * @return void
	 */
	private static function register_posts_list() {
		wp_register_ability(
			'crayn-safe/posts-list',
			array(
				'label'              => 'List posts',
				'description'        => 'List published or any status posts/pages/CPTs with pagination and optional search.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type to list. Default "post".',
							'default'     => 'post',
						),
						'status'    => array(
							'type'        => 'string',
							'description' => 'Post status filter. "any" by default.',
							'default'     => 'any',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Search term matched against titles/content.',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Number of items per page (1-50). Default 10.',
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 50,
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => 'Page number. Default 1.',
							'default'     => 1,
							'minimum'     => 1,
						),
						'dry_run'   => Safety::dry_run_property(),
					),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'items'       => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'       => array( 'type' => 'integer' ),
									'type'     => array( 'type' => 'string' ),
									'title'    => array( 'type' => 'string' ),
									'status'   => array( 'type' => 'string' ),
									'slug'     => array( 'type' => 'string' ),
									'date'     => array( 'type' => 'string' ),
									'modified' => array( 'type' => 'string' ),
									'excerpt'  => array( 'type' => 'string' ),
									'link'     => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_posts_list' ),
				'permission_callback' => array( self::class, 'permission_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, false, 'List Posts' ) ),
			)
		);
	}

	/**
	 * Execute posts-list.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_posts_list( $input ) {
		$guard = Safety::guard( 'crayn-content', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$per_page = isset( $input['per_page'] ) ? max( 1, min( 50, (int) $input['per_page'] ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$args     = array(
			'post_type'              => isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post',
			'post_status'            => isset( $input['status'] ) ? (string) $input['status'] : 'any',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'fields'                 => 'all',
			'update_post_term_cache' => false,
			'no_found_rows'          => false,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}

		// A user who cannot edit others' content sees only published items and their own:
		// titles of others' drafts and revisions must not leak through the list.
		$type_obj = get_post_type_object( $args['post_type'] );
		$see_all  = $type_obj
			? current_user_can( $type_obj->cap->edit_others_posts )
			: ( current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_others_pages' ) );
		$limit    = static function ( $where ) {
			global $wpdb;
			return $where . $wpdb->prepare( " AND ( {$wpdb->posts}.post_status = 'publish' OR {$wpdb->posts}.post_author = %d )", get_current_user_id() );
		};
		if ( ! $see_all ) {
			add_filter( 'posts_where', $limit );
		}
		$query = new \WP_Query( $args );
		remove_filter( 'posts_where', $limit );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::post_summary( $post );
		}

		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'items'       => $items,
		);
	}

	/**
	 * crayn-safe/post-get
	 *
	 * @return void
	 */
	private static function register_post_get() {
		wp_register_ability(
			'crayn-safe/post-get',
			array(
				'label'              => 'Get post',
				'description'        => 'Get a single post with full fields: title, content, excerpt, status, slug, terms, featured image and modified date.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
						),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array( 'type' => 'integer' ),
						'type'           => array( 'type' => 'string' ),
						'title'          => array( 'type' => 'string' ),
						'content'        => array( 'type' => 'string' ),
						'excerpt'        => array( 'type' => 'string' ),
						'status'         => array( 'type' => 'string' ),
						'slug'           => array( 'type' => 'string' ),
						'link'           => array( 'type' => 'string' ),
						'date'           => array( 'type' => 'string' ),
						'modified'       => array( 'type' => 'string' ),
						'author'         => array( 'type' => 'integer' ),
						'terms'          => array(
							'type'       => 'object',
							'description' => 'Term ids per taxonomy.',
						),
						'featured_image' => array(
							'type'       => 'object',
							'properties' => array(
								'id'  => array( 'type' => 'integer' ),
								'url' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_post_get' ),
				'permission_callback' => array( self::class, 'permission_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, true, 'Get Post' ) ),
			)
		);
	}

	/**
	 * Execute post-get.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_post_get( $input ) {
		$guard = Safety::guard( 'crayn-content', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'crayn_safe_post_not_found', sprintf( 'Post %d does not exist.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot access this post.' );
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) ) {
				$terms[ $taxonomy ] = array_map( 'intval', $ids );
			}
		}

		$thumb_id = (int) get_post_thumbnail_id( $post );
		$featured = array(
			'id'  => $thumb_id,
			'url' => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'full' ) : '',
		);

		return array(
			'id'             => (int) $post->ID,
			'type'           => $post->post_type,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'status'         => $post->post_status,
			'slug'           => $post->post_name,
			'link'           => (string) get_permalink( $post ),
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'author'         => (int) $post->post_author,
			'terms'          => $terms,
			'featured_image' => $featured,
		);
	}

	/**
	 * crayn-safe/post-create
	 *
	 * @return void
	 */
	private static function register_post_create() {
		wp_register_ability(
			'crayn-safe/post-create',
			array(
				'label'              => 'Create post',
				'description'        => 'Create a new post/page. Default status is draft. Accepts dry_run to preview the change without writing. Every real write is logged with a change_id you can revert.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'     => array(
							'type'        => 'string',
							'description' => 'Post type. Default "post".',
							'default'     => 'post',
						),
						'title'         => array(
							'type'        => 'string',
							'description' => 'Post title.',
						),
						'content'       => array(
							'type'        => 'string',
							'description' => 'Post content (HTML).',
							'default'     => '',
						),
						'excerpt'       => array(
							'type'        => 'string',
							'description' => 'Excerpt.',
							'default'     => '',
						),
						'status'        => array(
							'type'        => 'string',
							'enum'        => self::allowed_statuses(),
							'description' => 'Post status. Default "draft".',
							'default'     => 'draft',
						),
						'slug'          => array(
							'type'        => 'string',
							'description' => 'Post slug (optional; generated from title otherwise).',
						),
						'categories'    => array(
							'type'        => 'array',
							'description' => 'Category term IDs to assign.',
							'items'       => array( 'type' => 'integer' ),
						),
						'tags'          => array(
							'type'        => 'array',
							'description' => 'Tag term IDs to assign.',
							'items'       => array( 'type' => 'integer' ),
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => 'Attachment ID for the featured image.',
						),
						'dry_run'       => Safety::dry_run_property(),
					) + self::rankmath_property(),
					'required'   => array( 'title' ),
				),
				'output_schema'      => self::change_output_schema(
					array(
						'post_id' => array( 'type' => 'integer', 'description' => 'Created post id (0 on dry run).' ),
						'link'    => array( 'type' => 'string', 'description' => 'Post permalink (empty on dry run).' ),
					)
				),
				'execute_callback'   => array( self::class, 'execute_post_create' ),
				'permission_callback' => array( self::class, 'permission_content_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Create Post' ) ),
			)
		);
	}

	/**
	 * Execute post-create.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_post_create( $input ) {
		$dry_run = Safety::dry_run( $input );
		$guard   = Safety::guard( 'crayn-content', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$bad_lists = self::check_term_lists( $input );
		if ( is_wp_error( $bad_lists ) ) {
			return $bad_lists;
		}
		// WordPress refuses such a post on the real call: the preview must say so too.
		if ( '' === trim( (string) ( isset( $input['title'] ) ? $input['title'] : '' ) . (string) ( isset( $input['content'] ) ? $input['content'] : '' ) . (string) ( isset( $input['excerpt'] ) ? $input['excerpt'] : '' ) ) ) {
			return new \WP_Error( 'crayn_safe_empty_post', 'A post needs a title, content or excerpt.' );
		}
		$rm_plan = self::plan_rankmath( $input, array() );
		if ( is_wp_error( $rm_plan ) ) {
			return $rm_plan;
		}
		if ( $dry_run ) {
			$dr_type   = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
			$dr_status = isset( $input['status'] ) ? (string) $input['status'] : 'draft';
			$dr_error  = self::check_create_caps( $dr_type, $dr_status );
			if ( is_wp_error( $dr_error ) ) {
				return $dr_error;
			}
			$result = self::dry_run_create( $input, $dr_type );
			if ( $rm_plan ) {
				$result['after']['rankmath'] = RankMathAbilities::normalize( RankMathAbilities::simulate( array(), $rm_plan['ops'] ) );
			}
			return $result;
		}

		$status = isset( $input['status'] ) ? (string) $input['status'] : 'draft';
		if ( ! in_array( $status, self::allowed_statuses(), true ) ) {
			$status = 'draft';
		}
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';

		$cap_error = self::check_create_caps( $post_type, $status );
		if ( is_wp_error( $cap_error ) ) {
			return $cap_error;
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => (string) $input['title'],
			'post_content' => isset( $input['content'] ) ? (string) $input['content'] : '',
			'post_excerpt' => isset( $input['excerpt'] ) ? (string) $input['excerpt'] : '',
			'post_status'  => $status,
		);
		if ( ! empty( $input['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $input['slug'] );
		}

		if ( isset( $postarr['post_content'] ) ) {
			$denied = WpbakeryAbilities::raw_html_error( ShortcodeTree::parse( (string) $postarr['post_content'] ), array() );
			if ( null !== $denied ) {
				return $denied;
			}
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true ); // wp_insert_post() unslashes its input.
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::apply_terms_and_thumbnail( $post_id, $input );
		if ( $rm_plan && ! empty( $rm_plan['ops'] ) ) {
			RankMathAbilities::commit( $post_id, $rm_plan['ops'] );
		}

		$change_id = ChangeLogger::log(
			'crayn-safe/post-create',
			'post',
			$post_id,
			null,
			self::post_snapshot( $post_id ),
			'post created with status ' . $status . ( 'publish' === $status ? ' (published)' : '' )
		);
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Post %d was created.', $post_id ) );
		}

		$after_snap = self::post_snapshot( $post_id );
		unset( $after_snap['rankmath_raw'] );

		return array(
			'dry_run'   => false,
			'before'    => null,
			'after'     => $after_snap,
			'change_id' => $change_id,
			'post_id'   => (int) $post_id,
			'link'      => (string) get_permalink( $post_id ),
		);
	}

	/**
	 * Dry run result for post-create.
	 *
	 * @param array  $input Validated input.
	 * @param string $kind  'post' (reserved for future object types).
	 * @return array
	 */
	private static function dry_run_create( $input, $kind = 'post' ) {
		$after = array(
			'ID'            => 0,
			'post_title'    => (string) $input['title'],
			'post_content'  => isset( $input['content'] ) ? (string) $input['content'] : '',
			'post_excerpt'  => isset( $input['excerpt'] ) ? (string) $input['excerpt'] : '',
			'post_status'   => isset( $input['status'] ) ? (string) $input['status'] : 'draft',
			'post_name'     => ! empty( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
			'post_type'     => $kind,
			'terms'         => array(
				'category' => isset( $input['categories'] ) ? array_values( array_map( 'intval', (array) $input['categories'] ) ) : array(),
				'post_tag' => isset( $input['tags'] ) ? array_values( array_map( 'intval', (array) $input['tags'] ) ) : array(),
			),
			'featured_media' => isset( $input['featured_media'] ) ? (int) $input['featured_media'] : 0,
		);
		return array(
			'dry_run'   => true,
			'before'    => null,
			'after'     => $after,
			'change_id' => 0,
			'post_id'   => 0,
			'link'      => '',
		);
	}

	/**
	 * Refuse categories/tags that are not lists. The schema check lets "1,2" through
	 * (WordPress treats it as an array), and the write would then skip it silently.
	 *
	 * @param array $input Ability input.
	 * @return \WP_Error|null
	 */
	private static function check_term_lists( $input ) {
		foreach ( array( 'categories', 'tags' ) as $field ) {
			if ( isset( $input[ $field ] ) && ! is_array( $input[ $field ] ) ) {
				return new \WP_Error( 'crayn_safe_bad_input', sprintf( '%s must be an array of term ids.', $field ) );
			}
		}
		return null;
	}

	/**
	 * Assign categories/tags and the featured image to a post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $input   Input array that may contain categories/tags/featured_media.
	 * @return void
	 */
	private static function apply_terms_and_thumbnail( $post_id, $input ) {
		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$ids = array_map( 'intval', $input['categories'] );
			$ids = array_values( array_filter( $ids ) );
			wp_set_object_terms( $post_id, $ids, 'category', false );
		}
		if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			$ids = array_map( 'intval', $input['tags'] );
			$ids = array_values( array_filter( $ids ) );
			wp_set_object_terms( $post_id, $ids, 'post_tag', false );
		}
		if ( isset( $input['featured_media'] ) ) {
			if ( (int) $input['featured_media'] > 0 ) {
				set_post_thumbnail( $post_id, (int) $input['featured_media'] );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
	}

	/**
	 * crayn-safe/post-update
	 *
	 * @return void
	 */
	private static function register_post_update() {
		wp_register_ability(
			'crayn-safe/post-update',
			array(
				'label'              => 'Update post',
				'description'        => 'Update an existing post: title, content, excerpt, status, slug, categories, tags and featured image. Only the fields you pass are changed. Accepts dry_run to preview {before, after}. Logged with change_id for reverts.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'            => array(
							'type'        => 'integer',
							'description' => 'Post ID to update.',
						),
						'title'         => array( 'type' => 'string', 'description' => 'New title.' ),
						'content'       => array( 'type' => 'string', 'description' => 'New content (HTML).' ),
						'excerpt'       => array( 'type' => 'string', 'description' => 'New excerpt.' ),
						'status'        => array(
							'type'        => 'string',
							'enum'        => self::allowed_statuses(),
							'description' => 'New status.',
						),
						'slug'          => array( 'type' => 'string', 'description' => 'New slug.' ),
						'categories'    => array(
							'type'        => 'array',
							'description' => 'Full replacement list of category term IDs.',
							'items'       => array( 'type' => 'integer' ),
						),
						'tags'          => array(
							'type'        => 'array',
							'description' => 'Full replacement list of tag term IDs.',
							'items'       => array( 'type' => 'integer' ),
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => 'New featured image attachment id (0 removes it).',
						),
						'dry_run'       => Safety::dry_run_property(),
					) + self::rankmath_property(),
					'required'   => array( 'id' ),
				),
				'output_schema'      => self::change_output_schema(
					array(
						'post_id' => array( 'type' => 'integer' ),
					)
				),
				'execute_callback'   => array( self::class, 'execute_post_update' ),
				'permission_callback' => array( self::class, 'permission_content_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Update Post' ) ),
			)
		);
	}

	/**
	 * Execute post-update.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_post_update( $input ) {
		$dry_run = Safety::dry_run( $input );
		$guard   = Safety::guard( 'crayn-content', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$bad_lists = self::check_term_lists( $input );
		if ( is_wp_error( $bad_lists ) ) {
			return $bad_lists;
		}

		$post_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'crayn_safe_post_not_found', sprintf( 'Post %d does not exist.', $post_id ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot edit this post.' );
		}

		if ( isset( $input['status'] ) && (string) $input['status'] !== $post->post_status ) {
			$pto       = get_post_type_object( $post->post_type );
			$cap_error = $pto ? self::check_publish_cap( $pto, (string) $input['status'] ) : null;
			if ( is_wp_error( $cap_error ) ) {
				return $cap_error;
			}
		}

		$before  = self::post_snapshot( $post_id );
		$rm_plan = self::plan_rankmath( $input, isset( $before['rankmath_raw'] ) ? $before['rankmath_raw'] : array() );
		if ( is_wp_error( $rm_plan ) ) {
			return $rm_plan;
		}

		// Build the planned "after" state.
		$after = $before;
		if ( isset( $input['title'] ) ) {
			$after['post_title'] = (string) $input['title'];
		}
		if ( isset( $input['content'] ) ) {
			$after['post_content'] = (string) $input['content'];
		}
		if ( isset( $input['excerpt'] ) ) {
			$after['post_excerpt'] = (string) $input['excerpt'];
		}
		if ( isset( $input['status'] ) && in_array( (string) $input['status'], self::allowed_statuses(), true ) ) {
			$after['post_status'] = (string) $input['status'];
		}
		if ( isset( $input['slug'] ) && '' !== (string) $input['slug'] ) {
			$after['post_name'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$after['terms']['category'] = array_values( array_map( 'intval', $input['categories'] ) );
		}
		if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			$after['terms']['post_tag'] = array_values( array_map( 'intval', $input['tags'] ) );
		}
		if ( isset( $input['featured_media'] ) ) {
			$after['featured_media'] = (int) $input['featured_media'];
		}

		if ( $dry_run ) {
			if ( $rm_plan ) {
				$raw_before = isset( $before['rankmath_raw'] ) ? $before['rankmath_raw'] : array();
				$before['rankmath'] = RankMathAbilities::normalize( $raw_before );
				$after['rankmath']  = RankMathAbilities::normalize( RankMathAbilities::simulate( $raw_before, $rm_plan['ops'] ) );
			}
			unset( $before['rankmath_raw'], $after['rankmath_raw'] );
			return array(
				'dry_run'   => true,
				'before'    => $before,
				'after'     => $after,
				'change_id' => 0,
				'post_id'   => $post_id,
			);
		}

		$postarr = array( 'ID' => $post_id );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status' ) as $field ) {
			if ( $before[ $field ] !== $after[ $field ] ) {
				$postarr[ $field ] = $after[ $field ];
			}
		}
		// Only touch post_name when it actually changes (avoids slug regeneration).
		if ( $before['post_name'] !== $after['post_name'] ) {
			$postarr['post_name'] = $after['post_name'];
		}

		if ( isset( $postarr['post_content'] ) ) {
			$denied = WpbakeryAbilities::raw_html_error( ShortcodeTree::parse( (string) $postarr['post_content'] ), ShortcodeTree::parse( (string) get_post_field( 'post_content', $postarr['ID'], 'raw' ) ) );
			if ( null !== $denied ) {
				return $denied;
			}
		}

		$result = wp_update_post( wp_slash( $postarr ), true ); // wp_update_post() unslashes its input.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::apply_terms_and_thumbnail( $post_id, $input );
		if ( $rm_plan && ! empty( $rm_plan['ops'] ) ) {
			RankMathAbilities::commit( $post_id, $rm_plan['ops'] );
		}

		$change_id = ChangeLogger::log( 'crayn-safe/post-update', 'post', $post_id, $before, self::post_snapshot( $post_id ), 'post fields updated' );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Post %d was updated.', $post_id ) );
		}

		$after_snap = self::post_snapshot( $post_id );
		unset( $before['rankmath_raw'], $after_snap['rankmath_raw'] ); // Kept in the change log; too verbose for the response.

		return array(
			'dry_run'   => false,
			'before'    => $before,
			'after'     => $after_snap,
			'change_id' => $change_id,
			'post_id'   => $post_id,
		);
	}

	/**
	 * crayn-safe/post-trash
	 *
	 * @return void
	 */
	private static function register_post_trash() {
		wp_register_ability(
			'crayn-safe/post-trash',
			array(
				'label'              => 'Trash post',
				'description'        => 'Move a post to the trash. Nothing is ever hard deleted: the post keeps its content in the trash and the logged snapshot lets you restore its previous status.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Post ID to trash.',
						),
						'dry_run' => Safety::dry_run_property(),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'      => self::change_output_schema(
					array( 'post_id' => array( 'type' => 'integer' ) )
				),
				'execute_callback'   => array( self::class, 'execute_post_trash' ),
				'permission_callback' => array( self::class, 'permission_content_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, true, true, 'Trash Post' ) ),
			)
		);
	}

	/**
	 * Execute post-trash.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_post_trash( $input ) {
		$dry_run = Safety::dry_run( $input );
		$guard   = Safety::guard( 'crayn-content', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'crayn_safe_post_not_found', sprintf( 'Post %d does not exist.', $post_id ) );
		}
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot delete this post.' );
		}

		$before = array(
			'ID'          => $post_id,
			'post_status' => $post->post_status,
		);

		if ( $dry_run ) {
			$after         = $before;
			$after['post_status'] = 'trash';
			return array(
				'dry_run'   => true,
				'before'    => $before,
				'after'     => $after,
				'change_id' => 0,
				'post_id'   => $post_id,
			);
		}

		$trashed = wp_trash_post( $post_id );
		if ( ! $trashed ) {
			return new \WP_Error( 'crayn_safe_trash_failed', 'The post could not be moved to the trash.' );
		}

		$change_id = ChangeLogger::log( 'crayn-safe/post-trash', 'post', $post_id, $before, array( 'ID' => $post_id, 'post_status' => 'trash' ), 'post moved to trash (safe deletion, never hard deleted)' );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Post %d was moved to the trash.', $post_id ) );
		}

		return array(
			'dry_run'   => false,
			'before'    => $before,
			'after'     => array( 'ID' => $post_id, 'post_status' => 'trash' ),
			'change_id' => $change_id,
			'post_id'   => $post_id,
		);
	}

	/**
	 * crayn-safe/media-list
	 *
	 * @return void
	 */
	private static function register_media_list() {
		wp_register_ability(
			'crayn-safe/media-list',
			array(
				'label'              => 'List media',
				'description'        => 'List media library items with pagination and optional search.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Search attachments by title.' ),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Number of items per page (1-50). Default 10.',
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 50,
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Page number. Default 1.',
							'default'     => 1,
							'minimum'     => 1,
						),
					),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'items'       => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'        => array( 'type' => 'integer' ),
									'title'     => array( 'type' => 'string' ),
									'url'       => array( 'type' => 'string' ),
									'alt'       => array( 'type' => 'string' ),
									'mime_type' => array( 'type' => 'string' ),
									'date'      => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_media_list' ),
				'permission_callback' => array( self::class, 'permission_media_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, false, 'List Media' ) ),
			)
		);
	}

	/**
	 * Execute media-list.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_media_list( $input ) {
		$guard = Safety::guard( 'crayn-content', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$per_page = isset( $input['per_page'] ) ? max( 1, min( 50, (int) $input['per_page'] ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				's'                      => isset( $input['search'] ) ? (string) $input['search'] : '',
				'update_post_term_cache' => false,
				'no_found_rows'          => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title,
				'url'       => (string) wp_get_attachment_url( $post->ID ),
				'alt'       => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
				'mime_type' => $post->post_mime_type,
				'date'      => $post->post_date,
			);
		}

		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'items'       => $items,
		);
	}

	/**
	 * crayn-safe/media-upload-from-url
	 *
	 * @return void
	 */
	private static function register_media_upload_from_url() {
		wp_register_ability(
			'crayn-safe/media-upload-from-url',
			array(
				'label'              => 'Upload media from URL',
				'description'        => 'Download an image/file from a URL into the media library. Accepts dry_run. Logged with change_id.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'url'      => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'Source URL of the file (http/https).',
						),
						'title'    => array( 'type' => 'string', 'description' => 'Media title.' ),
						'alt'      => array( 'type' => 'string', 'description' => 'Alt text (images).' ),
						'post_id'  => array(
							'type'        => 'integer',
							'description' => 'Optional parent post to attach the media to.',
						),
						'dry_run'  => Safety::dry_run_property(),
					),
					'required'   => array( 'url' ),
				),
				'output_schema'      => self::change_output_schema(
					array(
						'attachment_id' => array( 'type' => 'integer', 'description' => 'New attachment id (0 on dry run).' ),
						'media_url'     => array( 'type' => 'string', 'description' => 'URL of the stored media (empty on dry run).' ),
					)
				),
				'execute_callback'   => array( self::class, 'execute_media_upload_from_url' ),
				'permission_callback' => static function ( $input = array() ) {
					return current_user_can( 'upload_files' ) && null === Safety::guard( 'crayn-content', false );
				},
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Upload Media From URL' ) ),
			)
		);
	}

	/**
	 * Execute media-upload-from-url.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_media_upload_from_url( $input ) {
		$dry_run = Safety::dry_run( $input );
		$guard   = Safety::guard( 'crayn-content', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$url = isset( $input['url'] ) ? (string) $input['url'] : '';
		if ( '' === $url || ! ( wp_parse_url( $url, PHP_URL_HOST ) && in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
			return new \WP_Error( 'crayn_safe_invalid_url', 'A valid http/https URL is required.' );
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'crayn_safe_unsafe_url', 'The URL is not allowed (private/internal hosts, unusual ports and non-http(s) schemes are rejected).' );
		}

		$parent = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( $parent > 0 ) {
			if ( ! get_post( $parent ) ) {
				return new \WP_Error( 'crayn_safe_post_not_found', sprintf( 'Parent post %d does not exist.', $parent ) );
			}
			if ( ! current_user_can( 'edit_post', $parent ) ) {
				return new \WP_Error( 'crayn_safe_permission_denied', 'You cannot attach media to this post.' );
			}
		}

		$title = isset( $input['title'] ) && '' !== (string) $input['title']
			? sanitize_text_field( (string) $input['title'] )
			: sanitize_text_field( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );

		$before = null;
		$after  = array(
			'title' => $title,
			'alt'   => isset( $input['alt'] ) ? (string) $input['alt'] : '',
			'url'   => $url,
		);

		if ( $dry_run ) {
			return array(
				'dry_run'       => true,
				'before'        => null,
				'after'         => $after,
				'change_id'     => 0,
				'attachment_id' => 0,
				'media_url'     => '',
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url ); // Uses wp_safe_remote_get (rejects unsafe URLs).
		if ( is_wp_error( $tmp ) ) {
			return new \WP_Error( 'crayn_safe_download_failed', 'Could not download the file: ' . $tmp->get_error_message() );
		}
		if ( filesize( $tmp ) > wp_max_upload_size() ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'crayn_safe_file_too_large', 'The downloaded file exceeds the maximum upload size.' );
		}

		$path      = wp_parse_url( $url, PHP_URL_PATH );
		$basename  = $path ? basename( $path ) : '';
		$file_name = sanitize_file_name( ( '' !== $basename ? $basename : 'crayn-safe-media-' . time() ) );

		$file_array = array(
			'name'     => $file_name,
			'tmp_name' => $tmp,
		);
		$attachment = media_handle_sideload( $file_array, $parent, $title );

		if ( is_wp_error( $attachment ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'crayn_safe_sideload_failed', 'Could not import the file into the media library: ' . $attachment->get_error_message() );
		}
		wp_delete_file( $tmp );

		if ( isset( $input['alt'] ) && '' !== (string) $input['alt'] ) {
			update_post_meta( $attachment, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
		}

		$change_id = ChangeLogger::log(
			'crayn-safe/media-upload-from-url',
			'attachment',
			$attachment,
			null,
			array(
				'title'     => $title,
				'alt'       => (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ),
				'file'      => get_attached_file( $attachment ),
				'mime_type' => get_post_mime_type( $attachment ),
			),
			'media imported from URL (note: reverting does not delete media files; remove manually if needed)'
		);
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Attachment %d was imported.', (int) $attachment ) );
		}

		return array(
			'dry_run'       => false,
			'before'        => null,
			'after'         => array(
				'title'        => $title,
				'alt'          => (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ),
				'url'          => $url,
				'mime_type'    => get_post_mime_type( $attachment ),
				'attaches_to'  => $parent,
			),
			'change_id'     => $change_id,
			'attachment_id' => (int) $attachment,
			'media_url'     => (string) wp_get_attachment_url( $attachment ),
		);
	}

	/**
	 * crayn-safe/terms-list
	 *
	 * @return void
	 */
	private static function register_terms_list() {
		wp_register_ability(
			'crayn-safe/terms-list',
			array(
				'label'              => 'List terms',
				'description'        => 'List terms of a taxonomy (e.g. category, post_tag).',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => array(
							'type'        => 'string',
							'description' => 'Taxonomy name, e.g. "category", "post_tag".',
						),
						'search'   => array( 'type' => 'string', 'description' => 'Optional search term.' ),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Number of terms (1-100). Default 50.',
							'default'     => 50,
							'minimum'     => 1,
							'maximum'     => 100,
						),
					),
					'required'   => array( 'taxonomy' ),
				),
				'output_schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => array( 'type' => 'string' ),
						'total'    => array( 'type' => 'integer' ),
						'items'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array( 'type' => 'integer' ),
									'name'       => array( 'type' => 'string' ),
									'slug'       => array( 'type' => 'string' ),
									'taxonomy'   => array( 'type' => 'string' ),
									'count'      => array( 'type' => 'integer' ),
									'parent'     => array( 'type' => 'integer' ),
									'description' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'   => array( self::class, 'execute_terms_list' ),
				'permission_callback' => array( self::class, 'permission_read' ),
				'meta'               => Safety::meta( Safety::annotations( true, false, false, 'List Terms' ) ),
			)
		);
	}

	/**
	 * Execute terms-list.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_terms_list( $input ) {
		$guard = Safety::guard( 'crayn-content', false );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'crayn_safe_taxonomy_not_found', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) );
		}

		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 50;
		$args     = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => $per_page,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'id'          => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'taxonomy'    => $term->taxonomy,
				'count'       => (int) $term->count,
				'parent'      => (int) $term->parent,
				'description' => $term->description,
			);
		}

		return array(
			'taxonomy' => $taxonomy,
			'total'    => count( $items ),
			'items'    => $items,
		);
	}

	/**
	 * crayn-safe/term-create
	 *
	 * @return void
	 */
	private static function register_term_create() {
		wp_register_ability(
			'crayn-safe/term-create',
			array(
				'label'              => 'Create term',
				'description'        => 'Create a term (category, tag, …). Accepts dry_run. Logged with change_id. Note: newly created terms are not auto-deleted by revert to keep trash-only safety.',
				'category'           => 'crayn-content',
				'input_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'   => array( 'type' => 'string', 'description' => 'Taxonomy name, e.g. "category".' ),
						'name'       => array( 'type' => 'string', 'description' => 'Term name.' ),
						'slug'       => array( 'type' => 'string', 'description' => 'Term slug (optional).' ),
						'description' => array( 'type' => 'string', 'description' => 'Term description (optional).' ),
						'parent'     => array( 'type' => 'integer', 'description' => 'Parent term id for hierarchical taxonomies.' ),
						'dry_run'    => Safety::dry_run_property(),
					),
					'required'   => array( 'taxonomy', 'name' ),
				),
				'output_schema'      => self::change_output_schema(
					array( 'term_id' => array( 'type' => 'integer', 'description' => 'Created term id (0 on dry run).' ) )
				),
				'execute_callback'   => array( self::class, 'execute_term_create' ),
				'permission_callback' => array( self::class, 'permission_term_write' ),
				'meta'               => Safety::meta( Safety::annotations( false, false, false, 'Create Term' ) ),
			)
		);
	}

	/**
	 * Execute term-create.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function execute_term_create( $input ) {
		$dry_run = Safety::dry_run( $input );
		$guard   = Safety::guard( 'crayn-content', true, $dry_run );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'crayn_safe_taxonomy_not_found', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) );
		}

		$name = isset( $input['name'] ) ? (string) $input['name'] : '';
		if ( '' === $name ) {
			return new \WP_Error( 'crayn_safe_term_name_required', 'A term name is required.' );
		}

		$after = array(
			'taxonomy'   => $taxonomy,
			'name'       => $name,
			'slug'       => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : sanitize_title( $name ),
			'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
			'parent'     => isset( $input['parent'] ) ? (int) $input['parent'] : 0,
		);

		if ( $dry_run ) {
			return array(
				'dry_run'   => true,
				'before'    => null,
				'after'     => $after,
				'change_id' => 0,
				'term_id'   => 0,
			);
		}

		$args = array();
		if ( '' !== $after['slug'] ) {
			$args['slug'] = $after['slug'];
		}
		if ( '' !== $after['description'] ) {
			$args['description'] = $after['description'];
		}
		if ( $after['parent'] > 0 ) {
			$args['parent'] = $after['parent'];
		}

		$new_term = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $args ) ); // wp_insert_term() unslashes its input.
		if ( is_wp_error( $new_term ) ) {
			return $new_term;
		}

		$term_id = (int) $new_term['term_id'];
		$change_id = ChangeLogger::log( 'crayn-safe/term-create', 'term', $term_id, null, $after, 'term created (not auto-revertible; created terms stay)' );
		if ( ! $change_id ) {
			return ChangeLogger::unlogged_error( sprintf( 'Term %d was created.', $term_id ) );
		}

		return array(
			'dry_run'   => false,
			'before'    => null,
			'after'     => $after,
			'change_id' => $change_id,
			'term_id'   => $term_id,
		);
	}

	/**
	 * Permission for read-type content abilities.
	 *
	 * @param array $input Optional input (unused).
	 * @return bool
	 */
	public static function permission_read( $input = array() ) {
		return current_user_can( 'edit_posts' ) && null === Safety::guard( 'crayn-content', false );
	}

	/**
	 * Permission for listing media (needs the media library capability).
	 *
	 * @param array $input Optional input (unused).
	 * @return bool
	 */
	public static function permission_media_read( $input = array() ) {
		return current_user_can( 'upload_files' ) && null === Safety::guard( 'crayn-content', false );
	}

	/**
	 * Permission generic for content writes (fine-grained post caps are re-checked in execute).
	 *
	 * @param array $input Optional input (unused).
	 * @return bool
	 */
	public static function permission_content_write( $input = array() ) {
		$post_id = ( is_array( $input ) && isset( $input['id'] ) ) ? (int) $input['id'] : 0;
		$can     = self::can_edit_post_or_missing( $post_id );
		return $can && null === Safety::guard( 'crayn-content', false );
	}

	/**
	 * Permission for term writes based on the taxonomy caps.
	 *
	 * @param array $input Validated input with "taxonomy".
	 * @return bool
	 */
	public static function permission_term_write( $input = array() ) {
		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
		$tax      = $taxonomy ? get_taxonomy( $taxonomy ) : null;
		if ( ! $tax ) {
			// Let the tool answer "taxonomy not found" instead of a permission error.
			return current_user_can( 'edit_posts' ) && null === Safety::guard( 'crayn-content', false );
		}
		return current_user_can( $tax->cap->edit_terms ) && null === Safety::guard( 'crayn-content', false );
	}
}
