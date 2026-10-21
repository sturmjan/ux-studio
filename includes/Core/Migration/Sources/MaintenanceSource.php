<?php
/**
 * Source: WP Maintenance Mode, Coming Soon (SeedProd), Maintenance, Under Construction.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `maintenance-mode`: `mode` (disabled | maintenance 503 | coming_soon 200), the page (own layout or an
 * existing page), who may still see the site (logged in or by role), headline, text and colours.
 *
 * BEHAVIOUR-CHANGING in the strongest way - the mode switches the whole site off for visitors. So with the module
 * on, every value including `mode` waits in the staging area. With the module off the settings are written as the
 * old plugin had them (status on = `maintenance`), and the plan says plainly that the site goes into maintenance
 * mode the moment the module is switched on.
 *
 *  - WP Maintenance Mode: `wpmm_settings` { general { status, frontend_role[] }, design { heading, heading_color, text,
 *    text_color, bg_type, bg_color } }.
 *  - Coming Soon / Maintenance Mode by SeedProd: JSON option `seedprod_settings` (`enable_coming_soon_mode`,
 *    `enable_maintenance_mode`) and the page ids `seedprod_coming_soon_page_id` / `seedprod_maintenance_mode_page_id`;
 *    the design lives in the page builder, so the module shows that page as an "existing page".
 *  - Maintenance (WebFactory): `mtnc-options` { options { status, header_text, mode, mtnc_page } }.
 *  - Under Construction: `ucp_options` { status, heading1, content, whitelisted_roles }.
 */
final class MaintenanceSource extends AbstractSource {

	private const MODULE = 'maintenance-mode';

	protected function option_names(): array {
		switch ( $this->slug ) {
			case 'coming-soon':
				return array( 'seedprod_settings' );
			case 'maintenance':
				return array( 'mtnc-options' );
			case 'under-construction-page':
				return array( 'ucp_options' );
			default:
				return array( 'wpmm_settings' );
		}
	}

	/**
	 * Queue one normalized configuration.
	 *
	 * @param Mapping              $m      Mapping.
	 * @param array<string, mixed> $c      mode, headline, body, headline_colour, body_colour, background, roles (null = not known), page.
	 * @param string               $origin Origin.
	 */
	private function fill( Mapping $m, array $c, string $origin ): void {
		$m->set( self::MODULE, 'mode', $c['mode'], __( 'Mode', 'ux-studio' ), $origin );
		if ( ! empty( $c['page'] ) ) {
			$m->set( self::MODULE, 'layout_type', 'page', __( 'Layout type', 'ux-studio' ), $origin );
			$m->set( self::MODULE, 'existing_page', (string) $c['page'], __( 'Existing page', 'ux-studio' ), $origin );
		} else {
			$m->set( self::MODULE, 'layout_type', 'custom', __( 'Layout type', 'ux-studio' ), $origin );
			if ( '' !== (string) $c['headline'] ) {
				$m->set( self::MODULE, 'headline_text', $c['headline'], __( 'Headline', 'ux-studio' ), $origin );
			}
			if ( '' !== (string) $c['body'] ) {
				$m->set( self::MODULE, 'body_text', $c['body'], __( 'Text', 'ux-studio' ), $origin );
			}
			if ( '' !== (string) $c['headline_colour'] ) {
				$m->set( self::MODULE, 'headline_colour', $c['headline_colour'], __( 'Headline colour', 'ux-studio' ), $origin );
			}
			if ( '' !== (string) $c['body_colour'] ) {
				$m->set( self::MODULE, 'body_colour', $c['body_colour'], __( 'Text colour', 'ux-studio' ), $origin );
			}
			if ( '' !== (string) $c['background'] ) {
				$m->set( self::MODULE, 'background_colour', $c['background'], __( 'Background colour', 'ux-studio' ), $origin );
			}
		}
		if ( null !== $c['roles'] ) {
			$m->set( self::MODULE, 'auth_type', 'roles', __( 'Authentication type', 'ux-studio' ), $origin );
			$m->set( self::MODULE, 'access_roles', $c['roles'], __( 'Allowed roles', 'ux-studio' ), $origin );
		}
		if ( 'disabled' !== $c['mode'] ) {
			$m->warn( __( 'The old plugin has maintenance or coming soon mode ON. Once the module is switched on, the site shows the maintenance page to visitors. Switch the old plugin off first and check the page in the preview.', 'ux-studio' ) );
		}
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		switch ( $this->slug ) {
			case 'coming-soon':
				return $this->map_seedprod( $m );
			case 'maintenance':
				return $this->map_maintenance( $m );
			case 'under-construction-page':
				return $this->map_ucp( $m );
			default:
				return $this->map_wpmm( $m );
		}
	}

