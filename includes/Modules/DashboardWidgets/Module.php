<?php
/**
 * Dashboard Widgets module - PageSpeed score, recent activity and quick
 * tasks/notes, presented entirely through the module's own SPA screen.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy dashboard-widgets module. The rich views
 * (PageSpeed score, recent activity, quick tasks/notes) live in the SPA under
 * this module's own page; on top of that it manages the REAL wp-admin
 * dashboard by hiding selected (or all) core/plugin dashboard widgets via
 * wp_dashboard_setup.
 *
 * Secrets (Google PageSpeed API key, GA service-account JSON) are never
 * stored in the regular uxstudio_dashboard-widgets settings option; they go
 * through Security::store_secret() in dedicated uxstudio_secret_* options
 * and are never echoed back via REST.
 */
final class Module extends BaseModule {

	private const SECRET_PAGESPEED = 'uxstudio_secret_dashboard_pagespeed_key';
	private const SECRET_GA        = 'uxstudio_secret_dashboard_ga_json';

	private const OPTION_TASKS = 'uxstudio_dashboard_tasks';
	private const OPTION_NOTES = 'uxstudio_dashboard_notes';

	private const CACHE_WIDGETS = 'uxstudio_dashboard_widgets_cache';

	/**
	 * Disk usage widget: the install size is computed by a WP-Cron event
	 * (never while rendering the dashboard) in time-boxed slices that resume
	 * where the previous one stopped. OPTION_DISK holds the last complete
	 * result, OPTION_DISK_SCAN the in-progress walk.
	 */
	public const CRON_DISK_USAGE  = 'uxstudio_dashboard_disk_usage';
	private const OPTION_DISK      = 'uxstudio_disk_usage_data';
	private const OPTION_DISK_SCAN = 'uxstudio_disk_usage_scan';
	private const DISK_MAX_AGE     = 6 * HOUR_IN_SECONDS;
	private const DISK_TIME_BUDGET = 15; // seconds per cron slice.

	private ?Analytics $analytics = null;
	private ?Layout $layout       = null;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Manage the real wp-admin dashboard: snapshot the registered widgets
		// late (so add-ons have registered), then remove the ones the admin
		// hid. Priority ordering matters - cache before we start removing.
		add_action( 'wp_dashboard_setup', array( $this, 'cache_dashboard_widgets' ), 998 );
		add_action( 'wp_dashboard_setup', array( $this, 'apply_hidden_widgets' ), 999 );

		// Priority 1000: after apply_hidden_widgets, so a widget added here is
		// never itself hidden by a stale "hidden_widgets" settings snapshot.
		add_action( 'wp_dashboard_setup', array( $this, 'register_content_widgets' ), 1000 );

		add_action( self::CRON_DISK_USAGE, array( $this, 'compute_disk_usage' ) );

