<?php
/**
 * Google Review Request module - send review request emails and track stats.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\GoogleReviewRequest;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy google-review-request module. The legacy
 * module drove an on-site popup with multiple review platforms and trigger
 * config; this module is re-scoped to the group-B "send a review request
 * email + track delivery stats" flow, optionally auto-triggered when a
 * WooCommerce order completes.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		DB::ensure_module_tables(
			'google-review-request',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_grr_stats (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						recipient_email VARCHAR(255) NOT NULL DEFAULT '',
						status VARCHAR(20) NOT NULL DEFAULT '',
						order_id BIGINT UNSIGNED NULL,
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// The trigger_on_order_complete toggle is only meaningful when
		// WooCommerce is active; the front-end may hide the field in that
		// case, but the schema stays static (no dynamic schema per the
		// architecture notes).
		if ( class_exists( 'WooCommerce' ) && $this->settings->get( 'trigger_on_order_complete', false ) ) {
			add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_send_on_order_complete' ) );
		}
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
		return 'manage_options';
	}

	/**
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'google_review_url',
				'type'    => 'text',
				'label'   => __( 'Google review URL', 'ux-studio' ),
				'help'    => __( 'Link customers use to leave a review, inserted into the email via {review_url}.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'email_subject',
				'type'    => 'text',
				'label'   => __( 'Email subject', 'ux-studio' ),
				'default' => __( 'How was your experience?', 'ux-studio' ),
			),
			array(
				'key'     => 'email_body',
				'type'    => 'richtext',
				'label'   => __( 'Email body', 'ux-studio' ),
				'help'    => __( 'Use {review_url} as a placeholder for the review link.', 'ux-studio' ),
				'default' => __( 'Hi,<br><br>Thank you for your recent order! We would really appreciate it if you could take a moment to leave us a review: <a href="{review_url}">{review_url}</a><br><br>Thank you!', 'ux-studio' ),
			),
			array(
				'key'     => 'trigger_on_order_complete',
				'type'    => 'toggle',
				'label'   => __( 'Send automatically when a WooCommerce order completes', 'ux-studio' ),
				'help'    => __( 'Only takes effect if WooCommerce is active.', 'ux-studio' ),
				'default' => false,
			),
		);
	}

	/**
	 * Send a review request when a WooCommerce order transitions to
	 * "completed" (hooked only if WooCommerce is active and the toggle is
	 * on). Guarded so it never fatals if WooCommerce data is unexpectedly
	 * unavailable.
	 *
	 * @param int $order_id WooCommerce order id.
	 */
	public function maybe_send_on_order_complete( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! method_exists( $order, 'get_billing_email' ) ) {
			return;
		}

		$email = (string) $order->get_billing_email();
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$this->send_request( $email, $order_id );
	}

	/**
	 * Send a review request email, log the result, and record stats.
	 *
	 * @param string   $email    Recipient email.
	 * @param int|null $order_id Optional order id for context.
	 * @return array{success:bool,email:string}
	 */
	public function send_request( string $email, ?int $order_id = null ): array {
		$subject     = (string) $this->settings->get( 'email_subject', '' );
		$body        = (string) $this->settings->get( 'email_body', '' );
		$review_url  = (string) $this->settings->get( 'google_review_url', '' );
		$body        = str_replace( '{review_url}', $review_url, $body );

		$success = wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_grr_stats",
			array(
				'created_at'      => current_time( 'mysql' ),
				'recipient_email' => mb_substr( $email, 0, 255 ),
				'status'          => $success ? 'sent' : 'failed',
				'order_id'        => $order_id,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		if ( $success ) {
			ActivityLog::log( 'google-review-request', 'sent', 'order', (int) $order_id, array( 'email' => $email ) );
		}

		return array(
			'success' => (bool) $success,
			'email'   => $email,
		);
	}

	/**
	 * Paginated stats rows, newest first.
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_stats( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, recipient_email, status, order_id FROM {$wpdb->prefix}uxstudio_grr_stats ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
