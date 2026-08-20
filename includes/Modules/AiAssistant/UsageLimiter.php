<?php
/**
 * Enforces monthly/daily AI usage limits configured in module settings.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class UsageLimiter {

	private static ?Settings $settings = null;

	private static function settings(): Settings {
		if ( null === self::$settings ) {
			self::$settings = new Settings( 'uxstudio_ai_assistant' );
		}
		return self::$settings;
	}

	/**
	 * Checks all limits and returns an error message when one is exceeded,
	 * or null when the request may proceed.
	 */
	public static function check( ?int $user_id = null ): ?string {
		$user_id = $user_id ?? get_current_user_id();

		// 1. Monthly token limit.
		$monthly_limit = (int) self::settings()->get( 'monthly_token_limit', 0 );
		if ( $monthly_limit > 0 ) {
			$used = self::get_monthly_tokens();
			if ( $used >= $monthly_limit ) {
				return sprintf(
					/* translators: 1: tokens used, 2: monthly token limit */
					__( 'The monthly token limit has been reached (%1$s / %2$s). It resets on the 1st of next month.', 'ux-studio' ),
					number_format_i18n( $used ),
					number_format_i18n( $monthly_limit )
				);
			}
		}

		// 2. Global daily request limit.
		$daily_limit = (int) self::settings()->get( 'daily_request_limit', 0 );
		if ( $daily_limit > 0 ) {
			$today_requests = self::get_daily_requests();
			if ( $today_requests >= $daily_limit ) {
				return sprintf(
					/* translators: 1: requests made today, 2: daily request limit */
					__( 'The daily request limit has been reached (%1$d / %2$d). Please try again tomorrow.', 'ux-studio' ),
					$today_requests,
					$daily_limit
				);
			}
		}

		// 3. Per-user daily request limit.
		$user_daily_limit = (int) self::settings()->get( 'user_daily_request_limit', 0 );
		if ( $user_daily_limit > 0 ) {
			$user_today_requests = self::get_daily_requests( $user_id );
			if ( $user_today_requests >= $user_daily_limit ) {
				return sprintf(
					/* translators: 1: requests made today by this user, 2: per-user daily request limit */
					__( 'Your daily request limit has been reached (%1$d / %2$d). Please try again tomorrow.', 'ux-studio' ),
					$user_today_requests,
					$user_daily_limit
				);
			}
		}

		return null;
	}

	/**
	 * Current usage vs. configured limits, for display in the admin UI.
	 *
	 * @return array<string, array{used:int,limit:int,percent:int,remaining:int}>
	 */
	public static function get_status(): array {
		$monthly_limit    = (int) self::settings()->get( 'monthly_token_limit', 0 );
		$daily_limit      = (int) self::settings()->get( 'daily_request_limit', 0 );
		$user_daily_limit = (int) self::settings()->get( 'user_daily_request_limit', 0 );

		$status = array();

		if ( $monthly_limit > 0 ) {
			$used                       = self::get_monthly_tokens();
			$status['monthly_tokens'] = array(
				'used'      => $used,
				'limit'     => $monthly_limit,
				'percent'   => min( 100, (int) round( ( $used / $monthly_limit ) * 100 ) ),
				'remaining' => max( 0, $monthly_limit - $used ),
			);
		}

		if ( $daily_limit > 0 ) {
			$used                      = self::get_daily_requests();
			$status['daily_requests'] = array(
				'used'      => $used,
				'limit'     => $daily_limit,
				'percent'   => min( 100, (int) round( ( $used / $daily_limit ) * 100 ) ),
				'remaining' => max( 0, $daily_limit - $used ),
			);
		}

		if ( $user_daily_limit > 0 ) {
			$used                           = self::get_daily_requests( get_current_user_id() );
			$status['user_daily_requests'] = array(
				'used'      => $used,
				'limit'     => $user_daily_limit,
				'percent'   => min( 100, (int) round( ( $used / $user_daily_limit ) * 100 ) ),
				'remaining' => max( 0, $user_daily_limit - $used ),
			);
		}

		return $status;
	}

	/**
	 * Total tokens (input + output) spent in the current calendar month.
	 */
	private static function get_monthly_tokens(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_usage';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return 0;
		}

		$first_of_month = gmdate( 'Y-m-01 00:00:00' );

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(input_tokens + output_tokens), 0) FROM {$table} WHERE created_at >= %s",
				$first_of_month
			)
		);

		return (int) $result;
	}

	/**
	 * Number of requests made today, optionally scoped to one user.
	 */
	private static function get_daily_requests( ?int $user_id = null ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_usage';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return 0;
		}

		$today = gmdate( 'Y-m-d 00:00:00' );

		if ( $user_id ) {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND user_id = %d",
					$today,
					$user_id
				)
			);
		} else {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
					$today
				)
			);
		}

		return (int) $result;
	}
}
