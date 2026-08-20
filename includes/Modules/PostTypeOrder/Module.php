<?php
/**
 * Post Type Order module (ported from the legacy free + pro modules, merged).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PostTypeOrder;

use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Drag-and-drop ordering for the configured post types in the admin list tables.
 * New posts get the next menu_order; the list is sorted by menu_order and the
 * new order is persisted through a REST endpoint (parameterised $wpdb updates).
 */
final class Module extends BaseModule {

	/**
	 * Post types that can never be reordered.
	 *
	 * @var string[]
	 */
	private array $excluded_post_types = array(
		'attachment',
		'elementor_library',
		'e-landing-page',
		'sfwd-courses',
	);

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->excluded_post_types = apply_filters( 'ux_studio/post_order/excluded_post_types', $this->excluded_post_types );

		add_action( 'wp_insert_post', array( $this, 'handle_post_created' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'current_screen', array( $this, 'handle_post_list_redirect' ) );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Capability required to manage this module.
	 */
	public function capability(): string {
		return 'edit_others_posts';
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	/**
	 * Enqueue the sortable script + styles on enabled list tables.
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || ! $this->is_enabled_post_type( $screen->post_type ) ) {
			return;
		}

		$base = UXSTUDIO_URL . 'includes/Modules/PostTypeOrder/assets/';
		$ver  = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_style( 'uxstudio-post-order', $base . 'post-type-order.css', array(), $ver );
		wp_enqueue_script( 'uxstudio-sortable', $base . 'sortable.min.js', array(), $ver, true );
		wp_enqueue_script( 'uxstudio-post-order', $base . 'post-type-order.js', array( 'jquery', 'uxstudio-sortable' ), $ver, true );

		wp_localize_script(
			'uxstudio-post-order',
			'uxStudioPostOrder',
			array(
				'restUrl'   => rest_url( 'uxstudio/v1' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'order_updated'  => __( '%s order updated', 'ux-studio' ),
					'error_item'     => __( 'Error updating post %s: %s', 'ux-studio' ),
					'no_items_found' => __( 'Failed to prepare items for reordering. No valid items found.', 'ux-studio' ),
					'update_failed'  => __( 'Failed to update post order. Please try again.', 'ux-studio' ),
					'items_fallback' => __( 'Items', 'ux-studio' ),
					'updating'       => __( 'Updating order...', 'ux-studio' ),
					'error'          => __( 'An error occurred.', 'ux-studio' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * List ordering (admin)
	 * ------------------------------------------------------------------- */

	/**
	 * Force the list table to sort by menu_order for enabled non-page types.
	 */
	public function handle_post_list_redirect(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || ! $this->is_enabled_post_type( $screen->post_type ) ) {
			return;
		}

		// Pages have a natural hierarchical order already.
		if ( 'page' === $screen->post_type ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['orderby'] ) ) {
			$this->redirect_to_ordered_view();
			return;
		}

		if ( 'menu_order' === $_REQUEST['orderby'] ) {
			$this->normalize_menu_order( $screen->post_type );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Redirect to the current list with orderby=menu_order applied.
	 */
	private function redirect_to_ordered_view(): void {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$parsed      = wp_parse_url( $request_uri );
		$query       = $parsed['query'] ?? '';
		$order       = http_build_query(
			array(
				'orderby' => 'menu_order',
				'order'   => 'asc',
			)
		);

		$new_query = $query ? $query . '&' . $order : $order;
		$redirect  = ( $parsed['path'] ?? '' ) . '?' . $new_query;

		wp_safe_redirect( $redirect, 302 );
		exit;
	}

	/**
	 * Normalise menu_order values when many posts share the default (1).
	 *
	 * @param string $post_type Post type.
	 */
	private function normalize_menu_order( string $post_type ): void {
		if ( ! $this->is_enabled_post_type( $post_type ) ) {
			return;
		}

		global $wpdb;

		$duplicates = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','pending','draft','future','private') AND menu_order = 1",
				$post_type
			)
		);

		if ( $duplicates ) {
			$this->renumber_post_order( $post_type );
		}
	}

