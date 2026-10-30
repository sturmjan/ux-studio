<?php
/**
 * Source: WP Maintenance Mode, Coming Soon (SeedProd), Maintenance, Under Construction.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\IpEntries;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\MaintenanceMode\MaintenanceRules;

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
				$m->set( self::MODULE, 'headline_colour', $c['headline_colour'], __( 'Headline color', 'ux-studio' ), $origin );
			}
			if ( '' !== (string) $c['body_colour'] ) {
				$m->set( self::MODULE, 'body_colour', $c['body_colour'], __( 'Text color', 'ux-studio' ), $origin );
			}
			if ( '' !== (string) $c['background'] ) {
				$m->set( self::MODULE, 'background_colour', $c['background'], __( 'Background color', 'ux-studio' ), $origin );
			}
		}
		if ( null !== $c['roles'] ) {
			// Administrators always get through in the module, so they are not a role to list.
			$m->set( self::MODULE, 'auth_type', 'roles', __( 'Authentication type', 'ux-studio' ), $origin );
			$m->set( self::MODULE, 'access_roles', array_values( array_diff( $c['roles'], array( 'administrator' ) ) ), __( 'Allowed roles', 'ux-studio' ), $origin );
		}

		if ( empty( $c['page'] ) && '' !== (string) ( $c['bg_url'] ?? '' ) ) {
			$attachment = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( (string) $c['bg_url'] ) : 0;
			if ( $attachment > 0 ) {
				$m->set( self::MODULE, 'enable_background_image', true, __( 'Enable background image', 'ux-studio' ), $origin );
				$m->set( self::MODULE, 'background_image', $attachment, __( 'Background image', 'ux-studio' ), $origin );
			} else {
				$m->lossy(
					sprintf(
						/* translators: %s: address of the image. */
						__( 'The background image %s is not in the media library, so it cannot be used. Add it to the library and select it in the module.', 'ux-studio' ),
						(string) $c['bg_url']
					)
				);
			}
		}

		if ( ! empty( $c['end'] ) ) {
			$m->set( self::MODULE, 'countdown_end', (string) $c['end'], __( 'Countdown ends', 'ux-studio' ), $origin );
			$m->set( self::MODULE, 'countdown_enabled', ! empty( $c['countdown'] ), __( 'Show a countdown', 'ux-studio' ), $origin );
			$m->set( self::MODULE, 'countdown_auto_disable', ! empty( $c['auto_disable'] ), __( 'Open the site when the countdown ends', 'ux-studio' ), $origin );
		}

		if ( ! empty( $c['paths'] ) ) {
			$m->set( self::MODULE, 'exclude_paths', implode( "\n", $c['paths'] ), __( 'Addresses that stay open', 'ux-studio' ), $origin );
		}
		if ( ! empty( $c['ips'] ) ) {
			$entries = array();
			$bad     = array();
			foreach ( $c['ips'] as $ip ) {
				$converted = IpEntries::convert( (string) $ip );
				if ( $converted['ok'] && array() !== $converted['entries'] ) {
					$entries = array_merge( $entries, $converted['entries'] );
				} else {
					$bad[] = (string) $ip;
				}
			}
			if ( array() !== $entries ) {
				$m->set( self::MODULE, 'bypass_ips', implode( "\n", array_values( array_unique( $entries ) ) ), __( 'IP addresses that see the site', 'ux-studio' ), $origin );
			}
			if ( array() !== $bad ) {
				$m->lossy(
					sprintf(
						/* translators: %s: comma-separated list of entries. */
						__( 'These entries of the list of addresses are not valid IP addresses and are not transferred: %s.', 'ux-studio' ),
						implode( ', ', $bad )
					)
				);
			}
		}
		if ( 'disabled' !== $c['mode'] ) {
			$m->warn( __( 'The old plugin has maintenance or coming soon mode ON. Once the module is turned on, the site shows the maintenance page to visitors. Switch the old plugin off first and check the page in the preview.', 'ux-studio' ) );
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
		$mod    = (array) ( $o['modules'] ?? array() );
		$roles  = array_values( array_filter( array_map( 'strval', (array) ( $g['frontend_role'] ?? array() ) ) ) );
		$type   = (string) ( $d['bg_type'] ?? 'color' );
		$bg_ok  = 'color' === $type;

		// Countdown: the plugin counts from a start time over days / hours / minutes.
		$end = '';
		if ( ! empty( $mod['countdown_status'] ) ) {
			$det   = (array) ( $mod['countdown_details'] ?? array() );
			$start = MaintenanceRules::parse_end( (string) ( $mod['countdown_start'] ?? '' ) ?: (string) ( $g['status_date'] ?? '' ), wp_timezone() );
			if ( null !== $start ) {
				$end = $this->site_datetime( $start + (int) ( $det['days'] ?? 0 ) * 86400 + (int) ( $det['hours'] ?? 0 ) * 3600 + (int) ( $det['minutes'] ?? 0 ) * 60 );
			}
		}

		// The list of exceptions of the plugin matches a part of the IP address or of the address (path and query).
		$split = $this->split_exclude( (array) ( $g['exclude'] ?? array() ) );

		$this->fill(
			$m,
			array(
				'mode'            => ! empty( $g['status'] ) ? 'maintenance' : 'disabled',
				'headline'        => wp_strip_all_tags( (string) ( $d['heading'] ?? '' ) ),
				'body'            => (string) ( $d['text'] ?? '' ),
				'headline_colour' => (string) ( $d['heading_color'] ?? '' ),
				'body_colour'     => (string) ( $d['text_color'] ?? '' ),
				'background'      => $bg_ok ? (string) ( $d['bg_color'] ?? '' ) : '',
				'bg_url'          => 'custom' === $type ? (string) ( $d['bg_custom'] ?? '' ) : '',
				'roles'           => $roles,
				'page'            => 0,
				'end'             => $end,
				'countdown'       => '' !== $end,
				'auto_disable'    => false,
				'paths'           => $split['paths'],
				'ips'             => $split['ips'],
			),
			'wpmm_settings'
		);
		if ( 'predefined' === $type ) {
			$m->lossy( __( 'The ready-made background image of the plugin is not transferred. Select your own image in the module.', 'ux-studio' ) );
		}
		if ( '' !== $end ) {
			$m->note( __( 'The countdown of the plugin counts from its start time; it was read as the time of the site and turned into the end date.', 'ux-studio' ) );
		}
		if ( array() !== $split['paths'] ) {
			$m->note( __( 'The plugin lets through every address that contains one of the listed texts; each became a pattern with a star on both sides. The module looks at the path only, not at the query string.', 'ux-studio' ) );
		}
		$m->lossy( __( 'Subscribe form, social links, contact form, chat bot, GDPR notice, custom CSS, redirection and "let search engine bots in" of the plugin are not transferred.', 'ux-studio' ) );
		$m->note( __( 'Who may still see the site: roles from the plugin; administrators always can. With no role listed only administrators get through.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * A time of the site as the module stores it (`YYYY-MM-DDTHH:MM`).
	 *
	 * @param int $timestamp Unix time.
	 */
	private function site_datetime( int $timestamp ): string {
		return wp_date( 'Y-m-d\TH:i', $timestamp );
	}

	/**
	 * Split the exception list of WP Maintenance Mode into IP addresses and texts of addresses.
	 *
	 * @param array<int, mixed> $items Entries; a `#` starts a comment.
	 * @return array{paths:string[],ips:string[]}
	 */
	private function split_exclude( array $items ): array {
		$paths = array();
		$ips   = array();
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			$hash = strpos( $item, '#' );
			if ( false !== $hash ) {
				$item = trim( substr( $item, 0, $hash ) );
			}
			if ( '' === $item ) {
				continue;
			}
			if ( 1 === preg_match( '/^[0-9a-f:.\/*-]+$/i', $item ) && 1 === preg_match( '/[.:]/', $item ) && 1 === preg_match( '/\d/', $item ) ) {
				$ips[] = $item;
			} else {
				$paths[] = '*' . strtolower( trim( $item, '*' ) ) . '*';
			}
		}
		return array(
			'paths' => array_values( array_unique( $paths ) ),
			'ips'   => array_values( array_unique( $ips ) ),
		);
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
		// Access rules live in the settings of the page itself (JSON in post_content_filtered).
		$post     = $page > 0 ? get_post( $page ) : null;
		$settings = $post && ! empty( $post->post_content_filtered ) ? json_decode( (string) $post->post_content_filtered, true ) : null;
		$settings = is_array( $settings ) ? $settings : array();
		$roles    = null;
		$by_role  = array_values( array_filter( array_map( static fn( $v ): string => str_replace( ' ', '', strtolower( (string) $v ) ), (array) ( $settings['access_by_role'] ?? array() ) ) ) );
		if ( array() !== $by_role && ! in_array( 'anyoneloggedin', $by_role, true ) ) {
			$roles = $by_role;
		}
		$paths = ! empty( $settings['disable_default_excluded_urls'] ) ? array( 'regex:privacy|imprint|login|admin|dashboard|account' ) : array();

		$this->fill(
			$m,
			array(
				'mode'            => $mm ? 'maintenance' : ( $soon ? 'coming_soon' : 'disabled' ),
				'headline'        => '',
				'body'            => '',
				'headline_colour' => '',
				'body_colour'     => '',
				'background'      => '',
				'roles'           => $roles,
				'page'            => $post ? $page : 0,
				'paths'           => $paths,
			),
			'seedprod_settings'
		);
		if ( array() !== $paths ) {
			$m->note( __( 'The addresses the page leaves open (privacy, imprint, login, admin, dashboard, account) became one pattern of addresses that stay open. The plugin looks at the whole address, the module at the path.', 'ux-studio' ) );
		}
		$m->lossy( __( 'The page was designed in the SeedProd editor. The module shows that page as an existing page; check in the preview that it looks right. The bypass link, the redirect mode and the countdown block of the page are not transferred.', 'ux-studio' ) );
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

		// Planned end (used for the Retry-After header of the plugin) and the addresses that are never affected.
		$end = '';
		if ( ! empty( $o['expiry_date_end'] ) && ! empty( $o['expiry_time_end'] ) ) {
			// Date and time as typed in the plugin, i.e. in the time of the site.
			try {
				$when = new \DateTimeImmutable( trim( (string) $o['expiry_date_end'] ) . ' ' . trim( (string) $o['expiry_time_end'] ), wp_timezone() );
				$end  = $this->site_datetime( $when->getTimestamp() );
			} catch ( \Exception $e ) {
				$end = '';
			}
		}
		$paths = array();
		if ( 'whitelist' === (string) ( $o['per_url_settings'] ?? '' ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $o['per_url_enable_disable'] ?? '' ) ) ?: array() as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$paths[] = $line;
				}
			}
		}
		$ips = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $o['ip_whitelist'] ?? '' ) ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$ips[] = $line;
			}
		}

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
				'end'             => $end,
				'countdown'       => false,
				'auto_disable'    => false,
				'paths'           => $paths,
				'ips'             => $ips,
			),
			'mtnc-options'
		);
		if ( '' !== $end ) {
			$m->note( __( 'The planned end date of the plugin is used for the Retry-After header of the maintenance page; no countdown is shown (the plugin shows none in this version).', 'ux-studio' ) );
		}
		if ( 'blacklist' === (string) ( $o['per_url_settings'] ?? '' ) ) {
			$m->lossy( __( 'The plugin applies maintenance mode only to the listed addresses; the module cannot do that, it applies to the whole site except for the addresses that stay open. Nothing of this list was transferred.', 'ux-studio' ) );
		}
		$m->lossy( __( 'The layout, colors, background, passwords, the secret access link and the search engine rules of the plugin are not transferred; the on / off state, the headline, the planned end, the addresses that stay open and the IP addresses are.', 'ux-studio' ) );
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

		// The plugin switches itself off at the end date (`Y-m-d H:i` of the site), so the module is set to do the same.
		$end = '';
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) ( $o['end_date'] ?? '' ) ) && '0000-00-00 00:00' !== $o['end_date'] ) {
			$end = str_replace( ' ', 'T', (string) $o['end_date'] );
		}

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
				'end'             => $end,
				'countdown'       => false,
				'auto_disable'    => '' !== $end,
			),
			'ucp_options'
		);
		if ( '' !== $end ) {
			$m->note( __( 'The plugin ends the under construction page at its end date; the module is set to open the site then (the saved mode stays, it is only ignored after the end).', 'ux-studio' ) );
		}
		$m->lossy( __( 'The theme, social links, analytics code, custom CSS and the list of allowed users of the plugin are not transferred.', 'ux-studio' ) );
		return $m;
	}
}
