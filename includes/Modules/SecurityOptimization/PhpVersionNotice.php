<?php
/**
 * PHP version check: dashboard widget and admin notice.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Shown to administrators whenever the PHP version is not ok. Registered by the plugin core
 * (Core\PhpVersion\Bootstrap), so it works on a site that has not enabled any module.
 *
 *  - Dashboard widget, top of the main column, red (critical) or yellow (warning), impossible to hide (same
 *    pattern as CspDashboardWidget).
 *  - Admin notice on every admin screen, also on the UX Studio page. While critical it has no close button; the
 *    only way to silence it is "verified with hosting, hide for 30 days", which is stored together with the PHP
 *    version, so a new version shows it again. The warning level (end of support soon) can also be hidden per user.
 *  - Admin bar node (front end and admin) for administrators, red or yellow, hidden while snoozed.
 *
 * Nothing is ever printed for visitors or users without `manage_options`.
 */
final class PhpVersionNotice {

	public const WIDGET_ID = 'uxstudio_php_version_widget';

	public const ACTION = 'uxstudio_php_version_snooze';

	public const DISMISS_ACTION = 'uxstudio_php_version_dismiss';

	/** User meta: `branch|eol` of the warning this user hid. */
	public const DISMISS_META = 'uxstudio_php_version_dismissed';

	public const BAR_NODE = 'uxstudio-php-version';

	public const SNOOZE_DAYS = 30;

	/**
	 * Marker of one warning, so a hidden warning comes back when the end-of-support date changes.
	 *
	 * @param array $r Result of evaluate().
	 */
	public static function dismiss_key( array $r ): string {
		return (string) $r['branch'] . '|' . (string) $r['eol'];
	}

	/**
	 * Where to show what. Pure (no WordPress calls), so the tests can drive every case.
	 *
	 *  - Nothing for anybody without `manage_options`, and nothing when the level is ok.
	 *  - A snooze (same PHP version, not expired) hides the notice AND the admin bar node.
	 *  - The notice exists in the admin only. Critical: not dismissible. Warning: dismissible, and a user who
	 *    dismissed this very warning does not get the notice again (the admin bar node stays).
	 *
	 * @param array  $r         Result of evaluate().
	 * @param bool   $can       Whether the user has `manage_options`.
	 * @param mixed  $snooze    Stored snooze option.
	 * @param string $dismissed User meta value (see dismiss_key()).
	 * @param string $version   PHP_VERSION.
	 * @param int    $now       Unix time.
	 * @param string $context   `admin` or `front`.
	 * @return array{notice:bool,bar:bool,dismissible:bool}
	 */
	public static function visibility( array $r, bool $can, $snooze, string $dismissed, string $version, int $now, string $context ): array {
		if ( ! $can || 'ok' === $r['level'] || PhpVersionCheck::snoozed( $snooze, $version, $now ) ) {
			return array(
				'notice'      => false,
				'bar'         => false,
				'dismissible' => false,
			);
		}
		$warning = 'warning' === $r['level'];
		return array(
			'notice'      => 'admin' === $context && ! ( $warning && '' !== $dismissed && self::dismiss_key( $r ) === $dismissed ),
			'bar'         => true,
			'dismissible' => $warning,
		);
	}

	/**
	 * The decision for the current request and user.
	 *
	 * @return array{notice:bool,bar:bool,dismissible:bool}
	 */
	private static function current_visibility(): array {
		$user = get_current_user_id();
		return self::visibility(
			PhpVersionMaintenance::result(),
			current_user_can( 'manage_options' ),
			get_option( PhpVersionMaintenance::OPTION_SNOOZE, array() ),
			$user > 0 ? (string) get_user_meta( $user, self::DISMISS_META, true ) : '',
			PHP_VERSION,
			time(),
			is_admin() ? 'admin' : 'front'
		);
	}