	/**
	 * Renumber posts with sequential menu_order values.
	 *
	 * @param string $post_type Post type.
	 */
	private function renumber_post_order( string $post_type ): void {
		$page    = 1;
		$counter = 1;

		while ( true ) {
			$posts = get_posts(
				array(
					'post_type'   => $post_type,
					'post_status' => array( 'publish', 'pending', 'draft', 'future', 'private' ),
					'fields'      => 'ids',
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
					'numberposts' => 100,
					'paged'       => $page,
				)
			);
			if ( empty( $posts ) ) {
				break;
			}
			foreach ( $posts as $post_id ) {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'menu_order' => $counter,
					)
				);
				++$counter;
			}
			++$page;
		}
	}

	/**
	 * Give newly created posts the next menu_order value.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public function handle_post_created( $post_id, $post, $update ): void {
		if ( $update || ! $this->is_enabled_post_type( $post->post_type ) || 0 !== (int) $post->menu_order ) {
			return;
		}

		$max = $this->get_max_menu_order( $post->post_type );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'menu_order' => $max + 1,
			),
			false,
			false
		);
	}

	/**
	 * Highest menu_order currently used by a post type.
	 *
	 * @param string $post_type Post type.
	 */
	private function get_max_menu_order( string $post_type ): int {
		$posts = get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'numberposts' => 1,
				'orderby'     => 'menu_order',
				'order'       => 'DESC',
				'fields'      => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			return 0;
		}

		$top = get_post( $posts[0] );
		return $top ? (int) $top->menu_order : 0;
	}

	/* ---------------------------------------------------------------------
	 * Reorder (called by the REST controller)
	 * ------------------------------------------------------------------- */

	/**
	 * Persist a new order for a set of posts.
	 *
	 * @param array $items List of { id, order }.
	 * @return array|WP_Error
	 */
	public function reorder_items( array $items ) {
		global $wpdb;

		$results = array(
			'updated'         => array(),
			'errors'          => array(),
			'post_type'       => null,
			'post_type_label' => null,
		);

		$post_type = null;
		$posts     = array();

		foreach ( $items as $item ) {
			$id    = (int) $item['id'];
			$order = (int) $item['order'];
			$post  = get_post( $id );

			if ( ! $post ) {
				return new WP_Error( 'uxstudio_post_not_found', sprintf( /* translators: %d: post id */ __( 'Post %d not found.', 'ux-studio' ), $id ), array( 'status' => 400 ) );
			}
			if ( ! $this->is_enabled_post_type( $post->post_type ) ) {
				return new WP_Error( 'uxstudio_post_type_unsupported', __( 'Post type not supported.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to edit one of these posts.', 'ux-studio' ), array( 'status' => 403 ) );
			}

			if ( null === $post_type ) {
				$post_type    = $post->post_type;
				$type_object  = get_post_type_object( $post_type );
				$results['post_type']       = $post_type;
				$results['post_type_label'] = $type_object ? $type_object->labels->singular_name : $post_type;
			} elseif ( $post_type !== $post->post_type ) {
				return new WP_Error( 'uxstudio_mixed_types', __( 'Cannot reorder posts of different types together.', 'ux-studio' ), array( 'status' => 400 ) );
			}

			$posts[] = array(
				'post'  => $post,
				'order' => $order,
			);
		}

		// For hierarchical types, children must not appear before their parent.
		if ( $post_type && is_post_type_hierarchical( $post_type ) ) {
			$error = $this->validate_hierarchical_order( $posts );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}

		foreach ( $posts as $entry ) {
			$post  = $entry['post'];
			$order = $entry['order'];

			if ( (int) $post->menu_order === $order ) {
				continue;
			}

			// Parameterised update of menu_order only.
			$updated = $wpdb->update(
				$wpdb->posts,
				array( 'menu_order' => $order ),
				array( 'ID' => (int) $post->ID ),
				array( '%d' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				$results['errors'][] = array(
					'id'      => (int) $post->ID,
					'message' => __( 'Database update failed.', 'ux-studio' ),
				);
				continue;
			}

			clean_post_cache( (int) $post->ID );
			$results['updated'][] = (int) $post->ID;
		}

		return $results;
	}

	/**
	 * Validate parent/child ordering for hierarchical post types.
	 *
	 * @param array $posts Ordered post entries.
	 * @return true|WP_Error
	 */
	private function validate_hierarchical_order( array $posts ) {
		$positions = array();
		foreach ( $posts as $index => $entry ) {
			$positions[ (int) $entry['post']->ID ] = $index;
		}

		foreach ( $posts as $index => $entry ) {
			$parent_id = (int) $entry['post']->post_parent;
			if ( $parent_id <= 0 || ! isset( $positions[ $parent_id ] ) ) {
				continue;
			}
			if ( $index <= $positions[ $parent_id ] ) {
				return new WP_Error(
					'uxstudio_invalid_hierarchy',
					__( 'Invalid hierarchy: child items cannot appear before their parent items.', 'ux-studio' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Helpers + settings
	 * ------------------------------------------------------------------- */

	/**
	 * Whether ordering is enabled for a post type.
	 *
	 * @param string $post_type Post type.
	 */
	private function is_enabled_post_type( string $post_type ): bool {
		if ( in_array( $post_type, $this->excluded_post_types, true ) ) {
			return false;
		}
		$types = (array) $this->settings->get( 'post_types', array() );
		return in_array( $post_type, $types, true );
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Post types', 'ux-studio' ),
				'help'    => __( 'Enable drag-and-drop ordering for these post types.', 'ux-studio' ),
				'options' => $this->get_post_type_options(),
				'default' => array( 'post', 'page' ),
			),
		);
	}

	/**
	 * Public post types available for selection.
	 *
	 * @return array<string, string>
	 */
	private function get_post_type_options(): array {
		$options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( in_array( $post_type->name, $this->excluded_post_types, true ) ) {
				continue;
			}
			$options[ $post_type->name ] = $post_type->label;
		}
		return $options;
	}
}
