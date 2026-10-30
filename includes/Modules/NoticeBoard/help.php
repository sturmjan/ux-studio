<?php
/**
 * Plain-language help of the NoticeBoard settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'per_page' => array(
		'does'   => __( 'How many notices are shown on one page of the shortcode and in the feed.', 'ux-studio' ),
		'effect' => __( 'A smaller number loads faster, a larger number needs less clicking.', 'ux-studio' ),
	),
);
