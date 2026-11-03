<?php
/**
 * Manual refresh of a post: row action in the list and a box in the editor (PLAN.md 39.5).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\IndexingNotice;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The link in the posts list and the side box of the classic and the block editor both go through one admin-post
 * action (capability `edit_post` of that post, nonce per post). It runs Dispatcher::refresh_now() at once and comes
 * back with a notice. The box also shows how the last refresh went and, when the option is on, a link to the
 * LinkedIn Post Inspector (LinkedIn has no API for this, so that one stays manual).
 */
final class PostActions {

	public const ACTION = 'uxstudio_sharing_refresh';

	/** Transient prefix of the one notice a user sees after the action. */
	private const NOTICE = 'uxstudio_sharing_notice_';

	private Module $module;

	/**
	 * @param Module $module Owning module.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Hooks (admin only).
	 */
	public function register(): void {
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_box' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Can the current user refresh this post, and does the module have anything to do with it?
	 *
	 * @param WP_Post $post Post.
	 */
	private function available( WP_Post $post ): bool {
		return 'publish' === $post->post_status
			&& is_post_type_viewable( $post->post_type )
			&& current_user_can( 'edit_post', $post->ID )
			&& array() !== $this->module->dispatcher()->steps();
	}

	/**
	 * Address of the action for one post.
	 *
	 * @param int $post_id Post id.
	 */
	private static function action_url( int $post_id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&post=' . $post_id ), self::ACTION . '_' . $post_id );
	}

	/**
	 * Address of the LinkedIn Post Inspector for a page.
	 *
	 * @param string $url Page address.
	 */
	public static function linkedin_url( string $url ): string {
		return 'https://www.linkedin.com/post-inspector/inspect/' . rawurlencode( $url );
	}

	/**
	 * "Refresh social preview" in the row actions.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    Post.
	 * @return array<string, string>
	 */
	public function row_action( $actions, $post ) {
		if ( is_array( $actions ) && $post instanceof WP_Post && $this->available( $post ) ) {
			$actions['uxstudio_sharing'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::action_url( (int) $post->ID ) ),
				esc_html__( 'Refresh social preview', 'ux-studio' )
			);
		}
		return $actions;
	}

	/**
	 * The box beside the editor.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 */
	public function add_box( $post_type, $post ): void {
		if ( ! $post instanceof WP_Post || ! is_post_type_viewable( (string) $post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		add_meta_box(
			'uxstudio-sharing',
			__( 'Social preview and search engines', 'ux-studio' ),
			array( $this, 'render_box' ),
			$post_type,
			'side',
			'low',
			array( '__back_compat_meta_box' => false )
		);
	}

	/**
	 * Content of the box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_box( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			echo '<p>' . esc_html__( 'Publish the page first. Only published pages are sent to Facebook and to the search engines.', 'ux-studio' ) . '</p>';
			return;
		}
		$report = get_post_meta( $post->ID, Dispatcher::META_KEY, true );
		echo '<p>' . esc_html( is_array( $report ) ? self::describe( $report ) : __( 'Not refreshed yet.', 'ux-studio' ) ) . '</p>';
		if ( $this->available( $post ) ) {
			printf(
				'<p><a href="%s" class="button">%s</a></p>',
				esc_url( self::action_url( (int) $post->ID ) ),
				esc_html__( 'Refresh social preview', 'ux-studio' )
			);
			echo '<p class="description">' . esc_html__( 'Save your changes first. Facebook and the search engines are told right away.', 'ux-studio' ) . '</p>';
		}
		if ( $this->module->setting( 'social_refresh_enabled', false ) && $this->module->setting( 'social_linkedin_link', true ) ) {
			printf(
				'<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p><p class="description">%s</p>',
				esc_url( self::linkedin_url( (string) get_permalink( $post ) ) ),
				esc_html__( 'Open the LinkedIn Post Inspector', 'ux-studio' ),
				esc_html__( 'LinkedIn has no automatic refresh. The inspector fetches the page again when you open it.', 'ux-studio' )
			);
		}
	}

	/**
	 * One sentence about the last refresh.
	 *
	 * @param array<string, mixed> $report Post meta written by the Dispatcher.
	 */
	public static function describe( array $report ): string {
		$parts = array();
		$fb    = (array) ( $report['facebook'] ?? array() );
		$when  = wp_date( 'j. n. H:i', (int) ( $report['time'] ?? time() ) );
		switch ( (string) ( $fb['status'] ?? 'off' ) ) {
			case 'ok':
				/* translators: %s: date and time. */
				$parts[] = sprintf( __( 'Facebook preview refreshed %s', 'ux-studio' ), $when );
				break;
			case 'retry':
				$parts[] = __( 'Facebook refresh waits for another try', 'ux-studio' );
				break;
			case 'failed':
				/* translators: %s: error text. */
				$parts[] = sprintf( __( 'Facebook refresh failed: %s', 'ux-studio' ), (string) ( $fb['error'] ?? '' ) );
				break;
		}
		switch ( (string) ( ( (array) ( $report['indexnow'] ?? array() ) )['status'] ?? 'off' ) ) {
			case 'ok':
				$parts[] = __( 'IndexNow sent', 'ux-studio' );
				break;
			case 'retry':
				$parts[] = __( 'IndexNow waits for another try', 'ux-studio' );
				break;
			case 'failed':
				$parts[] = __( 'IndexNow was refused', 'ux-studio' );
				break;
		}
		return $parts ? implode( ' · ', $parts ) : __( 'Nothing was sent (both options are off).', 'ux-studio' );
	}

	/**
	 * admin-post: refresh now and come back.
	 */
	public function handle(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked right below.
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION . '_' . $post_id );

		$result = $this->module->dispatcher()->refresh_now( $post );
		if ( ! $result['ok'] ) {
			$text = Eligibility::label( $result['reason'] );
			$type = 'warning';
		} else {
			$text = self::describe( $result['report'] );
			$type = isset( $result['report']['facebook']['status'] ) && in_array( $result['report']['facebook']['status'], array( 'failed', 'retry' ), true ) ? 'warning' : 'success';
		}
		set_transient( self::NOTICE . get_current_user_id(), array( 'type' => $type, 'text' => $text ), MINUTE_IN_SECONDS );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : (string) get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/**
	 * The notice after the action (shown once).
	 */
	public function notice(): void {
		$key  = self::NOTICE . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['text'] ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'success' === $data['type'] ? 'success' : 'warning',
			esc_html( (string) $data['text'] )
		);
	}
}
