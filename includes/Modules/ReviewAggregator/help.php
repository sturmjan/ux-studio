<?php
/**
 * Plain-language help of the ReviewAggregator settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'order_by' => array(
		'does  ' => __( 'Chooses which reviews come first in the list: the newest, the oldest or the best rated.', 'ux-studio' ),
		'effect' => __( 'Visitors see the top of the list first. Best rating shows your strongest reviews, newest shows that the site is alive.', 'ux-studio' ),
	),
	'show_platform' => array(
		'does  ' => __( 'Shows where the review came from (Google, Mapy.cz...) next to it.', 'ux-studio' ),
		'effect' => __( 'Visitors trust a review more when they see the source.', 'ux-studio' ),
	),
	'show_date' => array(
		'does  ' => __( 'Shows the date of the review.', 'ux-studio' ),
		'effect' => __( 'A recent date tells that the review is current. Old dates can work against you.', 'ux-studio' ),
	),
	'show_avatar' => array(
		'does  ' => __( 'Shows the profile picture of the author when the platform provides it.', 'ux-studio' ),
		'effect' => __( 'Makes the list look more personal.', 'ux-studio' ),
		'risk  ' => __( 'The pictures are loaded from the platform, so the visitor browser contacts it. Turn it off if you want to avoid that.', 'ux-studio' ),
	),
	'show_stars' => array(
		'does  ' => __( 'Shows the star rating of each review.', 'ux-studio' ),
		'effect' => __( 'Visitors read the rating at a glance.', 'ux-studio' ),
	),
	'auto_fetch' => array(
		'does  ' => __( 'Downloads new reviews from the central app on a schedule, without you doing anything.', 'ux-studio' ),
		'effect' => __( 'The list on your site stays current.', 'ux-studio' ),
		'risk  ' => __( 'Without it new reviews appear only when you press the button to load them.', 'ux-studio' ),
		'off   ' => __( 'Turn it off to freeze the reviews on the site as they are now.', 'ux-studio' ),
	),
);