		// GA4 visits widget + dashboard column count / forced layout.
		$this->analytics()->boot();
		( new ExtraWidgets( $this, $this->settings ) )->boot();
		( new ModuleWidgets( $this->settings ) )->boot();
		$this->layout()->boot();
	}

	/**
	 * GA4 widget helper (lazy, shared with the REST controller).
	 */
	public function analytics(): Analytics {
		if ( null === $this->analytics ) {
			$this->analytics = new Analytics( $this->settings );
		}
		return $this->analytics;
	}

	/**
	 * Dashboard layout helper (lazy, shared with the REST controller).
	 */
	public function layout(): Layout {
		if ( null === $this->layout ) {
			$this->layout = new Layout( $this->settings );
		}
		return $this->layout;
	}

	/**
	 * Drop a pending disk-usage computation when the module is switched off.
	 */
	public function on_disable(): void {
		wp_clear_scheduled_hook( self::CRON_DISK_USAGE );
		delete_option( self::OPTION_DISK_SCAN );
	}

	/* ------------------------------------------------------------------ *
	 * Real wp-admin dashboard widget management
	 * ------------------------------------------------------------------ */

	/**
	 * Snapshot the currently registered dashboard meta boxes (id => title) into
	 * a transient so the settings screen can list them even outside the
	 * dashboard request where $wp_meta_boxes isn't populated.
	 */
	public function cache_dashboard_widgets(): void {
		global $wp_meta_boxes;
		if ( ! isset( $wp_meta_boxes['dashboard'] ) || ! is_array( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}

		$normalized = array();
		foreach ( $wp_meta_boxes['dashboard'] as $priorities ) {
			if ( ! is_array( $priorities ) ) {
				continue;
			}
			foreach ( $priorities as $boxes ) {
				if ( ! is_array( $boxes ) ) {
					continue;
				}
				foreach ( $boxes as $box_id => $box ) {
					$title = is_array( $box ) && isset( $box['title'] ) ? sanitize_text_field( wp_strip_all_tags( (string) $box['title'] ) ) : '';
					$normalized[ (string) $box_id ] = $title;
				}
			}
		}
		set_transient( self::CACHE_WIDGETS, $normalized, DAY_IN_SECONDS );
	}

	/**
	 * Remove the dashboard widgets the admin hid (or all of them, plus the
	 * welcome panel, when "disable all" is on).
	 */
	public function apply_hidden_widgets(): void {
		global $wp_meta_boxes;

		if ( (bool) $this->settings->get( 'disable_all_widgets', false ) ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
			$wp_meta_boxes['dashboard'] = array();
			return;
		}

		$hidden = (array) $this->settings->get( 'hidden_widgets', array() );
		if ( empty( $hidden ) ) {
			return;
		}

		if ( in_array( 'dashboard_welcome', $hidden, true ) ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
		}
		if ( ! isset( $wp_meta_boxes['dashboard'] ) || ! is_array( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}

		foreach ( $hidden as $widget_id ) {
			foreach ( array_keys( $wp_meta_boxes['dashboard'] ) as $context ) {
				foreach ( array_keys( (array) $wp_meta_boxes['dashboard'][ $context ] ) as $priority ) {
					unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ][ $widget_id ] );
				}
			}
		}
	}

	/**
	 * Known dashboard widgets as id => title (core defaults merged over the
	 * cached snapshot of whatever is actually registered on this site).
	 *
	 * @return array<string,string>
	 */
	public function available_widgets(): array {
		$defaults = array(
			'dashboard_welcome'     => __( 'Welcome', 'ux-studio' ),
			'dashboard_site_health' => __( 'Site Health Status', 'ux-studio' ),
			'dashboard_right_now'   => __( 'At a Glance', 'ux-studio' ),
			'dashboard_activity'    => __( 'Activity', 'ux-studio' ),
			'dashboard_quick_press' => __( 'Quick Draft', 'ux-studio' ),
			'dashboard_primary'     => __( 'WordPress Events and News', 'ux-studio' ),
		);

		$cached = get_transient( self::CACHE_WIDGETS );
		if ( is_array( $cached ) ) {
			foreach ( $cached as $id => $title ) {
				// UX Studio's own widgets have their own switches in this module.
				if ( 0 === strpos( (string) $id, 'uxstudio_' ) || in_array( (string) $id, ModuleWidgets::ids(), true ) ) {
					continue;
				}
				$defaults[ (string) $id ] = '' !== (string) $title ? (string) $title : (string) $id;
			}
		}
		return $defaults;
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
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		$rows = array(
			array(
				'key'     => 'disable_all_widgets',
				'section' => __( 'Hidden widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide all dashboard widgets', 'ux-studio' ),
				'help'    => __( 'Removes every wp-admin dashboard widget and the welcome panel for a clean dashboard.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hidden_widgets',
				'section' => __( 'Hidden widgets', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => __( 'Hide specific dashboard widgets', 'ux-studio' ),
				'help'    => __( 'The list reflects widgets registered on this site (visit Dashboard once to refresh it).', 'ux-studio' ),
				'options' => $this->available_widgets(),
				'default' => array(),
			),
			array(
				'key'     => 'widget_disk_usage',
				'section' => __( 'Custom widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Dashboard widget: disk usage', 'ux-studio' ),
				'help'    => __( 'Adds a native wp-admin dashboard widget showing the WordPress install size, database size and free disk space (cached for 6 hours).', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'widget_recent_posts',
				'section' => __( 'Custom widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Dashboard widget: recent posts', 'ux-studio' ),
				'help'    => __( 'Adds a native wp-admin dashboard widget listing the 5 most recently published posts.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'widget_recent_media',
				'section' => __( 'Custom widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Dashboard widget: recent media', 'ux-studio' ),
				'help'    => __( 'Adds a native wp-admin dashboard widget with a thumbnail gallery of the most recently uploaded images.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'widget_tasks',
				'section' => __( 'Custom widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Dashboard widget: tasks', 'ux-studio' ),
				'help'    => __( 'A to-do list on the wp-admin dashboard: add, tick off and delete tasks.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'widget_notes',
				'section' => __( 'Custom widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Dashboard widget: notes', 'ux-studio' ),
				'help'    => __( 'A notepad on the wp-admin dashboard, saved automatically while you type.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'widget_pagespeed',
				'section' => __( 'Custom widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Dashboard widget: PageSpeed Insights', 'ux-studio' ),
				'help'    => __( 'Performance score of the home page from Google PageSpeed. Needs the PageSpeed API key.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'widget_user_activity',
				'section' => __( 'Custom widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Dashboard widget: recent user activity', 'ux-studio' ),
				'help'    => __( 'The last 15 actions from the activity log.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'widget_recent_forms',
				'section' => __( 'Custom widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Dashboard widget: Elementor form submissions', 'ux-studio' ),
				'help'    => __( 'The last 10 form submissions. Needs Elementor Pro.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'widget_analytics',
				'section' => __( 'Custom widgets', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Dashboard widget: visits (GA4)', 'ux-studio' ),
				'help'    => __( 'Sessions today / this month / this year plus a 7-day chart from Google Analytics 4. Needs the GA4 property ID and the service-account JSON above.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'dashboard_columns',
				'section' => __( 'Layout', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Dashboard columns', 'ux-studio' ),
				'help'    => __( 'Number of columns on the wp-admin home screen.', 'ux-studio' ),
				'options' => array(
					'0' => __( 'Default (WordPress)', 'ux-studio' ),
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'default' => '0',
			),
			array(
				'key'     => 'force_layout',
				'section' => __( 'Layout', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Force layout for all users', 'ux-studio' ),
				'help'    => __( 'Every user gets the widget arrangement saved with the "Save current layout as default" button below.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'pagespeed_api_key',
				'section' => __( 'API keys', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Google PageSpeed API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'ga_property_id',
				'section' => __( 'API keys', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'GA4 property ID', 'ux-studio' ),
				'help'    => __( 'Numeric ID of the Google Analytics 4 property (e.g. 123456789). The service account must have Viewer access to it.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'ga_service_account_json',
				'section' => __( 'API keys', 'ux-studio' ),
				'type'    => 'textarea',
				'label'   => __( 'Google Analytics service-account JSON', 'ux-studio' ),
				'help'    => __( 'Paste the full service-account JSON. Stored encrypted. Leave blank to keep the current value.', 'ux-studio' ),
				'default' => '',
			),
		);

		// Toggles for the widgets other modules add go right after this
		// module's own widgets, so all widget switches sit together.
		$at = (int) array_search( 'dashboard_columns', array_column( $rows, 'key' ), true );
		array_splice( $rows, $at, 0, ModuleWidgets::schema() );
		return $rows;
	}

	/**
	 * Intercept the two secret fields before they reach the plain settings
	 * option; everything else goes through the normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'pagespeed_api_key', $input ) && '' !== (string) $input['pagespeed_api_key'] ) {
			Security::store_secret( self::SECRET_PAGESPEED, (string) $input['pagespeed_api_key'] );
		}
		unset( $input['pagespeed_api_key'] );

		if ( array_key_exists( 'ga_service_account_json', $input ) && '' !== (string) $input['ga_service_account_json'] ) {
			Security::store_secret( self::SECRET_GA, (string) $input['ga_service_account_json'] );
		}
		unset( $input['ga_service_account_json'] );

		return parent::save_settings( $input );
	}

	/**
	 * Never leak the secrets back to the client; expose only whether they're set.
	 */
	public function settings_values(): array {
		$values                             = parent::settings_values();
		$values['pagespeed_api_key']        = '';
		$values['ga_service_account_json']  = '';
		$values['has_pagespeed_key']        = '' !== Security::get_secret( self::SECRET_PAGESPEED );
		$values['has_ga_key']               = '' !== Security::get_secret( self::SECRET_GA );
		$values['saved_layout']             = $this->layout()->summary();
		return $values;
	}

	/**
	 * Current tasks list.
	 *
	 * @return array<int, array{id:string,text:string,done:bool,created_at:string}>
	 */
	public function get_tasks(): array {
		$tasks = get_option( self::OPTION_TASKS, array() );
		return is_array( $tasks ) ? $tasks : array();
	}

	/**
	 * Current notes text.
	 */
	public function get_notes(): string {
		return (string) get_option( self::OPTION_NOTES, '' );
	}

	/**
	 * Sanitize and persist the full desired task list.
	 *
	 * @param array $tasks Raw task list from the client.
	 * @return array<int, array{id:string,text:string,done:bool,created_at:string}>
	 */
	public function save_tasks( array $tasks ): array {
		$clean = array();

		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}

			$text = isset( $task['text'] ) ? sanitize_text_field( (string) $task['text'] ) : '';
			$text = mb_substr( $text, 0, 500 );
			if ( '' === $text ) {
				continue;
			}

			$id = isset( $task['id'] ) ? sanitize_text_field( (string) $task['id'] ) : '';
			if ( '' === $id ) {
				$id = wp_generate_uuid4();
			}

			$created_at = isset( $task['created_at'] ) ? sanitize_text_field( (string) $task['created_at'] ) : '';
			if ( '' === $created_at ) {
				$created_at = current_time( 'mysql' );
			}

			$clean[] = array(
				'id'         => $id,
				'text'       => $text,
				'done'       => ! empty( $task['done'] ),
				'created_at' => $created_at,
			);
		}

		update_option( self::OPTION_TASKS, $clean, false );

		return $clean;
	}

	/**
	 * Persist the notes text.
	 *
	 * @param string $notes Raw notes text.
	 */
	public function save_notes( string $notes ): string {
		$notes = sanitize_textarea_field( $notes );
		update_option( self::OPTION_NOTES, $notes, false );
		return $notes;
	}

	/**
	 * PageSpeed Insights performance score for a URL, cached for one hour.
	 *
	 * @param string $url URL to test; defaults to the site home URL.
	 * @return array<string, mixed>
	 */
	public function get_pagespeed( string $url = '' ): array {
		$api_key = Security::get_secret( self::SECRET_PAGESPEED );
		if ( '' === $api_key ) {
			return array( 'configured' => false );
		}

		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		$cache_key = 'uxstudio_dashboard_pagespeed_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query(
				array(
					'url'      => $url,
					'key'      => $api_key,
					'category' => 'performance',
				)
			),
			array( 'timeout' => 20 )
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'configured' => true,
				'success'    => false,
				'error'      => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'configured' => true,
				'success'    => false,
				'error'      => sprintf(
					/* translators: %d: HTTP status code */
					__( 'PageSpeed API returned HTTP %d.', 'ux-studio' ),
					$code
				),
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array(
				'configured' => true,
				'success'    => false,
				'error'      => __( 'Unexpected PageSpeed API response.', 'ux-studio' ),
			);
		}

		$score_raw = $body['lighthouseResult']['categories']['performance']['score'] ?? null;
		$score     = is_numeric( $score_raw ) ? (int) round( (float) $score_raw * 100 ) : 0;

		$audits = $body['lighthouseResult']['audits'] ?? array();
		$metrics = array(
			'first_contentful_paint' => $audits['first-contentful-paint']['displayValue'] ?? null,
			'largest_contentful_paint' => $audits['largest-contentful-paint']['displayValue'] ?? null,
		);

		$result = array(
			'configured' => true,
			'success'    => true,
			'score'      => $score,
			'url'        => $url,
			'metrics'    => $metrics,
		);

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Recent rows from the shared activity log table.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_activity( int $limit = 10 ): array {
		global $wpdb;

		$limit = max( 1, min( 50, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT created_at, module, action, object_type, object_id FROM {$wpdb->prefix}uxstudio_activity_log ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/* ------------------------------------------------------------------ *
	 * Generic content widgets (no natural module owner - core WP concepts)
	 * ------------------------------------------------------------------ */

	/**
	 * Register the plain, toggle-gated wp-admin dashboard widgets. Plain
	 * server-rendered HTML (inline styles like BotThrottle's, not the SPA's
	 * `.uxs-*` CSS classes - those aren't loaded on /wp-admin/index.php).
	 */
	public function register_content_widgets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( (bool) $this->settings->get( 'widget_disk_usage', false ) ) {
			wp_add_dashboard_widget(
				'uxstudio_disk_usage',
				__( 'Disk usage', 'ux-studio' ),
				array( $this, 'render_disk_usage_widget' )
			);
		}

		if ( (bool) $this->settings->get( 'widget_recent_posts', false ) ) {
			wp_add_dashboard_widget(
				'uxstudio_recent_posts',
				__( 'Recent posts', 'ux-studio' ),
				array( $this, 'render_recent_posts_widget' )
			);
		}

		if ( (bool) $this->settings->get( 'widget_recent_media', false ) ) {
			wp_add_dashboard_widget(
				'uxstudio_recent_media',
				__( 'Recent media', 'ux-studio' ),
				array( $this, 'render_recent_media_widget' )
			);
		}
	}

	/**
	 * Disk usage widget: WP install size, database size, free/total disk
	 * space. Simplified from legacy (no threshold-alert config system). Shows
	 * the last computed sizes; when they are missing or older than 6 hours a
	 * background recomputation is queued (see compute_disk_usage()).
	 */
	public function render_disk_usage_widget(): void {
		$data  = get_option( self::OPTION_DISK, array() );
		$data  = is_array( $data ) ? $data : array();
		$stale = empty( $data['computed_at'] ) || ( time() - (int) $data['computed_at'] ) > self::DISK_MAX_AGE;

		if ( $stale && ! wp_next_scheduled( self::CRON_DISK_USAGE ) ) {
			wp_schedule_single_event( time(), self::CRON_DISK_USAGE );
		}

		$free  = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$total = @disk_total_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		?>
		<table style="width:100%;border-collapse:collapse;font-size:12px;">
			<tbody>
				<?php if ( empty( $data['computed_at'] ) ) : ?>
					<tr>
						<td colspan="2" style="padding:4px 0;color:#646970;"><?php esc_html_e( 'Computing the WordPress install and database size in the background - check back in a few minutes.', 'ux-studio' ); ?></td>
					</tr>
				<?php else : ?>
					<tr>
						<td style="padding:4px 0;color:#646970;"><?php esc_html_e( 'WordPress install', 'ux-studio' ); ?></td>
						<td style="text-align:right;font-weight:600;"><?php echo esc_html( size_format( (int) ( $data['wp_size'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td style="padding:4px 0;color:#646970;"><?php esc_html_e( 'Database', 'ux-studio' ); ?></td>
						<td style="text-align:right;font-weight:600;"><?php echo esc_html( size_format( (int) ( $data['db_size'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( false !== $free && false !== $total ) : ?>
					<tr>
						<td style="padding:4px 0;color:#646970;"><?php esc_html_e( 'Free disk space', 'ux-studio' ); ?></td>
						<td style="text-align:right;font-weight:600;">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: free space, 2: total space */
									__( '%1$s of %2$s', 'ux-studio' ),
									size_format( (float) $free ),
									size_format( (float) $total )
								)
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $data['computed_at'] ) ) : ?>
					<tr>
						<td colspan="2" style="padding:4px 0 0;color:#8c8f94;font-size:11px;">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human time difference, e.g. "2 hours" */
									__( 'Sizes computed %s ago.', 'ux-studio' ),
									human_time_diff( (int) $data['computed_at'] )
								)
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * WP-Cron callback: walk ABSPATH breadth-first within a time budget. The
	 * queue of not-yet-listed directories and the running total are saved
	 * between slices; an unfinished walk reschedules itself a minute later,
	 * so even a huge install never blocks a single request for long.
	 */
	public function compute_disk_usage(): void {
		$scan = get_option( self::OPTION_DISK_SCAN, array() );
		if ( ! is_array( $scan ) || empty( $scan['queue'] ) ) {
			$scan = array(
				'queue' => array( untrailingslashit( ABSPATH ) ),
				'size'  => 0,
			);
		}

		$deadline = microtime( true ) + self::DISK_TIME_BUDGET;
		$queue    = (array) $scan['queue'];
		$size     = (int) $scan['size'];

		while ( ! empty( $queue ) && microtime( true ) < $deadline ) {
			$dir = (string) array_pop( $queue );
			try {
				foreach ( new \FilesystemIterator( $dir, \FilesystemIterator::SKIP_DOTS ) as $entry ) {
					if ( $entry->isLink() ) {
						continue;
					}
					if ( $entry->isDir() ) {
						$queue[] = $entry->getPathname();
					} elseif ( $entry->isFile() ) {
						$size += (int) $entry->getSize();
					}
				}
			} catch ( \Exception $e ) {
				continue; // Unreadable directory - skipped, best effort.
			}
		}

		if ( ! empty( $queue ) ) {
			update_option(
				self::OPTION_DISK_SCAN,
				array(
					'queue' => array_values( $queue ),
					'size'  => $size,
				),
				false
			);
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_DISK_USAGE );
			return;
		}

		delete_option( self::OPTION_DISK_SCAN );
		update_option(
			self::OPTION_DISK,
			array(
				'wp_size'     => $size,
				'db_size'     => $this->database_size(),
				'computed_at' => time(),
			),
			false
		);
	}

	/**
	 * Total size in bytes of all tables in the WordPress database.
	 */
	private function database_size(): int {
		global $wpdb;
		$size = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s",
				DB_NAME
			)
		);
		return (int) $size;
	}

	/**
	 * Recent posts widget: the 5 most recently published posts.
	 */
	public function render_recent_posts_widget(): void {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 5,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $posts ) ) {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'No posts yet.', 'ux-studio' ) . '</p>';
			return;
		}
		?>
		<table style="width:100%;border-collapse:collapse;font-size:12px;">
			<tbody>
				<?php foreach ( $posts as $post ) : ?>
					<tr>
						<td style="padding:4px 0;">
							<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
						</td>
						<td style="text-align:right;color:#646970;white-space:nowrap;">
							<?php echo esc_html( get_the_author_meta( 'display_name', (int) $post->post_author ) ); ?>
						</td>
						<td style="text-align:right;color:#646970;white-space:nowrap;">
							<?php echo esc_html( get_the_date( get_option( 'date_format' ), $post ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="button button-small"><?php esc_html_e( 'View all posts', 'ux-studio' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Recent media widget: thumbnail gallery of the most recently uploaded images.
	 */
	public function render_recent_media_widget(): void {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => 12,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_status'    => 'inherit',
			)
		);

		if ( empty( $attachments ) ) {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'No media uploaded yet.', 'ux-studio' ) . '</p>';
			return;
		}
		?>
		<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:6px;">
			<?php foreach ( $attachments as $attachment ) : ?>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $attachment->ID ) ); ?>" title="<?php echo esc_attr( $attachment->post_title ); ?>" style="display:block;">
					<?php echo wp_get_attachment_image( $attachment->ID, 'thumbnail', false, array( 'style' => 'width:100%;height:auto;border-radius:3px;display:block;' ) ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<p style="margin-top:10px;">
			<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="button button-small"><?php esc_html_e( 'View media library', 'ux-studio' ); ?></a>
		</p>
		<?php
	}
}
