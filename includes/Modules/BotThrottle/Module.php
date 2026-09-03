<?php
/**
 * Bot Throttle module - detects search-engine / AI / SEO bots by User-Agent and
 * adaptively throttles them based on live server load (load tier), with an
 * optional filesystem microcache and a hard per-IP rate-limit cap.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy bot-throttle module with the full adaptive stack:
 * Detector (UA/IP/rDNS), LoadSampler (sliding-window load -> tier), Throttler
 * (per-category, per-tier plan), Microcache and a hit Log. Kept from the studio
 * rewrite: GDPR IP hashing, uxstudio_ table naming and the manage_options gate.
 */
final class Module extends BaseModule {

	/** Detected bot for the current request, or null. */
	private ?array $detected = null;

	/** Throttle plan for the current request. */
	private ?array $plan = null;

	/** Load tier for the current request. */
	private ?array $tier = null;

	private float $request_start_ms = 0.0;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->request_start_ms = microtime( true );

		\UxStudio\Core\DB::ensure_module_tables(
			'bot-throttle',
			2,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_bot_throttle_buckets (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						ip_hash VARCHAR(64) NOT NULL,
						window_start DATETIME NOT NULL,
						request_count INT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						UNIQUE KEY ip_window (ip_hash, window_start)
					) {$charset};
					CREATE TABLE {$wpdb->prefix}uxstudio_bot_throttle_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						ip_hash VARCHAR(64) NOT NULL,
						user_agent VARCHAR(255) NOT NULL DEFAULT '',
						action VARCHAR(20) NOT NULL DEFAULT '',
						bot_category VARCHAR(50) NOT NULL DEFAULT '',
						bot_name VARCHAR(100) NOT NULL DEFAULT '',
						tier VARCHAR(10) NOT NULL DEFAULT 'GREEN',
						delay_ms INT UNSIGNED NOT NULL DEFAULT 0,
						url VARCHAR(500) NOT NULL DEFAULT '',
						load_score FLOAT NOT NULL DEFAULT 0,
						response_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
						PRIMARY KEY  (id),
						KEY created_at (created_at),
						KEY bot_name (bot_name)
					) {$charset};"
				);
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		DashboardWidget::register();

		if ( ! (bool) $this->settings->get( 'enabled', true ) ) {
			return;
		}

		// Daily log cleanup (retention).
		if ( ! get_transient( 'uxstudio_bt_cleanup_done' ) ) {
			Log::cleanup( (int) $this->settings->get( 'retention_days', 14 ) );
			set_transient( 'uxstudio_bt_cleanup_done', 1, DAY_IN_SECONDS );
		}

		// template_redirect fires only for normal frontend page requests -
		// it excludes REST, wp-cron and wp-admin, so no extra gating is needed.
		add_action( 'template_redirect', array( $this, 'evaluate' ), 1 );
		add_action( 'shutdown', array( $this, 'record_metrics' ), 999 );
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
	 * Main decision point on template_redirect (frontend, priority 1).
	 */
	public function evaluate(): void {
		// Admins are never throttled (safety valve against self-lockout).
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$ip = $this->client_ip();
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

		// Hard per-IP rate-limit cap (DoS safety net, independent of bot match).
		$limit = (int) $this->settings->get( 'requests_per_minute', 120 );
		if ( $limit > 0 && $this->hit_rate_limit( $ip, $limit ) ) {
			$this->log_now( $ip, $ua, 'ratelimit', '', '', 'GREEN', 0, 429 );
			$code = (int) $this->settings->get( 'block_response_code', 429 );
			( new Throttler() )->send_block_response( 429 === $code ? 429 : 503, 60 );
			exit;
		}

		$detector = new Detector(
			array(
				'whitelist_ua' => $this->csv_list( 'whitelist_ua' ),
				'whitelist_ip' => $this->csv_list( 'whitelist_ip' ),
				'blacklist_ua' => $this->csv_list( 'blacklist_ua' ),
				'verify_rdns'  => (bool) $this->settings->get( 'verify_rdns', false ),
			)
		);
		$detected = $detector->detect( $ua, $ip );
		if ( ! $detected ) {
			return;
		}

		$sampler = new LoadSampler(
			array(
				'yellow' => (int) $this->settings->get( 'threshold_yellow', 50 ),
				'orange' => (int) $this->settings->get( 'threshold_orange', 75 ),
				'red'    => (int) $this->settings->get( 'threshold_red', 90 ),
			)
		);
		$tier = $sampler->current_tier();

		$throttler = new Throttler( $this->category_rules(), $this->delay_bounds() );
		$plan      = $throttler->plan( $detected['category'], $tier['tier'] );

		$this->detected = $detected;
		$this->plan     = $plan;
		$this->tier     = $tier;

		// Block -> send 503/429 and stop (log before exit).
		if ( 'block' === $plan['action'] ) {
			$this->log_final( $ip, $ua, 0 );
			$throttler->send_block_response( (int) $plan['status'], 60 );
			exit;
		}

		// Microcache: try to serve a cached copy, otherwise capture this render.
		if ( 'microcache' === $plan['action'] ) {
			$cache  = new Microcache();
			$key    = $cache->key( (string) ( $_SERVER['HTTP_HOST'] ?? '' ), (string) ( $_SERVER['REQUEST_URI'] ?? '' ), (string) $detected['category'] );
			$cached = $cache->get( $key );
			if ( null !== $cached ) {
				if ( ! headers_sent() ) {
					header( 'X-UXS-BotThrottle: microcache-hit' );
					header( 'X-UXS-Tier: ' . $tier['tier'] );
				}
				$throttler->apply_delay( (int) $plan['delay_ms'] );
				$this->log_final( $ip, $ua, 200 );
				echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cached full HTML document.
				exit;
			}
			$this->start_cache_capture( $detected['category'], $tier['tier'] );
		}

		// Delay before the response is rendered.
		if ( in_array( $plan['action'], array( 'delay', 'microcache' ), true ) && (int) $plan['delay_ms'] > 0 ) {
			$throttler->apply_delay( (int) $plan['delay_ms'] );
		}

		if ( ! headers_sent() ) {
			header( 'X-UXS-BotThrottle: ' . $plan['action'] );
			header( 'X-UXS-Tier: ' . $tier['tier'] );
		}
	}

	/**
	 * Buffer the rendered HTML so it can be stored in the microcache on flush.
	 *
	 * @param string $category Bot category (part of the cache key).
	 * @param string $tier     Current tier (drives cache TTL).
	 */
	private function start_cache_capture( string $category, string $tier ): void {
		$host = (string) ( $_SERVER['HTTP_HOST'] ?? '' );
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$ttl  = $this->cache_ttl_for_tier( $tier );
		ob_start(
			static function ( $buffer ) use ( $host, $uri, $category, $ttl ) {
				if ( $buffer && strlen( $buffer ) > 500 && 200 === http_response_code() ) {
					$cache = new Microcache( null, $ttl );
					$cache->set( $cache->key( $host, $uri, $category ), $buffer );
				}
				return $buffer;
			}
		);
	}

	/**
	 * @param string $tier Tier constant.
	 */
	private function cache_ttl_for_tier( string $tier ): int {
		switch ( $tier ) {
			case 'YELLOW':
				return 5 * MINUTE_IN_SECONDS;
			case 'ORANGE':
				return HOUR_IN_SECONDS;
			case 'RED':
				return DAY_IN_SECONDS;
			default:
				return MINUTE_IN_SECONDS;
		}
	}

	/**
	 * Shutdown: sample this request's load metrics and log the final status for
	 * detected bots that were not blocked/served earlier.
	 */
	public function record_metrics(): void {
		if ( 0.0 === $this->request_start_ms ) {
			return;
		}
		$rt_ms   = ( microtime( true ) - $this->request_start_ms ) * 1000;
		$queries = function_exists( 'get_num_queries' ) ? (int) get_num_queries() : 0;
		$mem_mb  = memory_get_peak_usage( true ) / 1024 / 1024;

		( new LoadSampler() )->record( $rt_ms, $queries, $mem_mb );

		if ( $this->detected && $this->plan ) {
			$status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
			$this->log_final( $this->client_ip(), (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), $status );
		}
	}

	/**
	 * Log the current detected bot's outcome (guarded against double logging).
	 *
	 * @param string $ip     Raw client IP (only its hash is stored).
	 * @param string $ua     User-Agent.
	 * @param int    $status HTTP status.
	 */
	private function log_final( string $ip, string $ua, int $status ): void {
		if ( ! $this->detected || ! $this->plan ) {
			return;
		}
		static $logged = false;
		if ( $logged ) {
			return;
		}
		$logged = true;

		Log::insert(
			array(
				'ip_hash'         => $this->hash_ip( $ip ),
				'user_agent'      => $ua,
				'action'          => (string) $this->plan['action'],
				'bot_category'    => (string) $this->detected['category'],
				'bot_name'        => (string) $this->detected['name'],
				'tier'            => (string) ( $this->tier['tier'] ?? 'GREEN' ),
				'delay_ms'        => (int) $this->plan['delay_ms'],
				'url'             => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
				'load_score'      => (float) ( $this->tier['score'] ?? 0 ),
				'response_status' => $status,
			)
		);
	}

	/**
	 * Log a one-off event (rate-limit block) that has no persisted detection.
	 */
	private function log_now( string $ip, string $ua, string $action, string $category, string $name, string $tier, int $delay, int $status ): void {
		Log::insert(
			array(
				'ip_hash'         => $this->hash_ip( $ip ),
				'user_agent'      => $ua,
				'action'          => $action,
				'bot_category'    => $category,
				'bot_name'        => $name,
				'tier'            => $tier,
				'delay_ms'        => $delay,
				'url'             => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
				'load_score'      => 0,
				'response_status' => $status,
			)
		);
	}

	/**
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		$action_choices = array(
			'pass'                => __( 'Always pass (no throttle)', 'ux-studio' ),
			'pass_with_min_delay' => __( 'Pass with minimal delay', 'ux-studio' ),
			'throttle_light'      => __( 'Throttle lightly at YELLOW+', 'ux-studio' ),
			'throttle_aggressive' => __( 'Throttle aggressively', 'ux-studio' ),
			'block'               => __( 'Block (503/429)', 'ux-studio' ),
		);

		$schema = array(
			array(
				'key'     => 'enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable bot throttling', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'verify_rdns',
				'type'    => 'toggle',
				'label'   => __( 'Reverse-DNS verification', 'ux-studio' ),
				'help'    => __( 'Confirm the UA matches the real IP (guards against UA spoofing). Slightly slower.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'requests_per_minute',
				'type'    => 'number',
				'label'   => __( 'Hard rate-limit per IP (requests/minute, 0 = off)', 'ux-studio' ),
				'help'    => __( 'DoS safety cap applied to every visitor, independent of bot detection.', 'ux-studio' ),
				'default' => 120,
			),
			array(
				'key'     => 'block_response_code',
				'type'    => 'select',
				'label'   => __( 'Rate-limit block response code', 'ux-studio' ),
				'options' => array(
					'429' => __( '429 Too Many Requests', 'ux-studio' ),
					'503' => __( '503 Service Unavailable', 'ux-studio' ),
				),
				'default' => '429',
			),
			array(
				'key'     => 'retention_days',
				'type'    => 'number',
				'label'   => __( 'Log retention (days)', 'ux-studio' ),
				'default' => 14,
			),
		);

		// Per-category action rules.
		foreach ( Signatures::categories() as $id => $cat ) {
			$schema[] = array(
				'key'     => 'rule_' . $id,
				'type'    => 'select',
				'label'   => (string) $cat['label'],
				'help'    => sprintf(
					/* translators: %s: comma-separated bot names. */
					__( 'Bots in this category: %s', 'ux-studio' ),
					implode( ', ', array_map( static fn ( $b ) => $b['name'], (array) $cat['bots'] ) )
				),
				'options' => $action_choices,
				'default' => (string) $cat['default_action'],
			);
		}

		// Load thresholds and delay bounds.
		$numbers = array(
			'threshold_yellow' => array( __( 'YELLOW threshold (load score)', 'ux-studio' ), 50 ),
			'threshold_orange' => array( __( 'ORANGE threshold (load score)', 'ux-studio' ), 75 ),
			'threshold_red'    => array( __( 'RED threshold (load score)', 'ux-studio' ), 90 ),
			'min_delay_ms'     => array( __( 'Min delay (ms)', 'ux-studio' ), 0 ),
			'light_min'        => array( __( 'Light delay min (ms)', 'ux-studio' ), 300 ),
			'light_max'        => array( __( 'Light delay max (ms)', 'ux-studio' ), 1500 ),
			'aggressive_min'   => array( __( 'Aggressive delay min (ms)', 'ux-studio' ), 2000 ),
			'aggressive_max'   => array( __( 'Aggressive delay max (ms)', 'ux-studio' ), 5000 ),
			'red_min'          => array( __( 'RED delay min (ms)', 'ux-studio' ), 5000 ),
			'red_max'          => array( __( 'RED delay max (ms)', 'ux-studio' ), 10000 ),
		);
		foreach ( $numbers as $key => $def ) {
			$schema[] = array(
				'key'     => $key,
				'type'    => 'number',
				'label'   => $def[0],
				'default' => $def[1],
			);
		}

		// Whitelist / blacklist.
		$schema[] = array(
			'key'     => 'whitelist_ua',
			'type'    => 'textarea',
			'label'   => __( 'Whitelist User-Agents (one per line)', 'ux-studio' ),
			'help'    => __( 'UA fragments that are NEVER throttled.', 'ux-studio' ),
			'default' => '',
		);
		$schema[] = array(
			'key'     => 'whitelist_ip',
			'type'    => 'textarea',
			'label'   => __( 'Whitelist IP / CIDR (one per line)', 'ux-studio' ),
			'help'    => __( 'e.g. 192.168.0.0/16', 'ux-studio' ),
			'default' => '',
		);
		$schema[] = array(
			'key'     => 'blacklist_ua',
			'type'    => 'textarea',
			'label'   => __( 'Blacklist User-Agents (one per line)', 'ux-studio' ),
			'help'    => __( 'UA fragments that are ALWAYS aggressively throttled.', 'ux-studio' ),
			'default' => "AhrefsBot\nSemrushBot\nMJ12bot\nDotBot\nPetalBot\nBLEXBot\nMegaIndex\nSerpstatBot\nrogerbot\nDataForSeoBot",
		);

		return $schema;
	}

	/**
	 * Per-category rule map (only categories with a non-default override).
	 *
	 * @return array<string,array{action:string}>
	 */
	private function category_rules(): array {
		$rules = array();
		foreach ( array_keys( Signatures::categories() ) as $id ) {
			$action = (string) $this->settings->get( 'rule_' . $id, '' );
			if ( '' !== $action ) {
				$rules[ $id ] = array( 'action' => $action );
			}
		}
		return $rules;
	}

	/**
	 * Delay bounds pulled from settings.
	 *
	 * @return array<string,int>
	 */
	private function delay_bounds(): array {
		return array(
			'min_delay_ms'   => (int) $this->settings->get( 'min_delay_ms', 0 ),
			'light_min'      => (int) $this->settings->get( 'light_min', 300 ),
			'light_max'      => (int) $this->settings->get( 'light_max', 1500 ),
			'aggressive_min' => (int) $this->settings->get( 'aggressive_min', 2000 ),
			'aggressive_max' => (int) $this->settings->get( 'aggressive_max', 5000 ),
			'red_min'        => (int) $this->settings->get( 'red_min', 5000 ),
			'red_max'        => (int) $this->settings->get( 'red_max', 10000 ),
		);
	}

	/**
	 * Split a newline/comma separated setting into a trimmed list.
	 *
	 * @param string $key Setting key.
	 * @return string[]
	 */
	private function csv_list( string $key ): array {
		$val = (string) $this->settings->get( $key, '' );
		if ( '' === $val ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|,/', $val ) ) ) );
	}

	/**
	 * Atomically increments the fixed 1-minute-window bucket for this IP and
	 * reports whether the request is now over the limit. Opportunistically
	 * prunes stale bucket rows (1% chance per call).
	 *
	 * @param string $ip    Raw client IP (only its hash is stored).
	 * @param int    $limit requests_per_minute setting.
	 */
	private function hit_rate_limit( string $ip, int $limit ): bool {
		global $wpdb;

		$ip_hash      = $this->hash_ip( $ip );
		$window_start = gmdate( 'Y-m-d H:i:00' );
		$table        = "{$wpdb->prefix}uxstudio_bot_throttle_buckets";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table fixed, values parameterized.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (ip_hash, window_start, request_count) VALUES (%s, %s, 1)
				ON DUPLICATE KEY UPDATE request_count = request_count + 1",
				$ip_hash,
				$window_start
			)
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT request_count FROM {$table} WHERE ip_hash = %s AND window_start = %s",
				$ip_hash,
				$window_start
			)
		);

		if ( wp_rand( 1, 100 ) === 1 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE window_start < %s",
					gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) )
				)
			);
		}

		return $count > $limit;
	}

	/**
	 * Client IP. REMOTE_ADDR only: honoring proxy headers (X-Forwarded-For etc.)
	 * without a trusted-proxy allowlist would let a client spoof its way past
	 * both the rate limiter and the IP whitelist.
	 */
	private function client_ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * GDPR: only ever store a salted hash of the IP, never the raw address.
	 *
	 * @param string $ip Raw client IP.
	 */
	private function hash_ip( string $ip ): string {
		return hash( 'sha256', $ip . wp_salt() );
	}

	/**
	 * Bot categories + current rule for the REST/admin surface.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function categories_overview(): array {
		$out = array();
		foreach ( Signatures::categories() as $id => $cat ) {
			$out[] = array(
				'id'      => $id,
				'label'   => (string) $cat['label'],
				'action'  => (string) $this->settings->get( 'rule_' . $id, $cat['default_action'] ),
				'bots'    => array_map( static fn ( $b ) => $b['name'], (array) $cat['bots'] ),
			);
		}
		return $out;
	}

	/**
	 * Dashboard payload: current tier, 24h/1h stats and microcache size.
	 *
	 * @return array<string,mixed>
	 */
	public function dashboard(): array {
		$tier  = ( new LoadSampler() )->current_tier();
		$cache = ( new Microcache() )->size();
		return array(
			'tier'       => $tier,
			'stats_24h'  => Log::stats( 24 ),
			'stats_1h'   => Log::stats( 1 ),
			'cache'      => $cache,
			'categories' => $this->categories_overview(),
		);
	}

	/**
	 * Simulate how a given UA/IP would be handled at the current tier.
	 *
	 * @param string $ua Test User-Agent.
	 * @param string $ip Test IP.
	 * @return array<string,mixed>
	 */
	public function test_ua( string $ua, string $ip ): array {
		$detector = new Detector(
			array(
				'whitelist_ua' => $this->csv_list( 'whitelist_ua' ),
				'whitelist_ip' => $this->csv_list( 'whitelist_ip' ),
				'blacklist_ua' => $this->csv_list( 'blacklist_ua' ),
				'verify_rdns'  => false,
			)
		);
		$detected = $detector->detect( $ua, $ip );
		$tier     = ( new LoadSampler() )->current_tier();

		$result = array(
			'detected' => null !== $detected,
			'bot'      => $detected,
			'tier'     => $tier,
			'plan'     => null,
		);
		if ( $detected ) {
			$throttler      = new Throttler( $this->category_rules(), $this->delay_bounds() );
			$result['plan'] = $throttler->plan( $detected['category'], $tier['tier'] );
		}
		return $result;
	}

	/**
	 * Last 100 log rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_log(): array {
		return Log::recent( 100 );
	}

	/**
	 * Truncate the log. Returns affected rows.
	 */
	public function clear_log(): int {
		return Log::clear();
	}

	/**
	 * Clear the microcache. Returns files removed.
	 */
	public function clear_cache(): int {
		return ( new Microcache() )->clear();
	}
}
