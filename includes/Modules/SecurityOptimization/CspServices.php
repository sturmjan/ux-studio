<?php
/**
 * Catalogue of known third-party services for the CSP builder and risk rating.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * One place that knows which external hosts are legitimate, widely used
 * services: the service presets of the policy builder (checkbox list in the
 * settings) and a wider catalogue used only to label inventory rows. A host
 * from the catalogue is "known", not "trusted" - it still has to be approved
 * before it lands in the policy.
 */
final class CspServices {

	/**
	 * Source allowlist for a single service preset key.
	 *
	 * @return array<string,array<int,string>> Directive => sources.
	 */
	public static function preset_sources( string $preset ): array {
		$map = array(
			'google_fonts'   => array(
				'style-src' => array( 'https://fonts.googleapis.com' ),
				'font-src'  => array( 'https://fonts.gstatic.com' ),
			),
			'gtm_ga'         => array(
				'script-src'  => array( 'https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://ssl.google-analytics.com' ),
				'img-src'     => array( 'https://www.googletagmanager.com', 'https://www.google-analytics.com' ),
				'connect-src' => array( 'https://www.google-analytics.com', 'https://*.analytics.google.com', 'https://*.g.doubleclick.net', 'https://www.googletagmanager.com' ),
			),
			'youtube'        => array(
				'frame-src'  => array( 'https://www.youtube.com', 'https://www.youtube-nocookie.com' ),
				'script-src' => array( 'https://www.youtube.com', 'https://s.ytimg.com' ),
				'img-src'    => array( 'https://i.ytimg.com' ),
			),
			'vimeo'          => array(
				'frame-src'  => array( 'https://player.vimeo.com' ),
				'script-src' => array( 'https://player.vimeo.com' ),
				'img-src'    => array( 'https://i.vimeocdn.com' ),
			),
			'smartsupp'      => array(
				'script-src'  => array( 'https://www.smartsuppchat.com', 'https://*.smartsupp.com' ),
				'connect-src' => array( 'https://*.smartsupp.com', 'wss://*.smartsupp.com' ),
				'img-src'     => array( 'https://*.smartsupp.com' ),
				'frame-src'   => array( 'https://*.smartsupp.com' ),
			),
			'recaptcha'      => array(
				'script-src'  => array( 'https://www.google.com', 'https://www.gstatic.com' ),
				'frame-src'   => array( 'https://www.google.com' ),
				'connect-src' => array( 'https://www.google.com' ),
			),
			'cloudflare'     => array(
				'script-src'  => array( 'https://*.cloudflare.com', 'https://*.cloudfront.net' ),
				'style-src'   => array( 'https://*.cloudflare.com' ),
				'connect-src' => array( 'https://*.cloudflare.com' ),
			),
			'mapy_cz'        => array(
				'script-src'  => array( 'https://api.mapy.cz' ),
				'style-src'   => array( 'https://api.mapy.cz' ),
				'img-src'     => array( 'https://api.mapy.cz', 'https://mapy.cz', 'https://*.mapy.cz' ),
				'connect-src' => array( 'https://api.mapy.cz', 'https://*.mapy.cz' ),
			),
			'google_maps'    => array(
				'script-src'  => array( 'https://maps.googleapis.com', 'https://maps.gstatic.com' ),
				'style-src'   => array( 'https://fonts.googleapis.com' ),
				'img-src'     => array( 'https://maps.googleapis.com', 'https://maps.gstatic.com', 'https://*.googleusercontent.com' ),
				'connect-src' => array( 'https://maps.googleapis.com' ),
			),
			'facebook_pixel' => array(
				'script-src'  => array( 'https://connect.facebook.net' ),
				'img-src'     => array( 'https://www.facebook.com' ),
				'connect-src' => array( 'https://www.facebook.com' ),
			),
			'gravatar'       => array(
				'img-src' => array( 'https://secure.gravatar.com', 'https://*.gravatar.com' ),
			),
		);
		return $map[ $preset ] ?? array();
	}

	/**
	 * Preset keys in display order.
	 *
	 * @return array<int,string>
	 */
	public static function preset_keys(): array {
		return array( 'google_fonts', 'gtm_ga', 'youtube', 'vimeo', 'smartsupp', 'recaptcha', 'cloudflare', 'mapy_cz', 'google_maps', 'facebook_pixel', 'gravatar' );
	}

