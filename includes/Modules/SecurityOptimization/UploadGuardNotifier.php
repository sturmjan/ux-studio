<?php
/**
 * Upload Guard notifier: throttled admin email for new findings.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a single summary email per throttle window instead of one email
 * per finding - a bulk attack that drops dozens of malicious files in one
 * pass must not turn into a mail bomb against the site admin.
 */
final class UploadGuardNotifier {

	private const THROTTLE_OPTION = 'uxstudio_security_notify_last_sent';
	private const THROTTLE_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * Notify the admin about newly created 'scanned' findings with
	 * severity high/critical, at most once per throttle window.
	 *
	 * @param array<int,object|array<string,mixed>> $findings Findings to summarize.
	 */
	public function notify( array $findings ): void {
		$notable = array_values(
			array_filter(
				$findings,
				static function ( $f ): bool {
					$severity = is_array( $f ) ? ( $f['severity'] ?? '' ) : ( $f->severity ?? '' );
					return in_array( $severity, array( 'high', 'critical' ), true );
				}
			)
		);

		if ( empty( $notable ) ) {
			return;
		}

		$settings = (array) get_option( 'uxstudio_security_optimization', array() );
		if ( array_key_exists( 'upload_guard_notify', $settings ) && ! $settings['upload_guard_notify'] ) {
			return;
		}

		$last_sent = (int) get_option( self::THROTTLE_OPTION, 0 );
		if ( ( time() - $last_sent ) < self::THROTTLE_WINDOW ) {
			return;
		}

		$this->send_email( $notable );
		update_option( self::THROTTLE_OPTION, time(), false );
	}

	/**
	 * @param array<int,object|array<string,mixed>> $findings Findings to include in the email.
	 */
	private function send_email( array $findings ): void {
		$settings = (array) get_option( 'uxstudio_security_optimization', array() );
		$override = isset( $settings['upload_guard_notify_email'] ) ? trim( (string) $settings['upload_guard_notify_email'] ) : '';
		$to       = ( '' !== $override && is_email( $override ) ) ? $override : (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] Upload Guard: suspicious files detected', 'ux-studio' ),
			$site_name
		);

		$rows = '';
		foreach ( array_slice( $findings, 0, 20 ) as $f ) {
			$file_path = is_array( $f ) ? ( $f['file_path'] ?? '' ) : ( $f->file_path ?? '' );
			$severity  = is_array( $f ) ? ( $f['severity'] ?? '' ) : ( $f->severity ?? '' );
			$rows     .= sprintf(
				'<tr><td style="padding:6px 10px;border:1px solid #ddd;">%s</td><td style="padding:6px 10px;border:1px solid #ddd;">%s</td></tr>',
				esc_html( (string) $file_path ),
				esc_html( (string) $severity )
			);
		}

		$body  = '<html><body style="font-family:sans-serif;">';
		$body .= '<h2 style="color:#d63638;">' . esc_html__( 'Upload Guard - suspicious files', 'ux-studio' ) . '</h2>';
		$body .= '<p>' . sprintf(
			/* translators: %d: number of findings */
			esc_html__( '%d suspicious file(s) found on your site. Review and resolve them in the UX Studio admin.', 'ux-studio' ),
			count( $findings )
		) . '</p>';
		$body .= '<table style="border-collapse:collapse;width:100%;"><thead><tr>';
		$body .= '<th style="padding:6px 10px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'File', 'ux-studio' ) . '</th>';
		$body .= '<th style="padding:6px 10px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'Severity', 'ux-studio' ) . '</th>';
		$body .= '</tr></thead><tbody>' . $rows . '</tbody></table>';
		$body .= '<p>' . esc_html( admin_url( 'admin.php?page=ux-studio#/security-optimization' ) ) . '</p>';
		$body .= '</body></html>';

		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
