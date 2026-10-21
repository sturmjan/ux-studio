<?php
/**
 * All migration sources, one per foreign plugin (slug), linked to the Catalog.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\Migration\Sources\ActivityLogSource;
use UxStudio\Core\Migration\Sources\AutoImageUploadSource;
use UxStudio\Core\Migration\Sources\AutoUnpublishSource;
use UxStudio\Core\Migration\Sources\CaptchaSource;
use UxStudio\Core\Migration\Sources\ClassicEditorSource;
use UxStudio\Core\Migration\Sources\CodeSnippetsSource;
use UxStudio\Core\Migration\Sources\CronSource;
use UxStudio\Core\Migration\Sources\DisableCommentsSource;
use UxStudio\Core\Migration\Sources\DuplicatePostSource;
use UxStudio\Core\Migration\Sources\EmailLogSource;
use UxStudio\Core\Migration\Sources\ExternalLinksSource;
use UxStudio\Core\Migration\Sources\ExternalPermalinksSource;
use UxStudio\Core\Migration\Sources\FolderSource;
use UxStudio\Core\Migration\Sources\HideAdminBarSource;
use UxStudio\Core\Migration\Sources\LastLoginSource;
use UxStudio\Core\Migration\Sources\LoginSource;
use UxStudio\Core\Migration\Sources\MaintenanceSource;
use UxStudio\Core\Migration\Sources\MenuIconsSource;
use UxStudio\Core\Migration\Sources\MenuVisibilitySource;
use UxStudio\Core\Migration\Sources\NoSettingsSource;
use UxStudio\Core\Migration\Sources\PostTypeOrderSource;
use UxStudio\Core\Migration\Sources\Redirect404Source;
use UxStudio\Core\Migration\Sources\RedirectsSource;
use UxStudio\Core\Migration\Sources\SmtpSource;
use UxStudio\Core\Migration\Sources\SvgSource;
use UxStudio\Core\Migration\Sources\UpdatesSource;
use UxStudio\Core\Migration\Sources\WpExtendedSource;
use UxStudio\Core\Migration\Sources\XmlRpcSource;

defined( 'ABSPATH' ) || exit;

/**
 * The slug => class map below is the single place that decides which plugins the wizard
 * can migrate (F2 settings, F3 data). Every slug must also be in the Catalog (tests/migration-sources.php
 * checks it), so the overlap guard and the wizard speak about the same plugins.
 */
final class SourceRegistry {

	/**
	 * Slug => source class.
	 *
	 * @return array<string, class-string<Source>>
	 */
	public static function classes(): array {
		$map = array();
		foreach ( array(
			ClassicEditorSource::class    => array( 'classic-editor' ),
			NoSettingsSource::class       => array(
				'classic-widgets',
				'duplicate-menu',
				'enable-media-replace',
				'post-type-switcher',
				'user-switching',
				'wp-rollback',
				'instant-images',
				'reveal-ids-for-wp-admin-25',
				'show-ids',
				'simple-page-ordering',
			),
			DuplicatePostSource::class    => array( 'duplicate-post', 'duplicate-page', 'post-duplicator' ),
			DisableCommentsSource::class  => array( 'disable-comments' ),
			HideAdminBarSource::class     => array( 'hide-admin-bar' ),
			SvgSource::class              => array( 'safe-svg', 'svg-support' ),
			Redirect404Source::class      => array( 'all-404-redirect-to-homepage', 'redirect-404-to-homepage' ),
			UpdatesSource::class          => array( 'disable-wordpress-updates', 'stops-core-theme-and-plugin-updates' ),
			LoginSource::class            => array( 'limit-login-attempts-reloaded', 'wps-hide-login', 'loginizer' ),
			CaptchaSource::class          => array( 'simple-cloudflare-turnstile', 'advanced-google-recaptcha' ),
			XmlRpcSource::class           => array( 'disable-xml-rpc', 'disable-xml-rpc-api' ),
			AutoImageUploadSource::class  => array( 'auto-upload-images' ),
			ExternalLinksSource::class    => array( 'wp-external-links' ),
			WpExtendedSource::class       => array( 'wpextended' ),
			// F3: sources with data.
			RedirectsSource::class        => array( 'redirection', 'safe-redirect-manager', 'simple-301-redirects', '301-redirects' ),
			CodeSnippetsSource::class     => array( 'code-snippets', 'insert-headers-and-footers', 'header-footer-code-manager' ),
			FolderSource::class           => array( 'filebird', 'folders', 'real-media-library-lite', 'wicked-folders' ),
			ExternalPermalinksSource::class => array( 'page-links-to' ),
			AutoUnpublishSource::class    => array( 'post-expirator' ),
			MenuIconsSource::class        => array( 'menu-icons' ),
			MenuVisibilitySource::class   => array( 'nav-menu-roles', 'if-menu', 'menu-items-visibility-control' ),
			LastLoginSource::class        => array( 'when-last-login', 'wp-last-login' ),
			SmtpSource::class             => array( 'wp-mail-smtp', 'easy-wp-smtp', 'post-smtp', 'fluent-smtp' ),
			EmailLogSource::class         => array( 'wp-mail-logging', 'email-log' ),
			CronSource::class             => array( 'wp-crontrol' ),
			MaintenanceSource::class      => array( 'wp-maintenance-mode', 'coming-soon', 'maintenance', 'under-construction-page' ),
			ActivityLogSource::class      => array( 'simple-history', 'wp-security-audit-log', 'aryo-activity-log', 'adminify-activity-logs' ),
			PostTypeOrderSource::class    => array( 'post-types-order', 'intuitive-custom-post-order' ),
		) as $class => $slugs ) {
			foreach ( $slugs as $slug ) {
				$map[ $slug ] = $class;
			}
		}
		return $map;
	}

	/**
	 * A source by id (plugin slug), or null.
	 *
	 * @param string $id Plugin slug.
	 */
	public static function get( string $id ): ?Source {
		$classes = self::classes();
		if ( ! isset( $classes[ $id ] ) ) {
			return null;
		}
		$class = $classes[ $id ];
		return new $class( $id );
	}

	/**
	 * Every source, fresh instances.
	 *
	 * @return array<string, Source>
	 */
	public static function all(): array {
		$out = array();
		foreach ( array_keys( self::classes() ) as $id ) {
			$source = self::get( (string) $id );
			if ( $source ) {
				$out[ $id ] = $source;
			}
		}
		return $out;
	}

	/**
	 * What the scan step shows: every source whose plugin is installed or whose settings
	 * are still in the database ("data only" - the plugin was removed but left its
	 * options behind). Active ones first, then inactive, then data-only; each group by name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan(): array {
		PluginInfo::flush();
		$rows = array();
		foreach ( self::all() as $source ) {
			$detect = $source->detect();
			if ( 'absent' !== $detect['status'] ) {
				$rows[] = $detect;
			}
		}
		$rank = array(
			'active'    => 0,
			'inactive'  => 1,
			'data_only' => 2,
		);
		usort(
			$rows,
			static function ( array $a, array $b ) use ( $rank ): int {
				return array( $rank[ $a['status'] ], strtolower( $a['label'] ) ) <=> array( $rank[ $b['status'] ], strtolower( $b['label'] ) );
			}
		);
		return $rows;
	}
}