	/**
	 * Registrable domains of well-known services => human label. A host
	 * matches when it equals the domain or is its subdomain. Kept to
	 * registrable domains on purpose: the typosquat check compares against
	 * exactly these.
	 *
	 * @return array<string,string>
	 */
	public static function catalogue(): array {
		return array(
			// Google.
			'google.com'              => 'Google',
			'google.cz'               => 'Google',
			'gstatic.com'             => 'Google (gstatic)',
			'googleapis.com'          => 'Google APIs / Fonts',
			'googletagmanager.com'    => 'Google Tag Manager',
			'google-analytics.com'    => 'Google Analytics',
			'analytics.google.com'    => 'Google Analytics',
			'doubleclick.net'         => 'Google Ads',
			'googlesyndication.com'   => 'Google AdSense',
			'googleadservices.com'    => 'Google Ads',
			'googleusercontent.com'   => 'Google (user content)',
			'youtube.com'             => 'YouTube',
			'youtube-nocookie.com'    => 'YouTube',
			'ytimg.com'               => 'YouTube',
			'recaptcha.net'           => 'Google reCAPTCHA',
			// Meta, other social.
			'facebook.net'            => 'Facebook',
			'facebook.com'            => 'Facebook',
			'fbcdn.net'               => 'Facebook',
			'instagram.com'           => 'Instagram',
			'cdninstagram.com'        => 'Instagram',
			'linkedin.com'            => 'LinkedIn',
			'licdn.com'               => 'LinkedIn',
			'twitter.com'             => 'X (Twitter)',
			'x.com'                   => 'X (Twitter)',
			'twimg.com'               => 'X (Twitter)',
			'tiktok.com'              => 'TikTok',
			'pinterest.com'           => 'Pinterest',
			'pinimg.com'              => 'Pinterest',
			// Analytics / marketing.
			'clarity.ms'              => 'Microsoft Clarity',
			'bing.com'                => 'Microsoft Bing',
			'hotjar.com'              => 'Hotjar',
			'hotjar.io'               => 'Hotjar',
			'matomo.cloud'            => 'Matomo',
			'mailchimp.com'           => 'Mailchimp',
			'list-manage.com'         => 'Mailchimp',
			'chimpstatic.com'         => 'Mailchimp',
			'ecomailapp.cz'           => 'Ecomail',
			'smartemailing.cz'        => 'SmartEmailing',
			'leadhub.co'              => 'Leadhub',
			// CDNs.
			'jquery.com'              => 'jQuery CDN',
			'jsdelivr.net'            => 'jsDelivr CDN',
			'cloudflare.com'          => 'Cloudflare',
			'cloudflareinsights.com'  => 'Cloudflare Web Analytics',
			'cloudfront.net'          => 'Amazon CloudFront',
			'unpkg.com'               => 'unpkg CDN',
			'bootstrapcdn.com'        => 'Bootstrap CDN',
			'fontawesome.com'         => 'Font Awesome',
			'typekit.net'             => 'Adobe Fonts',
			'wp.com'                  => 'WordPress.com (Jetpack)',
			'wordpress.com'           => 'WordPress.com',
			'w.org'                   => 'WordPress.org',
			'gravatar.com'            => 'Gravatar',
			// Consent, chat, captcha.
			'cookiebot.com'           => 'Cookiebot',
			'cookie-script.com'       => 'Cookie Script',
			'onetrust.com'            => 'OneTrust',
			'cookielaw.org'           => 'OneTrust',
			'smartsupp.com'           => 'Smartsupp',
			'smartsuppchat.com'       => 'Smartsupp',
			'smartsuppcdn.com'        => 'Smartsupp',
			'tawk.to'                 => 'Tawk.to',
			'tidio.co'                => 'Tidio',
			'tidiochat.com'           => 'Tidio',
			'crisp.chat'              => 'Crisp',
			'intercom.io'             => 'Intercom',
			'hcaptcha.com'            => 'hCaptcha',
			// Video, maps.
			'vimeo.com'               => 'Vimeo',
			'vimeocdn.com'            => 'Vimeo',
			'mapy.cz'                 => 'Mapy.cz',
			'mapy.com'                => 'Mapy.com',
			'openstreetmap.org'       => 'OpenStreetMap',
			// Payments, e-commerce.
			'stripe.com'              => 'Stripe',
			'stripe.network'          => 'Stripe',
			'paypal.com'              => 'PayPal',
			'paypalobjects.com'       => 'PayPal',
			'gopay.com'               => 'GoPay',
			'gopay.cz'                => 'GoPay',
			'comgate.cz'              => 'Comgate',
			'thepay.cz'               => 'ThePay',
			'packeta.com'             => 'Packeta (Zásilkovna)',
			'zasilkovna.cz'           => 'Zásilkovna',
			'ppl.cz'                  => 'PPL',
			'heureka.cz'              => 'Heureka',
			'heureka.sk'              => 'Heureka',
			'zbozi.cz'                => 'Zboží.cz',
			'seznam.cz'               => 'Seznam',
			'szn.cz'                  => 'Seznam',
			'imedia.cz'               => 'Seznam (ads)',
			'trustpilot.com'          => 'Trustpilot',
			'booking.com'             => 'Booking.com',
			'previo.cz'               => 'Previo',
			// Own services.
			'ux1.cz'                  => 'UX1',
		);
	}

	/**
	 * Service label for a host from the catalogue, or '' when unknown.
	 */
	public static function label_for_host( string $host ): string {
		$host = strtolower( trim( $host, ". \t\n\r\0\x0B" ) );
		if ( '' === $host ) {
			return '';
		}
		foreach ( self::catalogue() as $domain => $label ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Preset key whose sources cover a host for a given directive, or ''.
	 * Used when building the policy from the inventory: a host covered by a
	 * preset is better enabled as that preset (all its directives) than as a
	 * one-off source.
	 */
	public static function preset_for( string $host, string $directive ): string {
		$host      = strtolower( $host );
		$directive = CspPolicy::base_directive( $directive );
		foreach ( self::preset_keys() as $preset ) {
			foreach ( self::preset_sources( $preset )[ $directive ] ?? array() as $source ) {
				if ( self::source_matches_host( $source, $host ) ) {
					return $preset;
				}
			}
		}
		return '';
	}

	/**
	 * Does a CSP host source (https://cdn.example.com, https://*.example.com)
	 * cover a host name?
	 */
	public static function source_matches_host( string $source, string $host ): bool {
		$source_host = strtolower( (string) preg_replace( '#^[a-z]+://#i', '', $source ) );
		$source_host = (string) preg_replace( '#[:/].*$#', '', $source_host );
		if ( '' === $source_host ) {
			return false;
		}
		if ( str_starts_with( $source_host, '*.' ) ) {
			return str_ends_with( $host, substr( $source_host, 1 ) );
		}
		return $host === $source_host;
	}
}
