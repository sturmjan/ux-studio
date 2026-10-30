<?php
/**
 * Plain-language help of the TopBar settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'bg_color' => array(
		'does  ' => __( 'Background colour of the bar at the top of every page.', 'ux-studio' ),
		'effect' => __( 'Pick a colour that is visible but does not fight with your header.', 'ux-studio' ),
	),
	'text_color' => array(
		'does  ' => __( 'Colour of the text and links in the bar.', 'ux-studio' ),
		'effect' => __( 'Needs strong contrast with the background colour, otherwise the bar cannot be read.', 'ux-studio' ),
	),
	'schedule_publish' => array(
		'does  ' => __( 'The bar is hidden until this date and time (time of the site). Empty means it is shown right away.', 'ux-studio' ),
		'effect' => __( 'Prepare an announcement in advance and let it appear on its own.', 'ux-studio' ),
	),
	'schedule_deactivate' => array(
		'does  ' => __( 'The bar disappears at this date and time (time of the site). Empty means it stays until you remove it.', 'ux-studio' ),
		'effect' => __( 'A sale or an event notice does not stay up after it is over.', 'ux-studio' ),
	),
);
