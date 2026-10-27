<?php
/**
 * Registry of the data providers (modules with portable content).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

use UxStudio\Core\Modules;
use UxStudio\Core\Transfer\Providers\CodeSnippetsProvider;
use UxStudio\Core\Transfer\Providers\FormsProvider;
use UxStudio\Core\Transfer\Providers\LocationsProvider;
use UxStudio\Core\Transfer\Providers\NoticeCategoriesProvider;
use UxStudio\Core\Transfer\Providers\PopupProvider;
use UxStudio\Core\Transfer\Providers\RedirectsProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in providers: Forms (definitions), Redirects (rules), Code Snippets (code, switched off on import),
 * Popup Manager (popups), Opening Hours (locations), Notice Board (categories).
 *
 * Deliberately NOT providers: form submissions, the 404 and activity logs, statistics, subscribers, notices
 * with their documents, folders of the media library (they hang on attachment ids), menu visibility rules
 * (they hang on menu item ids), IP bans (they are operating data, not settings).
 *
 * An add-on module opts in with the `ux_studio/transfer_providers` filter.
 */
final class DataProviders {

	/**
	 * Providers by module id.
	 *
	 * @param Modules $modules Module registry (live module instances are handed to the providers).
	 * @return array<string, DataProvider>
	 */
	public static function all( Modules $modules ): array {
		$list = array(
			new FormsProvider( self::module( $modules, 'forms' ) ),
			new RedirectsProvider(),
			new CodeSnippetsProvider(),
			new PopupProvider( self::module( $modules, 'popup-manager' ) ),
			new LocationsProvider( self::module( $modules, 'opening-hours' ) ),
			new NoticeCategoriesProvider( self::module( $modules, 'notice-board' ) ),
		);
		/**
		 * Extension API: more providers of portable content.
		 *
		 * @param DataProvider[] $list    Providers.
		 * @param Modules        $modules Module registry.
		 */
		$list = apply_filters( 'ux_studio/transfer_providers', $list, $modules );
		$out  = array();
		foreach ( (array) $list as $provider ) {
			if ( $provider instanceof DataProvider ) {
				$out[ $provider->module() ] = $provider;
			}
		}
		return $out;
	}

	/**
	 * One provider.
	 *
	 * @param Modules $modules Module registry.
	 * @param string  $module  Module id.
	 */
	public static function for_module( Modules $modules, string $module ): ?DataProvider {
		return self::all( $modules )[ $module ] ?? null;
	}

	/**
	 * Live module instance of the given class family, or null.
	 *
	 * @param Modules $modules Module registry.
	 * @param string  $id      Module id.
	 * @return object|null
	 */
	private static function module( Modules $modules, string $id ) {
		return $modules->instance( $id );
	}
}
