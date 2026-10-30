<?php
/**
 * Dashboard Widgets - the remaining legacy wp-admin dashboard widgets:
 * tasks, notes, PageSpeed, recent user activity and Elementor Pro form
 * submissions.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy dashboard-widgets module (renderTasksWidget,
 * renderNotesWidget, renderPageSpeedWidget, renderUserActivityWidget,
 * renderRecentFormsWidget). The first port moved tasks/notes/PageSpeed into
 * the module's SPA page only, so they disappeared from the real wp-admin
 * dashboard - these widgets bring them back, each behind its own toggle.
 *
 * Plain server-rendered HTML with inline styles (the SPA's `.uxs-*` CSS is not
 * loaded on /wp-admin/index.php). Interactive widgets (tasks, notes, PageSpeed)
 * talk to the module's existing capability-checked REST routes through
 * wp.apiFetch, which carries the REST nonce automatically.
 */
final class ExtraWidgets {

	private Module $module;
	private Settings $settings;

	/**
	 * @param Module   $module   The dashboard-widgets module (tasks/notes/PageSpeed data).
	 * @param Settings $settings Its settings.
	 */
	public function __construct( Module $module, Settings $settings ) {
		$this->module   = $module;
		$this->settings = $settings;
	}

	/**
	 * Hook the widgets.
	 */
	public function boot(): void {
		// Same priority as the other content widgets: after apply_hidden_widgets.
		add_action( 'wp_dashboard_setup', array( $this, 'register' ), 1000 );
	}

	/**
	 * Register the enabled widgets (admins only, like every other module widget).
	 */
	public function register(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$scripts = false;

		if ( $this->on( 'widget_tasks' ) ) {
			wp_add_dashboard_widget( 'uxstudio_tasks', __( 'Tasks', 'ux-studio' ), array( $this, 'render_tasks' ) );
			$scripts = true;
		}
		if ( $this->on( 'widget_notes' ) ) {
			wp_add_dashboard_widget( 'uxstudio_notes', __( 'Notes', 'ux-studio' ), array( $this, 'render_notes' ) );
			$scripts = true;
		}
		if ( $this->on( 'widget_pagespeed' ) ) {
			wp_add_dashboard_widget( 'uxstudio_pagespeed', __( 'PageSpeed Insights', 'ux-studio' ), array( $this, 'render_pagespeed' ) );
			$scripts = true;
		}
		if ( $this->on( 'widget_user_activity' ) ) {
			wp_add_dashboard_widget( 'uxstudio_user_activity', __( 'Recent user activity', 'ux-studio' ), array( $this, 'render_user_activity' ) );
		}
		if ( $this->on( 'widget_recent_forms' ) && defined( 'ELEMENTOR_PRO_VERSION' ) && $this->table_exists( 'e_submissions' ) ) {
			wp_add_dashboard_widget( 'uxstudio_recent_forms', __( 'Recent form submissions (Elementor)', 'ux-studio' ), array( $this, 'render_recent_forms' ) );
		}

		if ( $scripts ) {
			wp_enqueue_script( 'wp-api-fetch' );
			// After the footer scripts (wp-api-fetch) are printed - admin_footer runs before them.
			add_action( 'admin_print_footer_scripts', array( $this, 'print_script' ), 100 );
		}
	}

	/**
	 * Whether a widget toggle is on.
	 *
	 * @param string $key Settings key.
	 */
	private function on( string $key ): bool {
		return (bool) $this->settings->get( $key, false );
	}

