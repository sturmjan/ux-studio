<?php
/**
 * User Last Login module - track and display each user's last login.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\UserLastLogin;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Stores a login timestamp on wp_login and renders it as a Users list column,
 * formatted per the module settings (respecting the site locale via
 * date_i18n / wp_date). Ported from the legacy module: writes the new
 * `uxstudio_user_last_login` meta and reads the legacy
 * `wpext_user_last_login_status` as a fallback.
 */
final class Module extends BaseModule {

	/**
	 * Current meta key (written on login).
	 */
	private const META_KEY = 'uxstudio_user_last_login';

	/**
	 * Legacy meta key, read as a fallback. Legacy module.
	 */
	private const LEGACY_META_KEY = 'wpext_user_last_login_status';

	/**
	 * Users list column id.
	 */
	private const COLUMN = 'uxstudio_last_login';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_login', array( $this, 'record_login' ), 10, 1 );
		add_filter( 'manage_users_columns', array( $this, 'register_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
	}

	/**
	 * Store the login timestamp for the user.
	 *
	 * @param string $user_login Username.
	 */
	public function record_login( string $user_login ): void {
		$user = get_user_by( 'login', $user_login );
		if ( ! $user ) {
			return;
		}

		update_user_meta( $user->ID, self::META_KEY, time() );
	}

	/**
	 * Add the "Last Login" column to the Users table.
	 *
	 * @param array $columns Column headers.
	 * @return array
	 */
	public function register_column( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'Last Login', 'ux-studio' );
		return $columns;
	}

	/**
	 * Render the column value for a user.
	 *
	 * @param string $output      Existing output.
	 * @param string $column_name Column id.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public function render_column( string $output, string $column_name, int $user_id ): string {
		if ( self::COLUMN !== $column_name ) {
			return $output;
		}

		$login_time = $this->get_login_time( $user_id );
		if ( ! $login_time ) {
			return esc_html__( 'Never', 'ux-studio' );
		}

		return esc_html( $this->format_login_time( $login_time ) );
	}

	/**
	 * Read the stored login timestamp (new key, legacy fallback).
	 *
	 * @param int $user_id User ID.
	 */
	private function get_login_time( int $user_id ): int {
		$value = get_user_meta( $user_id, self::META_KEY, true );

		if ( '' === $value || null === $value ) {
			$value = get_user_meta( $user_id, self::LEGACY_META_KEY, true );
		}

		return (int) $value;
	}

	/**
	 * Format the timestamp per the selected format setting.
	 *
	 * @param int $login_time Unix timestamp.
	 */
	private function format_login_time( int $login_time ): string {
		$format = (string) $this->settings->get( 'format', 'date_time' );

		switch ( $format ) {
			case 'date':
				return wp_date( (string) get_option( 'date_format' ), $login_time );

			case 'relative':
				return $this->format_relative( $login_time );

			case 'custom':
				$custom = (string) $this->settings->get( 'custom_format', '' );
				if ( '' !== $custom ) {
					$formatted = wp_date( $custom, $login_time );
					if ( false !== $formatted ) {
						return $formatted;
					}
				}
				return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $login_time );

			case 'date_time':
			default:
				return sprintf(
					'%s %s',
					wp_date( (string) get_option( 'date_format' ), $login_time ),
					wp_date( (string) get_option( 'time_format' ), $login_time )
				);
		}
	}

	/**
	 * Human-readable relative time (e.g. "3 days ago").
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function format_relative( int $timestamp ): string {
		$now  = time();
		$diff = $now - $timestamp;

		if ( abs( $diff ) < MINUTE_IN_SECONDS ) {
			return __( 'Just now', 'ux-studio' );
		}

		$human = human_time_diff( $timestamp, $now );

		return $diff >= 0
			/* translators: %s: human-readable time difference, e.g. "3 days" */
			? sprintf( __( '%s ago', 'ux-studio' ), $human )
			/* translators: %s: human-readable time difference, e.g. "3 days" */
			: sprintf( __( 'in %s', 'ux-studio' ), $human );
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'format',
				'type'    => 'select',
				'label'   => __( 'Format', 'ux-studio' ),
				'help'    => __( 'How the last login time is displayed in the Users column.', 'ux-studio' ),
				'options' => array(
					'date_time' => __( 'Date and time', 'ux-studio' ),
					'date'      => __( 'Date only', 'ux-studio' ),
					'relative'  => __( 'Relative time', 'ux-studio' ),
					'custom'    => __( 'Custom format', 'ux-studio' ),
				),
				'default' => 'date_time',
			),
			array(
				'key'     => 'custom_format',
				'type'    => 'text',
				'label'   => __( 'Custom format', 'ux-studio' ),
				'help'    => __( 'PHP date format used when "Custom format" is selected (e.g. Y-m-d H:i:s).', 'ux-studio' ),
				'default' => 'Y-m-d H:i:s',
			),
		);
	}
}
