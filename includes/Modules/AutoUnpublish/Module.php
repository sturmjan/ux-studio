<?php
/**
 * Auto Unpublish module - schedule posts to revert to draft (ported from legacy module).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AutoUnpublish;

use UxStudio\Modules\BaseModule;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an "unpublish at" date to the classic publish box, schedules a single
 * cron event that switches the post back to draft, with an hourly fallback
 * sweep for missed cron events.
 */
final class Module extends BaseModule {

	private const META_KEY     = '_uxstudio_auto_unpublish';
	private const CRON_HOOK    = 'ux_studio/auto_unpublish_post';
	private const NONCE_ACTION = 'uxstudio_auto_unpublish_save';
	private const NONCE_FIELD  = 'uxstudio_auto_unpublish_nonce';
	private const TRANSIENT    = 'uxstudio_auto_unpublish_checked';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		// Cron callback (always - runs even on frontend cron).
		add_action( self::CRON_HOOK, array( $this, 'unpublish_post' ) );

		// Fallback check for missed cron events.
		add_action( 'wp_loaded', array( $this, 'fallback_check' ) );

		if ( ! is_admin() ) {
			return;
		}

		// Publish box field (classic editor).
		add_action( 'post_submitbox_misc_actions', array( $this, 'render_publish_box' ) );

		// Save post meta + schedule cron.
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );

		// Post state indicator in posts list.
		add_filter( 'display_post_states', array( $this, 'add_post_state' ), 10, 2 );
	}

	/**
	 * Render the unpublish date field in the publish box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_publish_box( WP_Post $post ): void {
		$value       = (string) get_post_meta( $post->ID, self::META_KEY, true );
		$input_value = $value ? gmdate( 'Y-m-d\TH:i', (int) strtotime( $value ) ) : '';

		wp_nonce_field( self::NONCE_ACTION . '_' . $post->ID, self::NONCE_FIELD );
		?>
		<div class="misc-pub-section" id="uxstudio-auto-unpublish-section">
			<label for="uxstudio-auto-unpublish-input">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<?php esc_html_e( 'Unpublish on:', 'ux-studio' ); ?>
			</label>
			<input type="datetime-local"
				id="uxstudio-auto-unpublish-input"
				name="uxstudio_auto_unpublish_date"
				value="<?php echo esc_attr( $input_value ); ?>"
				style="max-width:100%;">
			<p class="description"><?php esc_html_e( 'Leave empty to keep the post published indefinitely.', 'ux-studio' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Persist the unpublish date and (re)schedule the cron event.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_post( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION . '_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Always unschedule the existing event first.
		$this->unschedule_event( $post_id );

		$raw = isset( $_POST['uxstudio_auto_unpublish_date'] ) ? sanitize_text_field( wp_unslash( $_POST['uxstudio_auto_unpublish_date'] ) ) : '';

		if ( '' === $raw ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		$timestamp = strtotime( $raw );
		if ( ! $timestamp ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		$mysql_date = gmdate( 'Y-m-d H:i:s', $timestamp );
		update_post_meta( $post_id, self::META_KEY, $mysql_date );

		// Schedule the cron event only for future dates on published posts.
		if ( $timestamp > current_time( 'timestamp' ) && 'publish' === $post->post_status ) {
			$utc_timestamp = $timestamp - (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			wp_schedule_single_event( $utc_timestamp, self::CRON_HOOK, array( $post_id ) );
		}
	}

	/**
	 * Cron callback: switch the post back to draft.
	 *
	 * @param int $post_id Post ID.
	 */
	public function unpublish_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		$date = (string) get_post_meta( $post_id, self::META_KEY, true );
		if ( ! $date ) {
			return;
		}

		$timestamp = strtotime( $date );
		if ( $timestamp && $timestamp > current_time( 'timestamp' ) ) {
			return;
		}

		remove_action( 'save_post', array( $this, 'save_post' ), 10 );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );

		delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Hourly fallback sweep for expired posts missed by cron.
	 */
	public function fallback_check(): void {
		if ( get_transient( self::TRANSIENT ) ) {
			return;
		}

		set_transient( self::TRANSIENT, 1, HOUR_IN_SECONDS );

		global $wpdb;

		$now = current_time( 'mysql' );

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value <= %s
				   AND p.post_status = 'publish'
				 LIMIT 50",
				self::META_KEY,
				$now
			)
		);

		foreach ( (array) $post_ids as $post_id ) {
			$this->unpublish_post( (int) $post_id );
		}
	}

	/**
	 * Show a scheduled-unpublish indicator in the posts list.
	 *
	 * @param array   $states Current post states.
	 * @param WP_Post $post   Post object.
	 */
	public function add_post_state( array $states, WP_Post $post ): array {
		$date = (string) get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! $date ) {
			return $states;
		}

		$timestamp = strtotime( $date );
		if ( $timestamp && $timestamp > current_time( 'timestamp' ) ) {
			$states['uxstudio_auto_unpublish'] = sprintf(
				/* translators: %s: formatted date and time. */
				__( 'Unpublish: %s', 'ux-studio' ),
				$this->format_date( $date )
			);
		}

		return $states;
	}

	/**
	 * Format a stored MySQL date using the site date/time format.
	 *
	 * @param string $date MySQL date string.
	 */
	private function format_date( string $date ): string {
		$timestamp = strtotime( $date );
		if ( ! $timestamp ) {
			return $date;
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Remove a pending cron event for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function unschedule_event( int $post_id ): void {
		$next = wp_next_scheduled( self::CRON_HOOK, array( $post_id ) );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK, array( $post_id ) );
		}
	}
}
