<?php
/**
 * Plain-language help of the User Last Login settings, read by Core\SettingHelp. Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'custom_format' => array(
		'does'   => __( 'The pattern uses PHP date letters. Common ones: d day, m month, Y year, H hour (00 to 23), i minute.', 'ux-studio' ),
		'effect' => __( 'The column shows the time exactly as the pattern says, for example "24. 10. 2026 14:35". An empty pattern falls back to the date and time of your site.', 'ux-studio' ),
	),
);
