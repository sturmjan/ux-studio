<?php
/**
 * Delivers a stored notification to the matching subscribers over Web Push.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PushNotifications;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts the payload per subscriber and POSTs it to each endpoint with a
 * VAPID-signed request. Expired subscriptions (404/410) are pruned; delivery
 * events are recorded for analytics.
 */
final class Sender {

	private Vapid $vapid;

	/**
	 * @param Vapid $vapid VAPID keypair provider.
	 */
	public function __construct( Vapid $vapid ) {
		$this->vapid = $vapid;
	}

	/**
	 * Send a notification to every targeted subscriber.
	 *
	 * @param array  $notification Row: id/title/body/url/icon/segment.
	 * @param string $subject      VAPID "sub" claim.
	 * @param callable $on_event   fn(int $subscriber_id, string $event): void - records delivered/failed.
	 * @param callable $on_expired fn(int $subscriber_id): void - prunes an expired subscription.
	 * @return array{targeted:int,delivered:int,failed:int,expired:int}
	 */
	public function send( array $notification, string $subject, callable $on_event, callable $on_expired ): array {
		$private_pem = $this->vapid->private_key_pem();
		$public_raw  = WebPushCrypto::b64u_decode( $this->vapid->public_key() );

		$subscribers = $this->targeted_subscribers( (string) ( $notification['segment'] ?? 'all' ) );

		$delivered = 0;
		$failed    = 0;
		$expired   = 0;

		foreach ( $subscribers as $sub ) {
			$payload = (string) wp_json_encode(
				array(
					'title'           => (string) $notification['title'],
					'body'            => (string) ( $notification['body'] ?? '' ),
					'icon'            => (string) ( $notification['icon'] ?? '' ),
					'url'             => (string) ( $notification['url'] ?? home_url( '/' ) ),
					'notification_id' => (int) $notification['id'],
				)
			);

			$result = $this->send_one( $sub, $payload, $subject, $private_pem, $public_raw );

			if ( 'ok' === $result ) {
				++$delivered;
				$on_event( (int) $sub['id'], 'delivered' );
			} elseif ( 'expired' === $result ) {
				++$expired;
				$on_expired( (int) $sub['id'] );
			} else {
				++$failed;
				$on_event( (int) $sub['id'], 'failed' );
			}
		}

		return array(
			'targeted'  => count( $subscribers ),
			'delivered' => $delivered,
			'failed'    => $failed,
			'expired'   => $expired,
		);
	}

	/**
	 * Subscribers matching a segment. 'recent_30d' filters by created_at, any
	 * other value means all.
	 *
	 * @param string $segment Segment key.
	 * @return array<int,array<string,mixed>>
	 */
	private function targeted_subscribers( string $segment ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_push_subscribers";

		if ( 'recent_30d' === $segment ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, endpoint, p256dh_key, auth_key FROM {$table} WHERE created_at >= %s ORDER BY id ASC",
					$since
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT id, endpoint, p256dh_key, auth_key FROM {$table} ORDER BY id ASC", ARRAY_A );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Send to one subscriber. Returns 'ok' | 'expired' | 'fail'.
	 *
	 * @param array  $sub         Subscriber row.
	 * @param string $payload     JSON payload.
	 * @param string $subject     VAPID subject.
	 * @param string $private_pem VAPID private PEM.
	 * @param string $public_raw  Raw VAPID public key.
	 */
	private function send_one( array $sub, string $payload, string $subject, string $private_pem, string $public_raw ): string {
		try {
			$key  = WebPushCrypto::b64u_decode( (string) $sub['p256dh_key'] );
			$auth = WebPushCrypto::b64u_decode( (string) $sub['auth_key'] );
			if ( strlen( $key ) < 65 || strlen( $auth ) < 16 ) {
				return 'fail';
			}

			$request = WebPushCrypto::build_request(
				(string) $sub['endpoint'],
				$key,
				$auth,
				$payload,
				$private_pem,
				$public_raw,
				$subject
			);

			$response = wp_remote_post(
				$request['url'],
				array(
					'headers'   => $request['headers'],
					'body'      => $request['body'],
					'timeout'   => 10,
					'sslverify' => true,
				)
			);

			if ( is_wp_error( $response ) ) {
				return 'fail';
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $status, array( 200, 201, 202 ), true ) ) {
				return 'ok';
			}
			if ( in_array( $status, array( 404, 410 ), true ) ) {
				return 'expired';
			}
			return 'fail';
		} catch ( Throwable $e ) {
			return 'fail';
		}
	}
}
