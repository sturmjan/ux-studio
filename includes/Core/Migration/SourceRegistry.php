<?php
/**
 * All migration sources, one per foreign plugin (slug), linked to the Catalog.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\Migration\Sources\AutoImageUploadSource;
use UxStudio\Core\Migration\Sources\CaptchaSource;
use UxStudio\Core\Migration\Sources\ClassicEditorSource;
use UxStudio\Core\Migration\Sources\DisableCommentsSource;
use UxStudio\Core\Migration\Sources\DuplicatePostSource;
use UxStudio\Core\Migration\Sources\ExternalLinksSource;
use UxStudio\Core\Migration\Sources\HideAdminBarSource;
use UxStudio\Core\Migration\Sources\LoginSource;
use UxStudio\Core\Migration\Sources\NoSettingsSource;
use UxStudio\Core\Migration\Sources\Redirect404Source;
use UxStudio\Core\Migration\Sources\SvgSource;
use UxStudio\Core\Migration\Sources\UpdatesSource;
use UxStudio\Core\Migration\Sources\WpExtendedSource;
use UxStudio\Core\Migration\Sources\XmlRpcSource;

defined( 'ABSPATH' ) || exit;

/**
 * The slug => class map below is the single place that decides which plugins the wizard
 * can migrate in phase F2. Every slug must also be in the Catalog (tests/migration-sources.php
 * checks it), so the overlap guard and the wizard speak about the same plugins.
 * Phase F3+ adds the sources that carry data.
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
