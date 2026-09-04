<?php
/**
 * Hub-side orchestration: push local content to connected nodes; issue SSO.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on the hub. Gathers a local post (fields + categories + featured media
 * + ACF) and pushes it to one or more nodes over signed SyncClient calls,
 * recording a per-site row in the sync log. Idempotent per (post, site): the
 * remote post id is remembered in post meta so a re-push updates rather than
 * duplicates.
 */
final class Hub {

	private SiteManager $sites;

	public function __construct() {
		$this->sites = new SiteManager();
	}

	/**
	 * Push a local post to several nodes.
	 *
	 * @param int   $post_id  Local post id.
	 * @param int[] $site_ids Target site ids.
	 * @return array<int, array<string, mixed>> Per-site results.
	 */
	public function push( int $post_id, array $site_ids ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'revision' === $post->post_type || 'attachment' === $post->post_type ) {
			return array();
		}

		$results = array();
		foreach ( $site_ids as $raw_id ) {
			$results[] = $this->push_to_site( $post, (int) $raw_id );
		}
		return $results;
	}

	/**
	 * Issue an SSO login link on a node for the current hub operator.
	 *
	 * @param int $site_id Target site id.
	 * @return array<string, mixed>
	 */
	public function issue_sso( int $site_id ): array {
		$client = $this->sites->client( $site_id );
		if ( ! $client ) {
			return array( 'success' => false, 'error' => __( 'Site not found or missing API key.', 'ux-studio' ) );
		}
		$user = wp_get_current_user();
		if ( ! $user || 0 === (int) $user->ID ) {
			return array( 'success' => false, 'error' => __( 'No current user.', 'ux-studio' ) );
		}

		$result = $client->post(
			'/sso/issue',
			array(
				'operator_email' => $user->user_email,
				'operator_id'    => (int) $user->ID,
				'central_role'   => (string) ( $user->roles[0] ?? '' ),
				'target_wp_role' => (string) ( $user->roles[0] ?? 'editor' ),
				'action'         => 'dashboard',
				'return_to'      => '/wp-admin/',
				'ua_hash'        => hash( 'sha256', isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ),
				'ip'             => '',
			)
		);

		SyncLog::record(
			array(
				'site_id'     => $site_id,
				'site_name'   => (string) ( $this->sites->get( $site_id )['name'] ?? '' ),
				'action'      => 'sso_issue',
				'status'      => $result['success'] ? 'success' : 'error',
				'object_type' => 'user',
				'object_id'   => (int) $user->ID,
				'message'     => $result['success'] ? '' : (string) ( $result['error'] ?? '' ),
			)
		);

		if ( ! $result['success'] ) {
			return array( 'success' => false, 'error' => (string) ( $result['error'] ?? __( 'SSO issue failed.', 'ux-studio' ) ) );
		}
		return array(
			'success'   => true,
			'login_url' => (string) ( $result['data']['login_url'] ?? '' ),
		);
	}

	/**
	 * Push one post to one site.
	 *
	 * @param \WP_Post $post    Local post.
	 * @param int      $site_id Target site id.
	 * @return array<string, mixed>
	 */
	private function push_to_site( \WP_Post $post, int $site_id ): array {
		$site      = $this->sites->get( $site_id );
		$site_name = (string) ( $site['name'] ?? '' );
		$client    = $this->sites->client( $site_id );

		$fail = function ( string $message ) use ( $site_id, $site_name, $post ): array {
			SyncLog::record(
				array(
					'site_id'      => $site_id,
					'site_name'    => $site_name,
					'action'       => 'push_post',
					'status'       => 'error',
					'object_type'  => 'post',
					'object_id'    => $post->ID,
					'object_title' => $post->post_title,
					'message'      => $message,
				)
			);
			return array(
				'site_id'   => $site_id,
				'site_name' => $site_name,
				'success'   => false,
				'message'   => $message,
			);
		};

		if ( ! $client ) {
			return $fail( __( 'Site not found or missing API key.', 'ux-studio' ) );
		}

		$payload = array(
			'hub_post_id' => md5( home_url( '/' ) ) . ':' . $post->ID,
			'post_type'   => $post->post_type,
			'title'       => $post->post_title,
			'content'     => $post->post_content,
			'excerpt'     => $post->post_excerpt,
			'status'      => $post->post_status,
		);

		// Reconcile categories by slug (flat; hierarchy is not reconstructed).
		$remote_cat_ids = $this->reconcile_categories( $client, $post->ID );
		if ( ! empty( $remote_cat_ids ) ) {
			$payload['categories'] = $remote_cat_ids;
		}

		// Featured image: sideload then reference by the new remote id.
		$thumb_id = (int) get_post_thumbnail_id( $post->ID );
		if ( $thumb_id > 0 ) {
			$media  = new MediaTransfer( $client );
			$transfer = $media->transfer( $thumb_id );
			if ( ! empty( $transfer['success'] ) ) {
				$payload['featured_image_id'] = (int) $transfer['remote_id'];
			}
		}

		// ACF values (raw; media-typed ACF fields keep local ids by design).
		if ( AcfBridge::is_active() ) {
			$acf = AcfBridge::read( $post->ID );
			if ( ! empty( $acf ) ) {
				$payload['acf'] = $acf;
			}
		}

		// Create vs update by remembered remote id.
		$meta_key  = '_uxstudio_cs_remote_' . $site_id;
		$remote_id = (int) get_post_meta( $post->ID, $meta_key, true );

		if ( $remote_id > 0 ) {
			$result = $client->put( '/posts/' . $remote_id, $payload );
		} else {
			$result = $client->post( '/posts', $payload );
		}

		if ( empty( $result['success'] ) ) {
			return $fail( (string) ( $result['error'] ?? __( 'Push failed.', 'ux-studio' ) ) );
		}

		$new_remote_id = (int) ( $result['data']['id'] ?? $remote_id );
		if ( $new_remote_id > 0 ) {
			update_post_meta( $post->ID, $meta_key, $new_remote_id );
		}
		$this->sites->update( $site_id, array( 'last_sync' => current_time( 'mysql' ) ) );

		SyncLog::record(
			array(
				'site_id'      => $site_id,
				'site_name'    => $site_name,
				'action'       => 'push_post',
				'status'       => 'success',
				'object_type'  => 'post',
				'object_id'    => $post->ID,
				'object_title' => $post->post_title,
				'message'      => 'remote_id=' . $new_remote_id,
			)
		);
		ActivityLog::log( 'content-sync', 'push_post', 'post', $post->ID, array( 'site_id' => $site_id, 'remote_id' => $new_remote_id ) );

		return array(
			'site_id'   => $site_id,
			'site_name' => $site_name,
			'success'   => true,
			'remote_id' => $new_remote_id,
			'message'   => '',
		);
	}

	/**
	 * Ensure the post's categories exist on the node and return their remote
	 * ids. Matches by slug; creates any that are missing.
	 *
	 * @param SyncClient $client  Node client.
	 * @param int        $post_id Local post id.
	 * @return int[] Remote category ids.
	 */
	private function reconcile_categories( SyncClient $client, int $post_id ): array {
		$local_ids = wp_get_post_categories( $post_id );
		if ( empty( $local_ids ) ) {
			return array();
		}

		$remote      = $client->get( '/categories' );
		$remote_data = ( ! empty( $remote['success'] ) && is_array( $remote['data'] ) ) ? $remote['data'] : array();
		$by_slug     = array();
		foreach ( $remote_data as $cat ) {
			if ( isset( $cat['slug'], $cat['id'] ) ) {
				$by_slug[ (string) $cat['slug'] ] = (int) $cat['id'];
			}
		}

		$result = array();
		foreach ( $local_ids as $local_id ) {
			$term = get_term( (int) $local_id, 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			if ( isset( $by_slug[ $term->slug ] ) ) {
				$result[] = $by_slug[ $term->slug ];
				continue;
			}
			$created = $client->post( '/categories', array( 'name' => $term->name, 'slug' => $term->slug ) );
			if ( ! empty( $created['success'] ) && isset( $created['data']['id'] ) ) {
				$result[] = (int) $created['data']['id'];
			}
		}
		return $result;
	}
}
