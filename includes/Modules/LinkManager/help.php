<?php
/**
 * Plain-language help of the LinkManager settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'icon_dashicon' => array(
		'does'   => __( 'Chooses the little icon marking external links from the Dashicons set of WordPress.', 'ux-studio' ),
		'effect' => __( 'Visitors recognise that the link leads to another website.', 'ux-studio' ),
	),
	'icon_position' => array(
		'does'   => __( 'Puts the icon of external links before or after the link text.', 'ux-studio' ),
		'effect' => __( 'After the text is the usual place, so the text lines stay aligned.', 'ux-studio' ),
	),
	'disable_speculative_loading' => array(
		'does'   => __( 'Turns off the WordPress feature that loads pages in the background before the visitor clicks them (speculative loading).', 'ux-studio' ),
		'effect' => __( 'With the turn on nothing is loaded ahead of time. Pages open a little slower, but the server and the data of visitors are spared.', 'ux-studio' ),
		'risk'   => __( 'Pages that count visits or change something when opened can be triggered by the preloading. That is the usual reason to switch the feature off.', 'ux-studio' ),
		'off'    => __( 'Leave it off for most sites, the feature makes browsing faster. Switch it on only when it causes trouble.', 'ux-studio' ),
	),
	'speculative_loading_mode' => array(
		'does'   => __( 'Chooses how pages are loaded ahead: Prefetch downloads only the page, Prerender prepares it fully in the background, Auto keeps the WordPress default.', 'ux-studio' ),
		'effect' => __( 'Prerender gives the fastest opening, Prefetch is lighter.', 'ux-studio' ),
		'risk'   => __( 'Prerender runs the scripts of the page before it is opened, so statistics and cookies can fire without a real visit. Exclude pages like the cart in the lists below.', 'ux-studio' ),
	),
	'speculative_loading_eagerness' => array(
		'does'   => __( 'Chooses how early the browser starts loading a link: Conservative on click, Moderate when the pointer rests on it, Eager as soon as the link is visible.', 'ux-studio' ),
		'effect' => __( 'More eager means faster pages, but more pages are loaded that nobody opens.', 'ux-studio' ),
		'risk'   => __( 'The most eager level can load many pages and put load on the server.', 'ux-studio' ),
	),
	'exclude_paths' => array(
		'does'   => __( 'Addresses that are never loaded ahead, one per line. A star stands for any ending, for example /cart/*.', 'ux-studio' ),
		'effect' => __( 'Protects pages whose opening has an effect, such as the cart, the checkout or a logout link.', 'ux-studio' ),
		'risk'   => __( 'Leave the cart, checkout, login and logout out of the preloading, otherwise they may run without the visitor wanting it.', 'ux-studio' ),
	),
	'exclude_prerender_paths' => array(
		'does'   => __( 'Addresses that may be downloaded ahead, but never fully prepared (prerendered). One per line.', 'ux-studio' ),
		'effect' => __( 'Pages with personal or changing content stay safe from early running of their scripts.', 'ux-studio' ),
	),
);
