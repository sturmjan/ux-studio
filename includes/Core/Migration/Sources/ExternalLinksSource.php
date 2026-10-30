<?php
/**
 * Source: WP External Links.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\LinkManager\LinkRules;
use UxStudio\Modules\MenuIcons\IconLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin options `wpel-external-link-settings` { apply_settings, target, rel_follow, rel_noopener, rel_noreferrer,
 * rel_external, rel_sponsored, rel_ugc, icon_type, icon_dashicon, icon_position, no_icon_for_img, title, class }
 * and `wpel-exceptions-settings` { ignore_classes, subdomains_as_internal_links, exclude_urls, include_urls, ... }.
 *
 * Target module `link-manager`: how external links open, the rel tokens, the icon (arrow, Dashicon or none) and its
 * position, classes to ignore, addresses that are never external (WPEL "exclude URLs", a substring of the address ->
 * `*text*`) and subdomains as internal links. Rules per domain have no counterpart in the plugin.
 */
final class ExternalLinksSource extends AbstractSource {

	private const MODULE = 'link-manager';

	protected function option_names(): array {
		return array( 'wpel-external-link-settings', 'wpel-exceptions-settings' );
	}

	protected function map( bool $active ): Mapping {
		$m   = new Mapping();
		$ext = $this->opt( 'wpel-external-link-settings' );
		$exc = $this->opt( 'wpel-exceptions-settings' );
		if ( ! is_array( $ext ) && ! is_array( $exc ) ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}

		if ( is_array( $exc ) ) {
			$this->map_exceptions( $m, $exc );
		}
		if ( is_array( $ext ) ) {
			$this->map_external( $m, $ext );
		}

		$lost = array(
			__( 'the settings for internal links', 'ux-studio' ),
			__( 'the link titles and added CSS classes', 'ux-studio' ),
			__( 'the exit confirmation', 'ux-studio' ),
		);
		if ( is_array( $exc ) ) {
			if ( '' !== trim( (string) ( $exc['include_urls'] ?? '' ) ) ) {
				$lost[] = __( 'the addresses that are always treated as external', 'ux-studio' );
			}
			if ( '' !== trim( (string) ( $exc['skip_post_ids'] ?? '' ) ) ) {
				$lost[] = __( 'the list of posts to skip', 'ux-studio' );
			}
		}
		$m->lossy(
			sprintf(
				/* translators: %s: comma-separated list of plugin features. */
				__( 'Not transferred: %s. The module changes links in the content of posts and pages only (the plugin also reaches comments and widgets).', 'ux-studio' ),
				implode( ', ', $lost )
			)
		);
		return $m;
	}

