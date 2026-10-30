<?php
/**
 * Plain-language help of the Download Files settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'enabled_post_types'    => array(
		'does'   => __( 'On the chosen content types the editor shows a box where you attach files for download.', 'ux-studio' ),
		'effect' => __( 'Only these types can have a list of files to download.', 'ux-studio' ),
	),
	'auto_append'           => array(
		'does'   => __( 'The list of files attached to a post is added below its content automatically.', 'ux-studio' ),
		'effect' => __( 'You do not have to place the shortcode by hand. A post that already contains the shortcode does not get the list twice.', 'ux-studio' ),
		'off'    => __( 'Turn it off when you want to place the list yourself with the shortcode.', 'ux-studio' ),
	),
	'require_login_default' => array(
		'does'   => __( 'Newly added files are marked as available only to logged-in users.', 'ux-studio' ),
		'effect' => __( 'Such files are moved out of the public uploads folder. Their original media address stops working and they can be downloaded only through the download link, and only after logging in.', 'ux-studio' ),
		'risk'   => __( 'Links to the original media address of a moved file that you published earlier no longer work.', 'ux-studio' ),
		'off'    => __( 'Turn it off when most of your files are public.', 'ux-studio' ),
	),
	'heading_text'          => array(
		'does'   => __( 'The heading shown above the list of files. You can change it for a single list with the heading attribute of the shortcode.', 'ux-studio' ),
		'effect' => __( 'Leave it empty to show lists without a heading.', 'ux-studio' ),
	),
	'hide_login_required'   => array(
		'does'   => __( 'Visitors who are not logged in do not see files that need a login in the list at all.', 'ux-studio' ),
		'effect' => __( 'The visitors are not teased with files they cannot open. The download itself is protected whatever you choose here.', 'ux-studio' ),
		'off'    => __( 'Turn it off if you want visitors to see that such files exist and then ask them to log in.', 'ux-studio' ),
	),
);
