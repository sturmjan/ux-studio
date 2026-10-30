<?php
/**
 * Plain-language help of the OpeningHours settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'schema_enabled' => array(
		'does'   => __( 'Adds [[JSON-LD]] data in the [[Schema.org]] format to the page: your address and opening hours in a form that search engines read.', 'ux-studio' ),
		'effect' => __( 'Google can show your hours directly in search results and on maps.', 'ux-studio' ),
		'risk'   => __( 'The data must match what you really show on the page. Wrong hours in search results annoy customers and can lead to the data being ignored. Another SEO plugin may print the same data, then you get it twice.', 'ux-studio' ),
		'off'    => __( 'Turn it off if another plugin already prints this data.', 'ux-studio' ),
	),
	'schema_on_homepage' => array(
		'does'   => __( 'Prints the structured data of your locations on the home page.', 'ux-studio' ),
		'effect' => __( 'Search engines connect the opening hours with your main page.', 'ux-studio' ),
	),
	'schema_location_ids' => array(
		'does'   => __( 'Limits the structured data to the locations with these numbers (separated by commas). Empty means all locations.', 'ux-studio' ),
		'effect' => __( 'Use it when some locations should not appear in search results, for example a closed one.', 'ux-studio' ),
		'risk'   => __( 'A number of a location that does not exist is skipped. A typo can leave a location out.', 'ux-studio' ),
	),
	'severity_info_color' => array(
		'does'   => __( 'Color of the announcement banner of the Info level.', 'ux-studio' ),
		'effect' => __( 'Visitors see the color with the announcement, for example a note about a changed schedule.', 'ux-studio' ),
	),
	'severity_warning_color' => array(
		'does'   => __( 'Color of the announcement banner of the Warning level.', 'ux-studio' ),
		'effect' => __( 'Use it for things visitors should notice, such as a shorter day.', 'ux-studio' ),
	),
	'severity_critical_color' => array(
		'does'   => __( 'Color of the announcement banner of the Critical level.', 'ux-studio' ),
		'effect' => __( 'Use it for closures, so nobody travels to a closed place.', 'ux-studio' ),
	),
);
