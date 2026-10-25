<?php
/**
 * Source: Auto Upload Images.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin option `aui-setting` { base_url, image_name, alt_name, max_width, max_height, exclude_urls, exclude_post_types,
 * custom_fields }. Target module `auto-image-upload` (PLAN.md 27.10):
 *
 *  - base_url          -> base_url   (the plugin's default is the site's own address = no replacement = empty in the module)
 *  - image_name        -> file_name_pattern, alt_name -> alt_pattern (the same placeholders)
 *  - max_width / height -> max_width / max_height
 *  - exclude_urls      -> excluded_domains (one per line)
 *  - exclude_post_types -> excluded_post_types (only types that exist on this site)
 *  - custom_fields     -> custom_fields (one meta key per line)
 *
 * The module's own limits (size in MB, editor notices, safety net on save, images per save) have no counterpart in the plugin
 * and keep their values. Differences that stay: the plugin kept the original and used a resized copy (the module resizes the
 * file itself and does not keep the original size), and it reused an identical file already in the uploads folder.
 */
final class AutoImageUploadSource extends AbstractSource {

	private const MODULE = 'auto-image-upload';

	protected function option_names(): array {
		return array( 'aui-setting' );
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'aui-setting' );
		if ( ! is_array( $o ) ) {
			$m->note( __( 'The plugin has no stored settings, the module works with its defaults and downloads external images on save just like the plugin.', 'ux-studio' ) );
			return $m;
		}

		$origin = 'aui-setting';
		$site   = self::host( function_exists( 'home_url' ) ? home_url() : '' );

		// The plugin writes its default (the address of the site) into the option, which means "no replacement".
		$base = trim( (string) ( $o['base_url'] ?? '' ) );
		if ( '' !== $base && '/' !== $base && self::host( $base ) === $site ) {
			$base = '';
		}
		if ( '' !== $base && '/' !== $base && ! preg_match( '#^https?://[^/\s]+#i', $base ) ) {
			$m->lossy(
				sprintf(
					/* translators: %s: the base address of the plugin. */
					__( 'The base address "%s" is not a full http(s) address, so it is not transferred.', 'ux-studio' ),
					$base
				)
			);
			$base = '';
		}
		$m->set( self::MODULE, 'base_url', $base, __( 'Address of the uploaded images', 'ux-studio' ), $origin . ':base_url' );

		// %date% and %day% are the plugin's deprecated names; the settings sanitizer would eat them (it removes %da... as an octet).
		$modern = array(
			'%date%' => '%today_date%',
			'%day%'  => '%today_day%',
		);
		$name   = strtr( trim( (string) ( $o['image_name'] ?? '' ) ), $modern );
		$m->set( self::MODULE, 'file_name_pattern', '' !== $name ? $name : '%filename%', __( 'File name', 'ux-studio' ), $origin . ':image_name' );
		$alt = strtr( trim( (string) ( $o['alt_name'] ?? '' ) ), $modern );
		$m->set( self::MODULE, 'alt_pattern', '' !== $alt ? $alt : '%image_alt%', __( 'Alt text', 'ux-studio' ), $origin . ':alt_name' );

		$m->set( self::MODULE, 'max_width', max( 0, (int) ( $o['max_width'] ?? 0 ) ), __( 'Largest width (px)', 'ux-studio' ), $origin . ':max_width' );
		$m->set( self::MODULE, 'max_height', max( 0, (int) ( $o['max_height'] ?? 0 ) ), __( 'Largest height (px)', 'ux-studio' ), $origin . ':max_height' );

		$domains = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\n|\r/', (string) ( $o['exclude_urls'] ?? '' ) ) ?: array() ) ) );
		$m->set( self::MODULE, 'excluded_domains', implode( "\n", $domains ), __( 'Excluded domains', 'ux-studio' ), $origin . ':exclude_urls' );

		$types = array_values( array_unique( array_map( 'strval', (array) ( $o['exclude_post_types'] ?? array() ) ) ) );
		if ( $types ) {
			$known = function_exists( 'get_post_types' ) ? array_keys( get_post_types( array( 'public' => true ) ) ) : array();
			$keep  = array_values( array_intersect( $types, $known ) );
			$m->set( self::MODULE, 'excluded_post_types', $keep, __( 'Excluded content types', 'ux-studio' ), $origin . ':exclude_post_types' );
			$gone = array_values( array_diff( $types, $keep ) );
			if ( $gone ) {
				$m->lossy(
					sprintf(
						/* translators: %s: comma-separated content types. */
						__( 'Excluded content types that do not exist on this site (or are not public) are not transferred: %s.', 'ux-studio' ),
						implode( ', ', $gone )
					)
				);
			}
		}

		$fields = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) ( $o['custom_fields'] ?? array() ) ) ) ) );
		if ( $fields ) {
			$m->set( self::MODULE, 'custom_fields', implode( "\n", $fields ), __( 'Custom fields', 'ux-studio' ), $origin . ':custom_fields' );
		}

		$m->lossy( __( 'The plugin used a resized COPY of the image and kept the original in the uploads folder; the module resizes the file itself, so the original size is not kept. The plugin also reused an identical file that was already there; the module remembers addresses it has already downloaded instead.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * Host of an address without `www.`.
	 *
	 * @param string $url Address.
	 */
	private static function host( string $url ): string {
		return (string) preg_replace( '/^www\d?\./', '', strtolower( (string) parse_url( $url, PHP_URL_HOST ) ) );
	}
}
