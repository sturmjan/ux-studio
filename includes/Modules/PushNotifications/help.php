<?php
/**
 * Plain-language help of the PushNotifications settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'vapid_subject' => array(
		'does  ' => __( 'A contact address (an e-mail with mailto: or a web address) that identifies your site to the browser push services. It is part of the [[VAPID]] signature sent with every notification.', 'ux-studio' ),
		'effect' => __( 'If a push service has a problem with your site, this is where it writes to.', 'ux-studio' ),
		'risk  ' => __( 'A wrong or unreachable address can make some push services refuse your notifications.', 'ux-studio' ),
		'off   ' => __( 'Leave it empty to use the e-mail of the site administrator.', 'ux-studio' ),
	),
	'auto_send_post_types' => array(
		'does  ' => __( 'The kinds of content (posts, pages, products...) whose first publication sends a notification.', 'ux-studio' ),
		'effect' => __( 'Only the chosen types notify subscribers. The rest is published quietly.', 'ux-studio' ),
		'risk  ' => __( 'Every newly published item of these types goes to every subscriber. A type that is published often (for example products imported in bulk) can flood people with notifications.', 'ux-studio' ),
	),
	'prompt_position' => array(
		'does  ' => __( 'Where on the screen the small window that asks for permission to send notifications appears.', 'ux-studio' ),
		'effect' => __( 'Pick a corner that does not cover your menu, cookie notice or chat button.', 'ux-studio' ),
	),
	'prompt_title' => array(
		'does  ' => __( 'The title of the permission window. Empty means the default text.', 'ux-studio' ),
		'effect' => __( 'Explain the value for the visitor ("Be the first to know"), not the technology.', 'ux-studio' ),
	),
	'prompt_text' => array(
		'does  ' => __( 'The explanation under the title. Empty means the default text.', 'ux-studio' ),
		'effect' => __( 'Say how often you write and what about. Honest expectations lead to fewer people blocking you.', 'ux-studio' ),
	),
	'prompt_btn_allow' => array(
		'does  ' => __( 'The label of the button that agrees to notifications.', 'ux-studio' ),
		'effect' => __( 'Pressing it opens the permission dialog of the browser itself.', 'ux-studio' ),
	),
	'prompt_btn_later' => array(
		'does  ' => __( 'The label of the button that closes the window without asking the browser.', 'ux-studio' ),
		'effect' => __( 'The visitor is asked again later. A choice made in the browser dialog itself would be remembered for good.', 'ux-studio' ),
	),
	'prompt_bg_color' => array(
		'does  ' => __( 'Background colour of the permission window.', 'ux-studio' ),
		'effect' => __( 'Pick a colour in line with your site and keep the text readable.', 'ux-studio' ),
	),
	'prompt_btn_color' => array(
		'does  ' => __( 'Colour of the allow button and the accent of the window.', 'ux-studio' ),
		'effect' => __( 'The allow button should stand out from the "later" button.', 'ux-studio' ),
	),
);
