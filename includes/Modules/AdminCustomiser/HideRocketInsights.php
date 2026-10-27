<?php
/**
 * Hides "Rocket Insights" of the WP Rocket plugin in wp-admin (setting `hide_rocket_insights`).
 *
 * How (researched in the WP Rocket 3.23.5.1 source, inc/Engine/Admin/RocketInsights/):
 *
 * - WP Rocket asks ONE official filter, `rocket_rocket_insights_enabled` (Context::is_allowed(), SaasContext::is_allowed()),
 *   before it shows or runs anything of Rocket Insights: the "Rocket Insights" tab and its content on the settings page,
 *   the score widget in the sidebar and on the dashboard, the recommendations widget, the column in the post lists, the
 *   activation notice, the "settings saved" hint, the REST routes and the scheduling of tests (see the cancelling of
 *   the scheduled jobs in Subscriber::maybe_add_homepage_automatically()). Returning false from it is the clean way,
 *   so no CSS or JS hiding of WP Rocket markup is needed and nothing breaks when WP Rocket renames a class.
 * - As a second line of defence for versions that would ignore the filter in the post lists, the column key
 *   `rocket_insights` is removed from the list table columns of every post type at the last priority.
 *
 * Nothing of WP Rocket is edited or deactivated and none of its options is written: switching the setting off
 * brings the feature back in full.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

defined( 'ABSPATH' ) || exit;

final class HideRocketInsights {

	/**
	 * Official WP Rocket filter (boolean) that enables or disables Rocket Insights as a whole.
	 * Source: wp-rocket inc/Engine/Admin/RocketInsights/Context/Context.php, WP Rocket 3.23.5.1.
	 */
	public const FILTER = 'rocket_rocket_insights_enabled';

	/**
	 * Key of the Rocket Insights column in the post list tables.
	 * Source: wp-rocket inc/Engine/Admin/RocketInsights/PostListing/Subscriber.php, WP Rocket 3.23.5.1.
	 */
	public const COLUMN = 'rocket_insights';

	/**
	 * Hook everything. Called only when the setting is on.
	 */
	public static function register(): void {
		// After WP Rocket's own callback (priority 10, it only blocks resellers and non-live sites).
		add_filter( self::FILTER, '__return_false', 99 );
		add_action( 'current_screen', array( self::class, 'on_current_screen' ) );
	}

	/**
	 * On the post list screens, strip the column once WP Rocket (or anybody) has added it.
	 *
	 * @param \WP_Screen|null $screen Current admin screen.
	 */
	public static function on_current_screen( $screen ): void {
		if ( ! $screen instanceof \WP_Screen || 'edit' !== $screen->base || '' === (string) $screen->post_type ) {
			return;
		}
		$post_type = (string) $screen->post_type;
		add_filter( "manage_{$post_type}_posts_columns", array( self::class, 'strip_column' ), PHP_INT_MAX );
		add_filter( "manage_edit-{$post_type}_sortable_columns", array( self::class, 'strip_column' ), PHP_INT_MAX );
	}

	/**
	 * Remove the Rocket Insights column, keep every other one in order.
	 *
	 * @param mixed $columns Column key => label (or sortable definition).
	 * @return mixed
	 */
	public static function strip_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		unset( $columns[ self::COLUMN ] );
		return $columns;
	}
}
