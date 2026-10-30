<?php
/**
 * Plain-language help of the Admin Customiser settings, read by Core\SettingHelp. Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'admin_footer_text'             => array(
		'does'   => __( 'Replaces the small line at the bottom left of every admin screen ("Thank you for creating with WordPress").', 'ux-studio' ),
		'effect' => __( 'Everyone who works in the admin sees your text instead, for example a link to your support. Basic formatting and links are allowed. Leave it empty to keep the WordPress line.', 'ux-studio' ),
	),
	'hide_footer_version'           => array(
		'does'   => __( 'Removes the WordPress version number from the bottom right of every admin screen.', 'ux-studio' ),
		'effect' => __( 'Editors no longer see which version runs and the footer looks cleaner. The version stays visible on the Updates screen.', 'ux-studio' ),
	),
	'hide_screen_options'           => array(
		'does'   => __( 'Hides the "Screen Options" tab at the top right of the admin screens.', 'ux-studio' ),
		'effect' => __( 'Nobody can change which columns and boxes a screen shows or how many items a list has per page. Choices already saved stay in force.', 'ux-studio' ),
		'risk'   => __( 'You hide it from yourself too. Turn this off when you need to change a list.', 'ux-studio' ),
	),
	'hide_help_tab'                 => array(
		'does'   => __( 'Hides the "Help" tab at the top right of the admin screens.', 'ux-studio' ),
		'effect' => __( 'The built-in WordPress help panels are not offered any more. The screens work as before.', 'ux-studio' ),
		'off'    => __( 'Leave it off when your editors use the built-in help.', 'ux-studio' ),
	),
	'login_customization_enabled'   => array(
		'does'   => __( 'Turns on the settings of the login page below: layout, logo, texts and colors.', 'ux-studio' ),
		'effect' => __( 'The login, lost password and registration screens get your look. Turned off, WordPress shows its own login page and the settings below are kept but not used.', 'ux-studio' ),
		'risk'   => __( 'Light text on a light background (or the reverse) makes the form hard to read. Check the page in a private window after each change.', 'ux-studio' ),
	),
	'login_logo'                    => array(
		'does'   => __( 'The image shown above the login form instead of the WordPress logo.', 'ux-studio' ),
		'effect' => __( 'People see your brand on the login page. In the split layout the logo can also appear over the media panel.', 'ux-studio' ),
		'off'    => __( 'Leave it empty to show the WordPress logo, or hide the logo with the login form options.', 'ux-studio' ),
	),
	'form_options'                  => array(
		'does'   => __( 'Extra switches for the login form. "Hide login logo" removes the logo above the form completely.', 'ux-studio' ),
		'effect' => __( 'The form starts straight with the fields, which suits a page where the logo is already part of the background.', 'ux-studio' ),
	),
	'login_remember_me_default'     => array(
		'does'   => __( 'Ticks the "Remember me" box on the login form in advance.', 'ux-studio' ),
		'effect' => __( 'People stay signed in for two weeks instead of until they close the browser, unless they untick the box.', 'ux-studio' ),
		'risk'   => __( 'On a shared computer someone may forget to untick it and leave their account open.', 'ux-studio' ),
	),
	'login_form_border_radius'      => array(
		'does'   => __( 'How round the corners of the login box are, in pixels.', 'ux-studio' ),
		'effect' => __( '0 gives sharp corners, 8 to 16 a soft look, a large number a very round box.', 'ux-studio' ),
	),
	'login_background_style'        => array(
		'does'   => __( 'How the background image of the classic login layout fills the screen.', 'ux-studio' ),
		'effect' => __( '"Cover" fills the whole screen and may crop the edges. "Fit inside" shows the whole picture and leaves bands of the background color. "Repeat" tiles a small picture as a pattern.', 'ux-studio' ),
	),
	'login_split_media_type'        => array(
		'does'   => __( 'Chooses what the panel next to the login form shows in the split layout: an MP4 video, a picture or a YouTube video.', 'ux-studio' ),
		'effect' => __( 'The field for the chosen type appears below. A video plays without sound in a loop.', 'ux-studio' ),
		'risk'   => __( 'A large video slows down the login page, especially on a phone. Keep it short and small, or use a picture.', 'ux-studio' ),
	),
	'login_split_media_youtube_url' => array(
		'does'   => __( 'The YouTube video that plays in the media panel. Paste the address of the video or only its ID.', 'ux-studio' ),
		'effect' => __( 'The video plays muted in a loop next to the form.', 'ux-studio' ),
		'risk'   => __( 'The login page loads content from YouTube, so YouTube can see the visitors of the login page.', 'ux-studio' ),
	),
	'login_split_show_logo'         => array(
		'does'   => __( 'Draws the login logo over the media panel in the split layout.', 'ux-studio' ),
		'effect' => __( 'The logo is shown once, over the picture or video. Turned off, it sits as a small round badge above the form.', 'ux-studio' ),
	),
	'admin_bar_enabled'             => array(
		'does'   => __( 'Turns on the tidy-up of the bar at the top of the screen. The options under it only work while this is on.', 'ux-studio' ),
		'effect' => __( 'The bar gets shorter: updates move to the right, the site name becomes an icon and plugin items fold under a plus.', 'ux-studio' ),
		'off'    => __( 'Turn it off to get the WordPress bar back as it was. The settings below are kept.', 'ux-studio' ),
	),
	'admin_bar_logo'                => array(
		'does'   => __( 'Your own image in place of the WordPress logo at the left end of the bar.', 'ux-studio' ),
		'effect' => __( 'The bar shows your brand. A small square image works best, a large one is scaled down.', 'ux-studio' ),
	),
	'hide_admin_bar_elements'       => array(
		'does'   => __( 'Hides the chosen items of the bar, for example the WordPress logo, the comments or the "New" menu.', 'ux-studio' ),
		'effect' => __( 'Fewer items for everybody who sees the bar.', 'ux-studio' ),
		'risk'   => __( 'Hiding the Updates item means people no longer see the badge about pending updates in the bar.', 'ux-studio' ),
	),
	'move_updates_right'            => array(
		'does'   => __( 'Moves the items with the number of updates and comments from the left part of the bar to the right.', 'ux-studio' ),
		'effect' => __( 'The left side keeps only what you use to navigate, the notifications sit next to your account on the right.', 'ux-studio' ),
	),
	'consolidate_plugin_actions'    => array(
		'does'   => __( 'Folds the items that plugins add to the bar into one "+" menu.', 'ux-studio' ),
		'effect' => __( 'The bar stays short even with many plugins. Items you list as "keep on the bar" stay visible.', 'ux-studio' ),
		'risk'   => __( 'A plugin item you need often is one click further, and counters that plugins show in the bar are hidden. Add such an item to the keep list.', 'ux-studio' ),
	),
	'move_site_link_right'          => array(
		'does'   => __( 'Shows the site name in the bar as a small link icon on the right instead of a long text on the left.', 'ux-studio' ),
		'effect' => __( 'More room on the left. The link still opens the front page of the site.', 'ux-studio' ),
	),
	'hide_sidebar_icons'            => array(
		'does'   => __( 'Hides the icons in front of the items of the left menu.', 'ux-studio' ),
		'effect' => __( 'The menu shows only text. The icon color setting is not used while the icons are hidden.', 'ux-studio' ),
	),
	'sidebar_width'                 => array(
		'does'   => __( 'The width of the left menu in pixels. WordPress uses 160, you can set 120 to 300.', 'ux-studio' ),
		'effect' => __( 'A wider menu fits long item names on one line, a narrower one leaves more room for the content.', 'ux-studio' ),
		'risk'   => __( 'With a very narrow menu long names are cut or wrapped.', 'ux-studio' ),
	),
	'icon_replacer_enabled'         => array(
		'does'   => __( 'Draws a modern set of line icons in place of the old WordPress menu icons.', 'ux-studio' ),
		'effect' => __( 'The menu looks more uniform. Icons without a replacement stay as they were, so nothing goes missing.', 'ux-studio' ),
	),
	'hide_notices_enabled'          => array(
		'does'   => __( 'Takes the notices of WordPress and plugins away from the top of the admin screens.', 'ux-studio' ),
		'effect' => __( 'The screens stay clean. By default the notices move to a tray instead of disappearing, and the history tab keeps what was shown.', 'ux-studio' ),
		'risk'   => __( 'Important warnings (a failed backup, an expired licence) may be overlooked. Look into the tray and the history from time to time.', 'ux-studio' ),
	),
	'quick_search_enabled'          => array(
		'does'   => __( 'Adds a search field to the top of the admin that finds posts, pages and other content from any screen.', 'ux-studio' ),
		'effect' => __( 'Editors jump to an item without opening its list first.', 'ux-studio' ),
	),
	'quick_search_post_types'       => array(
		'does'   => __( 'Chooses which kinds of content the quick search looks through.', 'ux-studio' ),
		'effect' => __( 'Fewer types give shorter, more relevant results. A type that is not ticked is never offered.', 'ux-studio' ),
	),
	'server_clock_enabled'          => array(
		'does'   => __( 'Shows the current time of the server in the admin bar.', 'ux-studio' ),
		'effect' => __( 'Handy when you schedule posts or read logs, because the server time can differ from your own time zone.', 'ux-studio' ),
	),
	'server_clock_position'         => array(
		'does'   => __( 'Where the server clock is shown: as a small pill in the bar or inside the "+" menu.', 'ux-studio' ),
		'effect' => __( 'The pill is always visible, the plus menu keeps the bar shorter.', 'ux-studio' ),
	),
);