	/**
	 * Admin bar node (front end and admin) for administrators.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public static function add_bar_node( $bar ): void {
		if ( ! self::current_visibility()['bar'] ) {
			return;
		}
		$r        = PhpVersionMaintenance::result();
		$critical = 'critical' === $r['level'];
		$external = $critical && '' !== $r['advisory_url'];
		$bar->add_node(
			array(
				'id'    => self::BAR_NODE,
				'title' => esc_html(
					$critical
						/* translators: %s: PHP version. */
						? sprintf( __( 'PHP %s: critical vulnerability', 'ux-studio' ), PHP_VERSION )
						: __( 'PHP: end of support', 'ux-studio' )
				),
				'href'  => $external ? $r['advisory_url'] : admin_url( 'admin.php?page=ux-studio#/module?id=security-optimization' ),
				'meta'  => array(
					'class'  => 'uxstudio-php-bar uxstudio-php-bar--' . ( $critical ? 'critical' : 'warning' ),
					'target' => $external ? '_blank' : '',
					'rel'    => $external ? 'noopener noreferrer' : '',
				),
			)
		);
	}

	/**
	 * Minimal CSS for the node, printed only when the node is shown.
	 */
	public static function print_bar_css(): void {
		if ( ! is_admin_bar_showing() || ! self::current_visibility()['bar'] ) {
			return;
		}
		echo '<style id="uxstudio-php-bar-css">#wpadminbar .uxstudio-php-bar--critical>.ab-item{background:#d63638!important;color:#fff!important;font-weight:600}'
			. '#wpadminbar .uxstudio-php-bar--warning>.ab-item{background:#dba617!important;color:#1d2327!important;font-weight:600}'
			. '#wpadminbar .uxstudio-php-bar>.ab-item:hover{opacity:.9}</style>' . "\n";
	}

	/**
	 * Register the widget, the notice, the admin bar node and the snooze / dismiss handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( self::class, 'handle_dismiss' ) );
		add_action( 'admin_bar_menu', array( self::class, 'add_bar_node' ), 999 );
		add_action( 'wp_head', array( self::class, 'print_bar_css' ), 99 );
		add_action( 'admin_head', array( self::class, 'print_bar_css' ), 99 );
		add_action( 'wp_dashboard_setup', array( self::class, 'add_widget' ), 1000 );
		add_filter( 'get_user_option_metaboxhidden_dashboard', array( self::class, 'never_hidden' ) );
		add_filter( 'get_user_option_closedpostboxes_dashboard', array( self::class, 'never_hidden' ) );
		add_action( 'admin_head-index.php', array( self::class, 'hide_screen_option' ) );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_snooze' ) );
	}

	/**
	 * Add the widget (administrators only, and only when there is something to say).
	 */
	public static function add_widget(): void {
		if ( ! current_user_can( 'manage_options' ) || 'ok' === PhpVersionMaintenance::result()['level'] ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Security - PHP version', 'ux-studio' ),
			array( self::class, 'render_widget' ),
			null,
			null,
			'normal',
			'high'
		);
	}

	/**
	 * @param mixed $value Stored list of widget ids.
	 * @return mixed
	 */
	public static function never_hidden( $value ) {
		return is_array( $value ) ? array_values( array_diff( $value, array( self::WIDGET_ID ) ) ) : $value;
	}

	/**
	 * No "show/hide" checkbox for this widget in Screen Options.
	 */
	public static function hide_screen_option(): void {
		echo '<style>label[for="' . esc_attr( self::WIDGET_ID ) . '-hide"]{display:none!important}#' . esc_attr( self::WIDGET_ID ) . ' .handle-actions{display:none}</style>';
	}

	/**
	 * One sentence per situation. Plain text (escape it where you print it).
	 *
	 * @param array $r Result of PhpVersionCheck::evaluate().
	 */
	public static function message( array $r ): string {
		$cves = implode( ', ', (array) $r['cves'] );

		if ( 'unsupported' === $r['reason'] ) {
			return sprintf(
				/* translators: 1: current PHP version, 2: recommended PHP version. */
				__( 'This website runs on PHP %1$s. This version no longer receives security updates, so every newly found vulnerability stays open and an attacker may be able to run code on the server. Switch to PHP %2$s or newer in your hosting administration.', 'ux-studio' ),
				$r['current'],
				$r['recommended']
			);
		}
		if ( 'outdated' === $r['reason'] && '' !== $cves ) {
			return sprintf(
				/* translators: 1: current PHP version, 2: CVE identifiers, 3: first safe PHP version. */
				__( 'This website runs on PHP %1$s. This version contains a critical vulnerability (%2$s): an attacker can run code on the server without logging in. Switch to PHP %3$s or newer in your hosting administration.', 'ux-studio' ),
				$r['current'],
				$cves,
				$r['minimum']
			);
		}
		if ( 'outdated' === $r['reason'] ) {
			return sprintf(
				/* translators: 1: current PHP version, 2: first safe PHP version. */
				__( 'This website runs on PHP %1$s. A security update is available for this version. Switch to PHP %2$s or newer in your hosting administration.', 'ux-studio' ),
				$r['current'],
				$r['minimum']
			);
		}
		return sprintf(
			/* translators: 1: current PHP version, 2: PHP branch, 3: end date, 4: days left, 5: recommended PHP version. */
			__( 'This website runs on PHP %1$s. Security support for PHP %2$s ends on %3$s (in %4$d days). Plan a switch to PHP %5$s or newer in your hosting administration.', 'ux-studio' ),
			$r['current'],
			$r['branch'],
			$r['eol'],
			(int) $r['days_to_eol'],
			$r['recommended']
		);
	}

	/**
	 * Why a patched-looking version may still be fine.
	 */
	public static function backport_note(): string {
		return __( 'Hosting providers that ship their own PHP build (Debian, Ubuntu and others) often fix security flaws without changing the version number. If your hosting confirms that the fix is in your build, you can hide this notice.', 'ux-studio' );
	}

	/**
	 * Widget body.
	 */
	public static function render_widget(): void {
		$r      = PhpVersionMaintenance::result();
		$colour = 'critical' === $r['level'] ? '#d63638' : '#dba617';
		$head   = 'critical' === $r['level'] ? __( 'Critical', 'ux-studio' ) : __( 'Warning', 'ux-studio' );
		?>
		<div class="uxs-php-widget" style="font-size:13px;padding:10px 12px;background:#f6f7f7;border-left:4px solid <?php echo esc_attr( $colour ); ?>;border-radius:0 4px 4px 0;">
			<p style="margin:0 0 6px;"><strong style="color:<?php echo esc_attr( $colour ); ?>;"><?php echo esc_html( $head ); ?></strong></p>
			<p style="margin:0 0 6px;"><?php echo esc_html( self::message( $r ) ); ?></p>
			<?php if ( $r['distro_build'] ) : ?>
				<p style="margin:0 0 6px;color:#646970;"><?php echo esc_html( self::backport_note() ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $r['advisory_url'] ) : ?>
				<p style="margin:0;"><a href="<?php echo esc_url( $r['advisory_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Security advisory on php.net', 'ux-studio' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Should the notice show for this result right now?
	 *
	 * @param array $r       Result of evaluate().
	 * @param mixed $snooze  Stored snooze option.
	 * @param int   $now     Unix time.
	 */
	public static function should_show( array $r, $snooze, int $now ): bool {
		return 'ok' !== $r['level'] && ! PhpVersionCheck::snoozed( $snooze, PHP_VERSION, $now );
	}

	/**
	 * Admin notice (administrators, every admin screen).
	 */
	public static function render_notice(): void {
		$v = self::current_visibility();
		if ( ! $v['notice'] ) {
			return;
		}
		$r     = PhpVersionMaintenance::result();
		$class = 'critical' === $r['level'] ? 'notice-error' : 'notice-warning';
		$url   = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		// `below-h2` keeps WordPress and the Admin Customiser from moving it to the tray; the Admin Customiser
		// "hide all notices" rule also leaves this id alone (HideAdminNotices). It must be seen.
		echo '<div id="uxstudio-php-version-notice" class="notice ' . esc_attr( $class ) . ' below-h2">'
			. '<p><strong>' . esc_html( 'critical' === $r['level'] ? __( 'Critical: the PHP version of this website is not safe.', 'ux-studio' ) : __( 'The PHP version of this website will soon lose security support.', 'ux-studio' ) ) . '</strong></p>'
			. '<p>' . esc_html( self::message( $r ) ) . '</p>';
		if ( '' !== $r['advisory_url'] ) {
			echo '<p><a href="' . esc_url( $r['advisory_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Security advisory on php.net', 'ux-studio' ) . '</a></p>';
		}
		if ( $r['distro_build'] ) {
			echo '<p>' . esc_html( self::backport_note() ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Verified with hosting, hide for 30 days', 'ux-studio' ) . '</a>';
		if ( $v['dismissible'] ) {
			$hide = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
			echo ' <a class="button-link" href="' . esc_url( $hide ) . '">' . esc_html__( 'Hide this warning', 'ux-studio' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * admin-post handler: a user hides the warning (not the critical notice) for themselves.
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DISMISS_ACTION );
		$r = PhpVersionMaintenance::result();
		if ( 'warning' === $r['level'] ) {
			update_user_meta( get_current_user_id(), self::DISMISS_META, self::dismiss_key( $r ) );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/**
	 * admin-post handler: hide the notice for 30 days on this PHP version.
	 */
	public static function handle_snooze(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		update_option(
			PhpVersionMaintenance::OPTION_SNOOZE,
			array(
				'until'   => time() + self::SNOOZE_DAYS * DAY_IN_SECONDS,
				'version' => PHP_VERSION,
			),
			false
		);
		ActivityLog::log( 'security-optimization', 'php_version_snooze', 'setting', 0, array( 'version' => PHP_VERSION ) );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