	/**
	 * Whether a table (without prefix) exists.
	 *
	 * @param string $name Table name without the WP prefix.
	 */
	private function table_exists( string $name ): bool {
		global $wpdb;
		$table = $wpdb->prefix . $name;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cheap existence check on dashboard render.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Tasks: open first, then done; newest first. Add / tick / delete in place.
	 */
	public function render_tasks(): void {
		?>
		<div id="uxs-dw-tasks" style="font-size:13px;">
			<ul class="uxs-dw-tasks__list" style="margin:0 0 10px;"></ul>
			<div style="display:flex;gap:6px;">
				<input type="text" class="uxs-dw-tasks__input" maxlength="500" placeholder="<?php esc_attr_e( 'New task…', 'ux-studio' ); ?>" style="flex:1;">
				<button type="button" class="button uxs-dw-tasks__add"><?php esc_html_e( 'Add', 'ux-studio' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Notes: one textarea, saved automatically a moment after typing stops.
	 */
	public function render_notes(): void {
		?>
		<div id="uxs-dw-notes">
			<textarea class="uxs-dw-notes__text" rows="8" style="width:100%;" placeholder="<?php esc_attr_e( 'Write your notes…', 'ux-studio' ); ?>"><?php echo esc_textarea( $this->module->get_notes() ); ?></textarea>
			<p class="uxs-dw-notes__status" style="margin:4px 0 0;color:#646970;font-size:12px;min-height:16px;"></p>
		</div>
		<?php
	}

	/**
	 * PageSpeed: the cached score (never calls Google while the dashboard
	 * renders - a missing result is fetched by the "Measure" button).
	 */
	public function render_pagespeed(): void {
		$cached = get_transient( 'uxstudio_dashboard_pagespeed_' . md5( home_url( '/' ) ) );
		$module = admin_url( 'admin.php?page=ux-studio#/module?id=dashboard-widgets' );
		?>
		<div id="uxs-dw-pagespeed" style="font-size:13px;">
			<div class="uxs-dw-pagespeed__body"><?php echo is_array( $cached ) ? self::pagespeed_html( $cached ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in pagespeed_html(). ?></div>
			<p style="margin:10px 0 0;display:flex;gap:8px;align-items:center;">
				<button type="button" class="button uxs-dw-pagespeed__run"><?php echo is_array( $cached ) ? esc_html__( 'Measure again', 'ux-studio' ) : esc_html__( 'Measure', 'ux-studio' ); ?></button>
				<a href="<?php echo esc_url( $module ); ?>"><?php esc_html_e( 'Settings', 'ux-studio' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Score markup for a get_pagespeed() result.
	 *
	 * @param array<string, mixed> $data Result.
	 */
	public static function pagespeed_html( array $data ): string {
		if ( empty( $data['configured'] ) ) {
			return '<p style="color:#646970;">' . esc_html__( 'Add a Google PageSpeed API key in the Dashboard widgets module settings.', 'ux-studio' ) . '</p>';
		}
		if ( empty( $data['success'] ) ) {
			return '<p style="color:#d63638;">' . esc_html( (string) ( $data['error'] ?? __( 'Measurement failed.', 'ux-studio' ) ) ) . '</p>';
		}
		$score = (int) ( $data['score'] ?? 0 );
		$color = $score >= 90 ? '#16a34a' : ( $score >= 50 ? '#d97706' : '#d63638' );
		$html  = '<div style="display:flex;align-items:center;gap:14px;">';
		$html .= '<span style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:50%;border:6px solid ' . esc_attr( $color ) . ';font-size:20px;font-weight:700;color:' . esc_attr( $color ) . ';">' . $score . '</span>';
		$html .= '<div><strong>' . esc_html__( 'Performance (mobile)', 'ux-studio' ) . '</strong>';
		foreach ( array(
			'first_contentful_paint'   => __( 'First Contentful Paint', 'ux-studio' ),
			'largest_contentful_paint' => __( 'Largest Contentful Paint', 'ux-studio' ),
		) as $key => $label ) {
			if ( ! empty( $data['metrics'][ $key ] ) ) {
				$html .= '<br><span style="color:#646970;">' . esc_html( $label ) . ': ' . esc_html( (string) $data['metrics'][ $key ] ) . '</span>';
			}
		}
		$html .= '</div></div>';
		return $html;
	}

	/**
	 * Recent user activity: the last 15 activity-log rows.
	 */
	public function render_user_activity(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small read of the module's own log table.
		$rows = $wpdb->get_results( "SELECT created_at, user_id, module, action, object_type, object_id FROM {$wpdb->prefix}uxstudio_activity_log ORDER BY id DESC LIMIT 15", ARRAY_A );
		$all  = admin_url( 'admin.php?page=ux-studio#/module?id=activity-log' );

		if ( empty( $rows ) ) {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'No activity yet.', 'ux-studio' ) . '</p>';
			return;
		}
		?>
		<table style="width:100%;border-collapse:collapse;font-size:12px;">
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$user   = (int) $row['user_id'] ? get_the_author_meta( 'display_name', (int) $row['user_id'] ) : '';
					$object = '';
					if ( (int) $row['object_id'] && in_array( $row['object_type'], array( 'post', 'page', 'attachment' ), true ) ) {
						$object = get_the_title( (int) $row['object_id'] );
					}
					$when = strtotime( (string) $row['created_at'] . ' UTC' );
					?>
					<tr>
						<td style="padding:3px 6px 3px 0;white-space:nowrap;"><?php echo esc_html( '' !== $user ? $user : '—' ); ?></td>
						<td style="padding:3px 6px 3px 0;"><span><?php echo esc_html( \UxStudio\Modules\ActivityLog\Labels::action( (string) $row['action'] ) ); ?></span></td>
						<td style="padding:3px 6px 3px 0;"><?php echo esc_html( mb_strimwidth( (string) $object, 0, 40, '…' ) ); ?></td>
						<td style="text-align:right;color:#646970;white-space:nowrap;">
							<?php
							/* translators: %s: human-readable time difference, e.g. "5 minutes". */
							echo esc_html( $when ? sprintf( __( '%s ago', 'ux-studio' ), human_time_diff( $when, time() ) ) : '' );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-top:10px;"><a href="<?php echo esc_url( $all ); ?>" class="button button-small"><?php esc_html_e( 'Open activity log', 'ux-studio' ); ?></a></p>
		<?php
	}

	/**
	 * Elementor Pro form submissions: the last 10 (not trashed).
	 */
	public function render_recent_forms(): void {
		global $wpdb;
		$s = $wpdb->prefix . 'e_submissions';
		$v = $wpdb->prefix . 'e_submissions_values';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed Elementor table names.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.form_name, s.created_at, s.is_read,
					(SELECT sv.value FROM {$v} sv WHERE sv.submission_id = s.id AND sv.`key` = 'email' LIMIT 1) AS email,
					(SELECT sv.value FROM {$v} sv WHERE sv.submission_id = s.id AND sv.`key` = 'name' LIMIT 1) AS sender_name
				FROM {$s} s WHERE s.status != %s ORDER BY s.created_at DESC LIMIT 10",
				'trash'
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'No form submissions yet.', 'ux-studio' ) . '</p>';
			return;
		}
		?>
		<table style="width:100%;border-collapse:collapse;font-size:12px;">
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td style="padding:3px 6px 3px 0;<?php echo $row['is_read'] ? '' : 'font-weight:600;'; ?>"><?php echo esc_html( (string) $row['form_name'] ); ?></td>
						<td style="padding:3px 6px 3px 0;color:#646970;"><?php echo esc_html( (string) ( $row['sender_name'] ?: ( $row['email'] ?: '—' ) ) ); ?></td>
						<td style="text-align:right;color:#646970;white-space:nowrap;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $row['created_at'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-top:10px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=e-form-submissions' ) ); ?>" class="button button-small"><?php esc_html_e( 'View all submissions', 'ux-studio' ); ?></a></p>
		<?php
	}

	/**
	 * Behaviour for the tasks / notes / PageSpeed widgets.
	 */
	public function print_script(): void {
		$i18n = array(
			'empty'    => __( 'No tasks.', 'ux-studio' ),
			'delete'   => __( 'Delete', 'ux-studio' ),
			'saving'   => __( 'Saving…', 'ux-studio' ),
			'saved'    => __( 'Saved', 'ux-studio' ),
			'failed'   => __( 'Saving failed.', 'ux-studio' ),
			'measuring' => __( 'Measuring… this can take up to 30 seconds.', 'ux-studio' ),
		);
		?>
		<script>
		( function () {
			if ( ! window.wp || ! wp.apiFetch ) { return; }
			var t = <?php echo wp_json_encode( $i18n ); ?>;
			var base = '/uxstudio/v1/dashboard-widgets/';

			// Tasks.
			var tasksBox = document.getElementById( 'uxs-dw-tasks' );
			if ( tasksBox ) {
				var list = tasksBox.querySelector( '.uxs-dw-tasks__list' );
				var input = tasksBox.querySelector( '.uxs-dw-tasks__input' );
				var tasks = <?php echo wp_json_encode( array_values( $this->module->get_tasks() ) ); ?>;
				var render = function () {
					list.innerHTML = '';
					var sorted = tasks.slice().sort( function ( a, b ) {
						if ( !! a.done !== !! b.done ) { return a.done ? 1 : -1; }
						return String( b.created_at ).localeCompare( String( a.created_at ) );
					} );
					if ( ! sorted.length ) {
						var e = document.createElement( 'li' );
						e.style.color = '#646970';
						e.textContent = t.empty;
						list.appendChild( e );
					}
					sorted.forEach( function ( task ) {
						var li = document.createElement( 'li' );
						li.style.cssText = 'display:flex;align-items:center;gap:6px;margin:0 0 4px;';
						var cb = document.createElement( 'input' );
						cb.type = 'checkbox';
						cb.checked = !! task.done;
						cb.addEventListener( 'change', function () { task.done = cb.checked; save(); } );
						var span = document.createElement( 'span' );
						span.textContent = task.text;
						span.style.cssText = 'flex:1;' + ( task.done ? 'text-decoration:line-through;color:#8c8f94;' : '' );
						var del = document.createElement( 'button' );
						del.type = 'button';
						del.className = 'button-link';
						del.textContent = '×';
						del.title = t['delete'];
						del.style.cssText = 'color:#d63638;font-size:16px;text-decoration:none;';
						del.addEventListener( 'click', function () {
							tasks = tasks.filter( function ( x ) { return x !== task; } );
							save();
						} );
						li.appendChild( cb );
						li.appendChild( span );
						li.appendChild( del );
						list.appendChild( li );
					} );
				};
				var save = function () {
					render();
					wp.apiFetch( { path: base + 'tasks', method: 'POST', data: { tasks: tasks } } ).then( function ( r ) {
						if ( r && r.data && r.data.tasks ) { tasks = r.data.tasks; render(); }
					} );
				};
				var add = function () {
					var text = input.value.trim();
					if ( ! text ) { return; }
					tasks.push( { text: text, done: false } );
					input.value = '';
					save();
				};
				tasksBox.querySelector( '.uxs-dw-tasks__add' ).addEventListener( 'click', add );
				input.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); add(); } } );
				render();
			}

			// Notes (autosave 1 s after typing stops).
			var notesBox = document.getElementById( 'uxs-dw-notes' );
			if ( notesBox ) {
				var area = notesBox.querySelector( '.uxs-dw-notes__text' );
				var status = notesBox.querySelector( '.uxs-dw-notes__status' );
				var timer = null;
				area.addEventListener( 'input', function () {
					clearTimeout( timer );
					status.textContent = t.saving;
					timer = setTimeout( function () {
						wp.apiFetch( { path: base + 'tasks', method: 'POST', data: { notes: area.value } } )
							.then( function () { status.textContent = t.saved; } )
							.catch( function () { status.textContent = t.failed; } );
					}, 1000 );
				} );
			}

			// PageSpeed.
			var psBox = document.getElementById( 'uxs-dw-pagespeed' );
			if ( psBox ) {
				var btn = psBox.querySelector( '.uxs-dw-pagespeed__run' );
				var body = psBox.querySelector( '.uxs-dw-pagespeed__body' );
				btn.addEventListener( 'click', function () {
					btn.disabled = true;
					body.innerHTML = '<p style="color:#646970;">' + t.measuring + '</p>';
					wp.apiFetch( { path: base + 'pagespeed/widget', method: 'POST' } )
						.then( function ( r ) { body.innerHTML = r && r.data ? r.data.html : ''; } )
						.catch( function ( e ) { body.textContent = e && e.message ? e.message : t.failed; } )
						.then( function () { btn.disabled = false; } );
				} );
			}
		} )();
		</script>
		<?php
	}
}
