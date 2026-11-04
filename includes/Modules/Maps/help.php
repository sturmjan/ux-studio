<?php
/**
 * Plain-language help of the Maps settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'default_provider' => array(
		'does'   => __( 'Chooses who draws the map background for every map set to "Default". Pins stay in the same place whichever provider you pick.', 'ux-studio' ),
		'effect' => __( 'You can switch all maps of the site to another provider at once, without editing each map.', 'ux-studio' ),
		'risk'   => __( 'Mapy.com, Mapbox and Google need a key. Without it the website quietly shows OpenStreetMap instead.', 'ux-studio' ),
		'off'    => __( 'Keep OpenStreetMap when you do not want to manage any keys or accounts.', 'ux-studio' ),
	),
	'mapy_api_key'     => array(
		'does'   => __( 'The key from your Mapy.com developer account. It lets the site load Czech tourist, aerial and winter maps and search addresses.', 'ux-studio' ),
		'effect' => __( 'Maps get the detailed Czech map background visitors know from Mapy.com, and address search in the editor finds Czech addresses reliably.', 'ux-studio' ),
		'risk'   => __( 'The key is visible in the page code, because the visitor\'s browser loads the map tiles with it. Limit it to your domain in the Mapy.com account and keep an eye on the free monthly limit.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you use another provider.', 'ux-studio' ),
	),
	'mapbox_token'     => array(
		'does'   => __( 'The public access token from your Mapbox account. It unlocks Mapbox styles such as the dark or outdoor map.', 'ux-studio' ),
		'effect' => __( 'Maps can match the look of the site, for example a dark map on a dark page.', 'ux-studio' ),
		'risk'   => __( 'Use only a public token (pk.), never a secret one (sk.). It is visible in the page code, so restrict it to your site\'s address in Mapbox.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you use another provider.', 'ux-studio' ),
	),
	'google_api_key'   => array(
		'does'   => __( 'The Google Cloud key with the Maps JavaScript API enabled. It lets the site show maps drawn by Google.', 'ux-studio' ),
		'effect' => __( 'Visitors see the familiar Google map with satellite and terrain views.', 'ux-studio' ),
		'risk'   => __( 'Google bills map loads above the free monthly credit. Restrict the key to your domain and set a budget alert in Google Cloud, otherwise a copied key can cost money.', 'ux-studio' ),
		'off'    => __( 'Leave it empty when you do not need Google specifically. OpenStreetMap and Mapy.com cost nothing for a normal site.', 'ux-studio' ),
	),
	'google_map_id'    => array(
		'does'   => __( 'The ID of a map style created in Google Cloud. With it Google uses its newer pins in the color of each pin and your cloud style.', 'ux-studio' ),
		'effect' => __( 'Google maps look the way you designed them in Google Cloud instead of the default style.', 'ux-studio' ),
		'off'    => __( 'Leave it empty and Google maps use the standard look with the pin colors from this module.', 'ux-studio' ),
	),
	'default_color'    => array(
		'does'   => __( 'The color a new pin gets. Each pin can still have its own color in the editor.', 'ux-studio' ),
		'effect' => __( 'Pins match the colors of the site without setting each one by hand.', 'ux-studio' ),
	),
	'default_height'   => array(
		'does'   => __( 'The height a new map starts with. The width always fills the space where the map is placed.', 'ux-studio' ),
		'effect' => __( 'New maps fit the layout of the site from the start. Existing maps keep their own height.', 'ux-studio' ),
		'risk'   => __( 'A very tall map on a phone covers the whole screen and is hard to scroll past.', 'ux-studio' ),
	),
);
