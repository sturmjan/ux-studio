<?php
/**
 * Dashboard Widgets - Google Analytics 4 visits widget.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

use UxStudio\Core\Security;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy dashboard-widgets module (Bootstrap::fetchAnalyticsData
 * + getGoogleAccessToken). Authenticates with a Google service account (RS256
 * JWT -> OAuth token), calls the GA4 Data API runReport and renders sessions
 * for today / this month / this year plus a 7-day bar chart.
 *
 * The service-account JSON lives in the encrypted secret store (never in the
 * settings option and never echoed back). The widget never calls Google while
 * the dashboard renders: it prints cached data, or a placeholder that fetches
 * through the (capability + nonce protected) REST refresh route.
 */
final class Analytics {

	/** Same secret name the module uses when saving ga_service_account_json. */
	private const SECRET_GA = 'uxstudio_secret_dashboard_ga_json';

	private const TRANSIENT_TOKEN = 'uxstudio_ga_access_token';
	private const TRANSIENT_DATA  = 'uxstudio_ga_analytics_data';

	private const SCOPE     = 'https://www.googleapis.com/auth/analytics.readonly';
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const API_BASE  = 'https://analyticsdata.googleapis.com/v1beta/properties/';

	private Settings $settings;

	/**
	 * @param Settings $settings The dashboard-widgets module settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the dashboard widget.
	 */
	public function boot(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ), 1001 );
	}

	/**
	 * GA4 property id reduced to digits (the Data API path segment).
	 */
	public function property_id(): string {
		return preg_replace( '/\D+/', '', (string) $this->settings->get( 'ga_property_id', '' ) ) ?? '';
	}

	/**
	 * Whether property id and service-account key are both present.
	 */
	public function is_configured(): bool {
		return '' !== $this->property_id() && '' !== Security::get_secret( self::SECRET_GA );
	}

	/**
	 * Register the widget when enabled and configured.
	 */
	public function register_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! (bool) $this->settings->get( 'widget_analytics', false ) || ! $this->is_configured() ) {
			return;
		}
		wp_add_dashboard_widget(
			'uxstudio_analytics',
			__( 'Visits', 'ux-studio' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Widget body: cached stats, or a placeholder filled in through REST.
	 */
	public function render_widget(): void {
		$data = get_transient( self::TRANSIENT_DATA );

		if ( is_array( $data ) && isset( $data['error'] ) ) {
			echo '<p>' . esc_html( (string) $data['error'] ) . '</p>';
			return;
		}
		if ( is_array( $data ) ) {
			echo $this->render_content( $data ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside render_content().
			return;
		}

		$url   = esc_url_raw( rest_url( 'uxstudio/v1/dashboard-widgets/analytics/refresh' ) );
		$nonce = wp_create_nonce( 'wp_rest' );
		echo '<div id="uxstudio-ga-box"><p>' . esc_html__( 'Loading data…', 'ux-studio' ) . '</p></div>';
		?>
		<script>
		( function () {
			var box = document.getElementById( 'uxstudio-ga-box' );
			if ( ! box ) { return; }
			fetch( <?php echo wp_json_encode( $url ); ?>, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': <?php echo wp_json_encode( $nonce ); ?>, 'Content-Type': 'application/json' },
				body: '{}'
			} ).then( function ( r ) { return r.json(); } ).then( function ( j ) {
				var p = document.createElement( 'p' );
				if ( j && j.data && j.data.html ) { box.innerHTML = j.data.html; return; }
				p.textContent = ( j && j.message ) ? j.message : <?php echo wp_json_encode( __( 'Could not load analytics data.', 'ux-studio' ) ); ?>;
				box.replaceChildren( p );
			} ).catch( function () {
				var p = document.createElement( 'p' );
				p.textContent = <?php echo wp_json_encode( __( 'Could not load analytics data.', 'ux-studio' ) ); ?>;
				box.replaceChildren( p );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Fetch fresh data (REST refresh) and return it plus rendered HTML.
	 *
	 * @return array{ok:bool,error?:string,html?:string}
	 */
	public function refresh(): array {
		delete_transient( self::TRANSIENT_DATA );
		$data = $this->fetch();

		if ( isset( $data['error'] ) ) {
			return array(
				'ok'    => false,
				'error' => (string) $data['error'],
			);
		}

		set_transient( self::TRANSIENT_DATA, $data, HOUR_IN_SECONDS );
		return array(
			'ok'   => true,
			'html' => $this->render_content( $data ),
		);
	}

	/**
	 * Request body for the three aggregate ranges (today / month / year).
	 *
	 * @param int|null $now Unix time (testing hook), default now.
	 * @return array<string,mixed>
	 */
	public function build_summary_body( ?int $now = null ): array {
		$now         = $now ?? time();
		$today       = wp_date( 'Y-m-d', $now );
		$month_start = wp_date( 'Y-m-01', $now );
		$year_start  = wp_date( 'Y-01-01', $now );

		return array(
			'dateRanges' => array(
				array( 'startDate' => $today, 'endDate' => $today, 'name' => 'today' ),
				array( 'startDate' => $month_start, 'endDate' => $today, 'name' => 'month' ),
				array( 'startDate' => $year_start, 'endDate' => $today, 'name' => 'year' ),
			),
			'metrics'    => array( array( 'name' => 'sessions' ) ),
		);
	}

	/**
	 * Request body for the per-day sessions of the last 7 days.
	 *
	 * @param int|null $now Unix time (testing hook), default now.
	 * @return array<string,mixed>
	 */
	public function build_daily_body( ?int $now = null ): array {
		$now = $now ?? time();

		return array(
			'dateRanges' => array(
				array(
					'startDate' => wp_date( 'Y-m-d', $now - 6 * DAY_IN_SECONDS ),
					'endDate'   => wp_date( 'Y-m-d', $now ),
				),
			),
			'dimensions' => array( array( 'name' => 'date' ) ),
			'metrics'    => array( array( 'name' => 'sessions' ) ),
			'orderBys'   => array( array( 'dimension' => array( 'dimensionName' => 'date' ) ) ),
		);
	}

	/**
	 * Claim set of the service-account JWT (without signature).
	 *
	 * @param string $client_email Service-account e-mail.
	 * @param int    $now          Unix time.
	 * @return array<string,mixed>
	 */
	public function build_jwt_claims( string $client_email, int $now ): array {
		return array(
			'iss'   => $client_email,
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + HOUR_IN_SECONDS,
		);
	}

	/**
	 * Call runReport twice (aggregates + daily) and parse the result.
	 *
	 * @return array<string,mixed> Data, or array( 'error' => string ).
	 */
	private function fetch(): array {
		if ( ! $this->is_configured() ) {
			return array( 'error' => __( 'GA4 is not configured.', 'ux-studio' ) );
		}

		$token = $this->access_token();
		if ( '' === $token ) {
			return array( 'error' => __( 'Could not obtain an access token.', 'ux-studio' ) );
		}

		$summary = $this->run_report( $token, $this->build_summary_body() );
		if ( isset( $summary['error'] ) && is_string( $summary['error'] ) ) {
			return array( 'error' => $summary['error'] );
		}

		$data = array(
			'today'   => 0,
			'month'   => 0,
			'year'    => 0,
			'daily'   => array(),
			'fetched' => time(),
		);

		foreach ( (array) ( $summary['rows'] ?? array() ) as $row ) {
			$range    = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
			$sessions = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			// GA4 echoes the range name when one is given (date_range_N otherwise).
			if ( 'today' === $range || 'date_range_0' === $range ) {
				$data['today'] = $sessions;
			} elseif ( 'month' === $range || 'date_range_1' === $range ) {
				$data['month'] = $sessions;
			} elseif ( 'year' === $range || 'date_range_2' === $range ) {
				$data['year'] = $sessions;
			}
		}

		$daily = $this->run_report( $token, $this->build_daily_body() );
		if ( ! isset( $daily['error'] ) ) {
			foreach ( (array) ( $daily['rows'] ?? array() ) as $row ) {
				$raw = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
				if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ) {
					$data['daily'][] = array(
						'date'     => "{$m[1]}-{$m[2]}-{$m[3]}",
						'sessions' => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
					);
				}
			}
		}

		return $data;
	}

	/**
	 * POST one runReport request.
	 *
	 * @param string              $token OAuth access token.
	 * @param array<string,mixed> $body  Request body.
	 * @return array<string,mixed> Decoded response, or array( 'error' => string ).
	 */
	private function run_report( string $token, array $body ): array {
		$response = wp_remote_post(
			self::API_BASE . $this->property_id() . ':runReport',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$result = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $result ) ) {
			return array( 'error' => __( 'Unexpected GA4 API response.', 'ux-studio' ) );
		}
		if ( isset( $result['error'] ) ) {
			$message = is_array( $result['error'] ) ? (string) ( $result['error']['message'] ?? '' ) : '';
			return array( 'error' => '' !== $message ? $message : 'GA4 API error' );
		}
		return $result;
	}

	/**
	 * OAuth access token for the service account (cached ~50 minutes).
	 */
	private function access_token(): string {
		$cached = get_transient( self::TRANSIENT_TOKEN );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$sa = json_decode( Security::get_secret( self::SECRET_GA ), true );
		if ( ! is_array( $sa ) || empty( $sa['private_key'] ) || empty( $sa['client_email'] ) ) {
			return '';
		}

		$header = self::b64url( (string) wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64url( (string) wp_json_encode( $this->build_jwt_claims( (string) $sa['client_email'], time() ) ) );
		$input  = $header . '.' . $claims;

		$key = openssl_pkey_get_private( (string) $sa['private_key'] );
		if ( ! $key ) {
			return '';
		}
		$signature = '';
		if ( ! openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $input . '.' . self::b64url( $signature ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return '';
		}

		set_transient( self::TRANSIENT_TOKEN, (string) $body['access_token'], 50 * MINUTE_IN_SECONDS );
		return (string) $body['access_token'];
	}

	/**
	 * Base64url encoding (JWT).
	 *
	 * @param string $data Raw bytes.
	 */
	private static function b64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Escaped HTML: three stat tiles + 7-day bar chart. Inline styles because
	 * the SPA's .uxs-* CSS is not loaded on /wp-admin/index.php.
	 *
	 * @param array<string,mixed> $data Parsed data.
	 */
	private function render_content( array $data ): string {
		$tiles = array(
			__( 'Today', 'ux-studio' )      => (int) ( $data['today'] ?? 0 ),
			__( 'This month', 'ux-studio' ) => (int) ( $data['month'] ?? 0 ),
			__( 'This year', 'ux-studio' )  => (int) ( $data['year'] ?? 0 ),
		);

		$html = '<div style="display:flex;gap:12px;margin-bottom:12px">';
		foreach ( $tiles as $label => $value ) {
			$html .= '<div style="flex:1;text-align:center"><div style="font-size:22px;font-weight:600">'
				. esc_html( number_format_i18n( $value ) )
				. '</div><div style="color:#646970">' . esc_html( (string) $label ) . '</div></div>';
		}
		$html .= '</div>';

		$daily = (array) ( $data['daily'] ?? array() );
		if ( ! empty( $daily ) ) {
			$max   = max( 1, max( array_map( static fn( $d ) => (int) ( $d['sessions'] ?? 0 ), $daily ) ) );
			$html .= '<div style="display:flex;gap:6px;align-items:flex-end;height:90px">';
			foreach ( $daily as $day ) {
				$sessions = (int) ( $day['sessions'] ?? 0 );
				$label    = date_i18n( 'j.n.', (int) strtotime( (string) ( $day['date'] ?? '' ) ) );
				$height   = (int) round( ( $sessions / $max ) * 100 );
				$html    .= '<div style="flex:1;text-align:center;font-size:11px;height:100%;display:flex;flex-direction:column;justify-content:flex-end">'
					. '<div style="background:#2271b1;height:' . $height . '%;min-height:2px" title="'
					. esc_attr( $label . ': ' . number_format_i18n( $sessions ) ) . '"></div>'
					. '<div style="color:#646970">' . esc_html( $label ) . '</div></div>';
			}
			$html .= '</div>';
		}

		if ( ! empty( $data['fetched'] ) ) {
			$html .= '<p style="color:#646970;margin:8px 0 0">' . esc_html(
				sprintf(
					/* translators: %s: human time difference, e.g. "5 minutes" */
					__( 'Updated %s ago', 'ux-studio' ),
					human_time_diff( (int) $data['fetched'], time() )
				)
			) . '</p>';
		}

		return $html;
	}
}
