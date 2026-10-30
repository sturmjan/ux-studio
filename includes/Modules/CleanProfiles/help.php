<?php
/**
 * Plain-language help of the Clean Profiles settings, read by Core\SettingHelp. Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'sections' => array(
		'does'   => __( 'Hides whole blocks of the user profile screen, for example the color scheme or the application passwords.', 'ux-studio' ),
		'effect' => __( 'Users see a shorter profile with only what they need. The data stays stored and keeps working, it is only not shown.', 'ux-studio' ),
		'risk'   => __( 'If you hide a block that people still need (for example the password section), they cannot change it on their own.', 'ux-studio' ),
	),
);
