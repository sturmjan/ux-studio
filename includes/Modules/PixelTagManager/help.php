<?php
/**
 * Plain-language help of the PixelTagManager settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'google_tag_manager' => array(
		'does'   => __( 'Your Google Tag Manager container ID (it starts with GTM-). The container script is added to the head of every page and a noscript fallback right after the opening body tag.', 'ux-studio' ),
		'effect' => __( 'All tags you set up in Tag Manager (analytics, ads, conversions) start working on the site without touching the code again.', 'ux-studio' ),
		'risk'   => __( 'A container can fire any tag, so it needs consent under the cookie rules and a mention in your privacy policy. Do not enter the same container here and in another plugin (for example GTM Kit), and do not put the same Google Analytics ID in both Tag Manager and the field below, visits would be counted twice.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you do not use Google Tag Manager.', 'ux-studio' ),
	),
	'google_analytics' => array(
		'does'   => __( 'Your Google Analytics measurement ID (it starts with G-). The tracking script is added to every page of the site.', 'ux-studio' ),
		'effect' => __( 'You see visits, sources and behaviour in your Google Analytics account.', 'ux-studio' ),
		'risk'   => __( 'This tracks visitors, so you need their consent under the cookie rules (GDPR, ePrivacy) and a mention in your privacy policy. Do not enter the same ID here and in another analytics plugin, visits would be counted twice.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you do not use Google Analytics.', 'ux-studio' ),
	),
	'facebook_pixel' => array(
		'does'   => __( 'Your numeric Facebook (Meta) Pixel ID. The Pixel script is added to every page.', 'ux-studio' ),
		'effect' => __( 'Facebook can measure ads and build audiences from your visitors.', 'ux-studio' ),
		'risk'   => __( 'The Pixel sends visitor data to Meta, so it needs consent under the cookie rules and a mention in your privacy policy. A Pixel that fires before consent is a legal risk.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you do not run Facebook or Instagram ads.', 'ux-studio' ),
	),
	'pinterest_tag' => array(
		'does'   => __( 'Your numeric Pinterest Tag ID. The tag script is added to every page.', 'ux-studio' ),
		'effect' => __( 'Pinterest can measure ads and conversions from your visitors.', 'ux-studio' ),
		'risk'   => __( 'The tag sends visitor data to Pinterest, so it needs consent under the cookie rules and a mention in your privacy policy.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you do not advertise on Pinterest.', 'ux-studio' ),
	),
);