	/**
	 * Exceptions: classes, subdomains and the addresses that are not external.
	 *
	 * @param Mapping              $m   Mapping.
	 * @param array<string, mixed> $exc Option `wpel-exceptions-settings`.
	 */
	private function map_exceptions( Mapping $m, array $exc ): void {
		if ( '' !== trim( (string) ( $exc['ignore_classes'] ?? '' ) ) ) {
			$m->set( self::MODULE, 'exclude_classes', trim( (string) $exc['ignore_classes'] ), __( 'Exclude classes', 'ux-studio' ), 'wpel-exceptions-settings:ignore_classes' );
		}
		if ( array_key_exists( 'subdomains_as_internal_links', $exc ) ) {
			$m->set( self::MODULE, 'subdomains_internal', ! empty( $exc['subdomains_as_internal_links'] ), __( 'Subdomains of this site are internal', 'ux-studio' ), 'wpel-exceptions-settings:subdomains_as_internal_links' );
		}

		// The plugin matches any part of the address (line or comma separated); the module's patterns are anchored, so wrap them.
		$parts = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) ( $exc['exclude_urls'] ?? '' ) ) ?: array() as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$parts[] = '*' . trim( $part, '*' ) . '*';
			}
		}
		if ( array() !== $parts ) {
			$m->set( self::MODULE, 'internal_domains', implode( "\n", array_values( array_unique( $parts ) ) ), __( 'Never treat as external', 'ux-studio' ), 'wpel-exceptions-settings:exclude_urls' );
			$m->note( __( 'Each excluded URL of the plugin matches anywhere in the address; it became a pattern with a star on both sides.', 'ux-studio' ) );
		}
	}

	/**
	 * How external links are treated.
	 *
	 * @param Mapping              $m   Mapping.
	 * @param array<string, mixed> $ext Option `wpel-external-link-settings`.
	 */
	private function map_external( Mapping $m, array $ext ): void {
		$origin = 'wpel-external-link-settings';
		$get    = static fn( string $key, $default = '' ) => array_key_exists( $key, $ext ) ? $ext[ $key ] : $default;

		if ( '' === (string) $get( 'apply_settings', '1' ) ) {
			// The plugin does not touch external links at all: the module is set to do the same.
			$m->set( self::MODULE, 'default_target', 'keep', __( 'External links open', 'ux-studio' ), $origin . ':apply_settings' );
			$m->set( self::MODULE, 'default_rel', '', __( 'rel attribute of external links', 'ux-studio' ), $origin . ':apply_settings' );
			$m->set( self::MODULE, 'icon_type', 'none', __( 'Icon of external links', 'ux-studio' ), $origin . ':apply_settings' );
			$m->note( __( 'The plugin has the settings for external links turned off, so the module does not change them either (no new tab, no rel, no icon).', 'ux-studio' ) );
			return;
		}

		$target = (string) $get( 'target', '' );
		$m->set( self::MODULE, 'default_target', '_blank' === $target ? 'new_tab' : 'keep', __( 'External links open', 'ux-studio' ), $origin . ':target' );
		if ( in_array( $target, array( '_new', '_top' ), true ) ) {
			$m->lossy(
				sprintf(
					/* translators: %s: target value of the plugin. */
					__( 'The target "%s" does not exist in the module; links keep the target the author set.', 'ux-studio' ),
					$target
				)
			);
		}

		$tokens = array();
		if ( 'nofollow' === (string) $get( 'rel_follow', '' ) ) {
			$tokens[] = 'nofollow';
		}
		foreach ( array(
			'rel_noopener'   => 'noopener',
			'rel_noreferrer' => 'noreferrer',
			'rel_external'   => 'external',
			'rel_sponsored'  => 'sponsored',
			'rel_ugc'        => 'ugc',
		) as $key => $token ) {
			$default = in_array( $key, array( 'rel_noopener', 'rel_noreferrer', 'rel_external' ), true ) ? '1' : '0';
			if ( '' !== (string) $get( $key, $default ) && '0' !== (string) $get( $key, $default ) ) {
				$tokens[] = $token;
			}
		}
		$order = array_flip( LinkRules::REL_TOKENS );
		usort( $tokens, static fn( string $a, string $b ): int => $order[ $a ] <=> $order[ $b ] );
		$m->set( self::MODULE, 'default_rel', implode( ' ', $tokens ), __( 'rel attribute of external links', 'ux-studio' ), $origin . ':rel_*' );
		if ( 'follow' === (string) $get( 'rel_follow', '' ) ) {
			$m->note( __( 'The plugin adds rel="follow" to external links; that value does not exist in HTML and the module does not add it.', 'ux-studio' ) );
		}

		$icon = (string) $get( 'icon_type', '' );
		if ( '' === $icon ) {
			$m->set( self::MODULE, 'icon_type', 'none', __( 'Icon of external links', 'ux-studio' ), $origin . ':icon_type' );
		} elseif ( 'dashicon' === $icon && IconLibrary::dashicon_exists( (string) $get( 'icon_dashicon', '' ) ) ) {
			$m->set( self::MODULE, 'icon_type', 'dashicon', __( 'Icon of external links', 'ux-studio' ), $origin . ':icon_type' );
			$m->set( self::MODULE, 'icon_dashicon', (string) $get( 'icon_dashicon', '' ), __( 'Dashicon', 'ux-studio' ), $origin . ':icon_dashicon' );
		} else {
			$m->set( self::MODULE, 'icon_type', 'arrow', __( 'Icon of external links', 'ux-studio' ), $origin . ':icon_type' );
			$m->lossy( __( 'The icon of the plugin (an image or a Font Awesome icon) is replaced by the arrow of the module; the Dashicon option is available in the module if you prefer a Dashicon.', 'ux-studio' ) );
		}
		if ( '' !== $icon ) {
			$m->set( self::MODULE, 'icon_position', 'left' === (string) $get( 'icon_position', 'right' ) ? 'left' : 'right', __( 'Icon position', 'ux-studio' ), $origin . ':icon_position' );
			$m->set( self::MODULE, 'icon_skip_images', '' !== (string) $get( 'no_icon_for_img', '1' ) && '0' !== (string) $get( 'no_icon_for_img', '1' ), __( 'No icon on links that contain an image', 'ux-studio' ), $origin . ':no_icon_for_img' );
		}
	}
}