	/**
	 * @param Mapping $m Mapping.
	 */
	private function map_wpmm( Mapping $m ): Mapping {
		$o = $this->opt( 'wpmm_settings' );
		if ( ! is_array( $o ) ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$g      = (array) ( $o['general'] ?? array() );
		$d      = (array) ( $o['design'] ?? array() );
		$roles  = array_values( array_filter( array_map( 'strval', (array) ( $g['frontend_role'] ?? array() ) ) ) );
		$bg_ok  = 'color' === (string) ( $d['bg_type'] ?? 'color' );
		$this->fill(
			$m,
			array(
				'mode'            => ! empty( $g['status'] ) ? 'maintenance' : 'disabled',
				'headline'        => wp_strip_all_tags( (string) ( $d['heading'] ?? '' ) ),
				'body'            => (string) ( $d['text'] ?? '' ),
				'headline_colour' => (string) ( $d['heading_color'] ?? '' ),
				'body_colour'     => (string) ( $d['text_color'] ?? '' ),
				'background'      => $bg_ok ? (string) ( $d['bg_color'] ?? '' ) : '',
				'roles'           => $roles,
				'page'            => 0,
			),
			'wpmm_settings'
		);
		if ( ! $bg_ok ) {
			$m->lossy( __( 'The background image of the plugin is not transferred, only a background colour. Set the image in the module.', 'ux-studio' ) );
		}
		$m->lossy( __( 'Countdown, subscribe form, social links, contact form, chat bot, GDPR notice, custom CSS and "let search engine bots in" of the plugin are not transferred.', 'ux-studio' ) );
		$m->note( __( 'Who may still see the site: roles from the plugin; administrators always can. With no role listed only administrators get through.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * @param Mapping $m Mapping.
	 */
	private function map_seedprod( Mapping $m ): Mapping {
		$raw = $this->opt( 'seedprod_settings' );
		$s   = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		if ( ! is_array( $s ) ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$soon = ! empty( $s['enable_coming_soon_mode'] );
		$mm   = ! empty( $s['enable_maintenance_mode'] );
		$page = (int) get_option( $mm ? 'seedprod_maintenance_mode_page_id' : 'seedprod_coming_soon_page_id', 0 );
		if ( $page <= 0 ) {
			$page = (int) get_option( 'seedprod_coming_soon_page_id', 0 ) ?: (int) get_option( 'seedprod_maintenance_mode_page_id', 0 );
		}
		$this->fill(
			$m,
			array(
				'mode'            => $mm ? 'maintenance' : ( $soon ? 'coming_soon' : 'disabled' ),
				'headline'        => '',
				'body'            => '',
				'headline_colour' => '',
				'body_colour'     => '',
				'background'      => '',
				'roles'           => null,
				'page'            => $page > 0 && get_post( $page ) ? $page : 0,
			),
			'seedprod_settings'
		);
		$m->lossy( __( 'The page was designed in the SeedProd editor. The module shows that page as an existing page; check in the preview that it looks right. Access rules by role and the bypass link are not transferred.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * @param Mapping $m Mapping.
	 */
	private function map_maintenance( Mapping $m ): Mapping {
		$all = $this->opt( 'mtnc-options' );
		$o   = is_array( $all ) && isset( $all['options'] ) && is_array( $all['options'] ) ? $all['options'] : null;
		if ( null === $o ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$page = 'page' === (string) ( $o['mode'] ?? 'layout' ) ? (int) ( $o['mtnc_page'] ?? 0 ) : 0;
		$this->fill(
			$m,
			array(
				'mode'            => '1' === (string) ( $o['status'] ?? '0' ) ? 'maintenance' : 'disabled',
				'headline'        => (string) ( $o['header_text'] ?? '' ),
				'body'            => '',
				'headline_colour' => '',
				'body_colour'     => '',
				'background'      => '',
				'roles'           => null,
				'page'            => $page > 0 && get_post( $page ) ? $page : 0,
			),
			'mtnc-options'
		);
		$m->lossy( __( 'The layout, colours, countdown, passwords and per-address rules of the plugin are not transferred; only the on / off state and the headline.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * @param Mapping $m Mapping.
	 */
	private function map_ucp( Mapping $m ): Mapping {
		$o = $this->opt( 'ucp_options' );
		if ( ! is_array( $o ) ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$roles = array_values( array_filter( array_map( 'strval', (array) ( $o['whitelisted_roles'] ?? array( 'administrator' ) ) ) ) );
		$this->fill(
			$m,
			array(
				'mode'            => ! empty( $o['status'] ) ? 'maintenance' : 'disabled',
				'headline'        => (string) ( $o['heading1'] ?? '' ),
				'body'            => (string) ( $o['content'] ?? '' ),
				'headline_colour' => '',
				'body_colour'     => '',
				'background'      => '',
				'roles'           => $roles,
				'page'            => 0,
			),
			'ucp_options'
		);
		$m->lossy( __( 'The theme, social links, analytics code, custom CSS and the list of allowed users of the plugin are not transferred.', 'ux-studio' ) );
		return $m;
	}
}
