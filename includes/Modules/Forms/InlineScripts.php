<?php
/**
 * The Forms module's inline scripts for the CSP hash registry.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

use UxStudio\Modules\SecurityOptimization\CspInlineScripts;

defined( 'ABSPATH' ) || exit;

/**
 * Two kinds of inline script reach a public page: the shared runtime (static text) and, per form with a custom
 * "after submit" script, the hook registration. The per-form config is a JSON data block, which CSP does not govern.
 *
 * The hook hashes are cached in an option (autoloaded, a few dozen bytes per form) and rebuilt whenever a form is
 * created, changed, restored or deleted, so sending the header never reads the forms table. The cache is tied to the
 * plugin version: the hook wrapper text may change between releases.
 */
final class InlineScripts {

	public const OPTION = 'uxstudio_forms_hook_hashes';

	public static function register(): void {
		CspInlineScripts::register(
			'forms-runtime',
			__( 'Forms: shared form script', 'ux-studio' ),
			static fn(): array => array( PublicRenderer::runtime_body() )
		);
		CspInlineScripts::register(
			'forms-hooks',
			__( 'Forms: custom script after submit', 'ux-studio' ),
			array( self::class, 'hook_items' )
		);
	}

	/**
	 * @return list<array{hash:string,label:string}>
	 */
	public static function hook_items(): array {
		$cache = get_option( self::OPTION, null );
		if ( ! is_array( $cache ) || ( $cache['version'] ?? '' ) !== UXSTUDIO_VERSION || ! isset( $cache['hashes'] ) ) {
			$cache = self::rebuild();
		}
		$out = array();
		foreach ( (array) $cache['hashes'] as $form_id => $hash ) {
			$out[] = array(
				'hash'  => (string) $hash,
				/* translators: %d: form id. */
				'label' => sprintf( __( 'Forms: custom script of form #%d', 'ux-studio' ), (int) $form_id ),
			);
		}
		return $out;
	}

	/**
	 * Recompute the hash of every ACTIVE form's custom script (a draft or archived form is never printed).
	 *
	 * @return array{version:string,hashes:array<int,string>}
	 */
	public static function rebuild(): array {
		global $wpdb;
		$rows   = $wpdb->get_results( "SELECT id, settings_json FROM {$wpdb->prefix}uxstudio_forms WHERE status = 'active' AND settings_json LIKE '%custom_js%' ORDER BY id ASC", ARRAY_A );
		$hashes = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$settings = json_decode( (string) $row['settings_json'], true );
			$code     = is_array( $settings ) ? (string) ( $settings['tracking']['custom_js'] ?? '' ) : '';
			$body     = AfterSubmit::hook_script_body( (int) $row['id'], $code );
			if ( '' !== $body ) {
				$hashes[ (int) $row['id'] ] = CspInlineScripts::hash( $body );
			}
		}
		if ( count( $hashes ) > CspInlineScripts::MAX ) {
			error_log( sprintf( 'UX Studio CSP: %d forms have a custom script, only the first %d are allowed by hash.', count( $hashes ), CspInlineScripts::MAX ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$hashes = array_slice( $hashes, 0, CspInlineScripts::MAX, true );
		}
		$cache = array(
			'version' => UXSTUDIO_VERSION,
			'hashes'  => $hashes,
		);
		update_option( self::OPTION, $cache, true );
		return $cache;
	}
}
