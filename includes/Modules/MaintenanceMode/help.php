<?php
/**
 * Plain-language help of the MaintenanceMode settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'mode' => array(
		'does'   => __( 'Chooses what visitors see: the normal site (Disabled), a maintenance page that tells search engines to come back later (503), or a coming soon page that looks like a normal page to search engines (200).', 'ux-studio' ),
		'effect' => __( 'While a page is active, visitors cannot browse the site. Logged-in people with access, the addresses you keep open and listed IP addresses still see the real site.', 'ux-studio' ),
		'risk'   => __( 'Leaving the maintenance page on by mistake hides the whole site from customers and search engines. Use 503 for a short break only: a long 503 can drop pages from Google. Use Coming Soon for a site that has not launched yet.', 'ux-studio' ),
		'off'    => __( 'Set it to Disabled when the work is done.', 'ux-studio' ),
	),
	'layout_type' => array(
		'does'   => __( 'Chooses whether the maintenance page is built from the module layouts or taken from a page you already made in WordPress.', 'ux-studio' ),
		'effect' => __( 'The fields that do not apply to your choice are hidden. Their values stay saved.', 'ux-studio' ),
		'risk'   => __( 'An existing page that uses the theme header and menu can show links to the real site. Check the page before you turn the mode on.', 'ux-studio' ),
	),
	'custom_layout' => array(
		'does'   => __( 'Picks one of the three built-in layouts: a centred card, or a split page with the picture on the right or on the left.', 'ux-studio' ),
		'effect' => __( 'Changes only the arrangement of the logo, texts and background image.', 'ux-studio' ),
	),
	'existing_page' => array(
		'does'   => __( 'The WordPress page shown to visitors instead of the site while the mode is on.', 'ux-studio' ),
		'effect' => __( 'You design the page in your own editor or page builder.', 'ux-studio' ),
		'risk'   => __( 'With no page chosen the built-in layout is shown instead. Do not pick a page that is hidden behind a password or a draft.', 'ux-studio' ),
	),
	'auth_type' => array(
		'does'   => __( 'Decides who can see the real site while the mode is on: every logged-in user, or only users with a chosen role. Administrators always get in.', 'ux-studio' ),
		'effect' => __( 'Your team can keep working and checking the site while visitors see the maintenance page.', 'ux-studio' ),
		'risk'   => __( 'With "Logged In Users" every account gets in, including customers and subscribers if your site lets visitors register. Choose roles to keep the site closed to them.', 'ux-studio' ),
	),
	'access_roles' => array(
		'does'   => __( 'The roles that see the real site while the mode is on (used with "By User Role").', 'ux-studio' ),
		'effect' => __( 'Everyone with another role sees the maintenance page even when logged in.', 'ux-studio' ),
		'risk'   => __( 'With no role chosen only administrators get in.', 'ux-studio' ),
	),
	'exclude_paths' => array(
		'does'   => __( 'Addresses that stay open to everybody while the mode is on, such as a contact page or a payment return page.', 'ux-studio' ),
		'effect' => __( 'Visitors can open exactly these pages and see the normal site there.', 'ux-studio' ),
		'risk'   => __( 'A pattern that is too wide (for example /*) opens the whole site. Payment gateways and webhooks need their return addresses here, otherwise orders break.', 'ux-studio' ),
	),
	'allow_feeds' => array(
		'does'   => __( 'Lets RSS feeds of posts and comments through instead of the maintenance page.', 'ux-studio' ),
		'effect' => __( 'Feed readers and newsletter tools keep receiving your content during the break.', 'ux-studio' ),
		'risk'   => __( 'The content of your posts is public through the feed, even though the site is closed.', 'ux-studio' ),
		'off'    => __( 'Turn it off for a site that must stay hidden, such as one before launch.', 'ux-studio' ),
	),
	'bypass_ips' => array(
		'does'   => __( 'IP addresses or ranges that see the real site without logging in.', 'ux-studio' ),
		'effect' => __( 'Your office or a client can check the site while everybody else sees the maintenance page.', 'ux-studio' ),
		'risk'   => __( 'Everyone behind that address gets in, and addresses of home internet connections change. Behind a proxy or a CDN WordPress may see a different address than yours, so the rule may not match.', 'ux-studio' ),
	),
	'countdown_enabled' => array(
		'does'   => __( 'Shows a counting clock to the end time below on the maintenance page.', 'ux-studio' ),
		'effect' => __( 'Visitors see when you are back.', 'ux-studio' ),
		'risk'   => __( 'The clock does not open the site by itself. If the work is late, the clock shows zero while the page stays closed. The clock is not shown on a page you made in WordPress.', 'ux-studio' ),
	),
	'countdown_end' => array(
		'does'   => __( 'The date and time (in the time of the site) when you expect to be back.', 'ux-studio' ),
		'effect' => __( 'It drives the countdown and tells search engines when to come back (the Retry-After hint of the 503 page).', 'ux-studio' ),
	),
	'countdown_label' => array(
		'does'   => __( 'The sentence above the countdown.', 'ux-studio' ),
		'effect' => __( 'Visitors read it together with the numbers, so write it as a full sentence start such as "We will be back in".', 'ux-studio' ),
	),
	'countdown_auto_disable' => array(
		'does'   => __( 'When the end time passes, the site opens by itself.', 'ux-studio' ),
		'effect' => __( 'Nobody has to remember to switch the mode off.', 'ux-studio' ),
		'risk'   => __( 'The site opens even when the work is not finished. The stored mode stays unchanged, it is only ignored.', 'ux-studio' ),
	),
	'headline_text' => array(
		'does'   => __( 'The main title of the maintenance page.', 'ux-studio' ),
		'effect' => __( 'Visitors read it first. Say clearly what is going on.', 'ux-studio' ),
	),
	'headline_colour' => array(
		'does'   => __( 'Color of the title.', 'ux-studio' ),
		'effect' => __( 'Needs good contrast with the page background.', 'ux-studio' ),
	),
	'body_text' => array(
		'does'   => __( 'The text under the title. It can contain formatting and links.', 'ux-studio' ),
		'effect' => __( 'Explain the reason, the expected end and how to reach you.', 'ux-studio' ),
	),
	'body_colour' => array(
		'does'   => __( 'Color of the text under the title.', 'ux-studio' ),
		'effect' => __( 'Needs good contrast with the page background.', 'ux-studio' ),
	),
	'footer_text' => array(
		'does'   => __( 'A small line at the bottom of the page, for example a copyright or a contact.', 'ux-studio' ),
		'effect' => __( 'Optional. Leave it empty for no footer.', 'ux-studio' ),
	),
	'footer_colour' => array(
		'does'   => __( 'Color of the footer text.', 'ux-studio' ),
		'effect' => __( 'Needs good contrast with the page background.', 'ux-studio' ),
	),
	'enable_logo' => array(
		'does'   => __( 'Shows a logo on the maintenance page.', 'ux-studio' ),
		'effect' => __( 'Visitors see right away whose site it is. The logo settings appear once you turn it on.', 'ux-studio' ),
	),
	'logo_width' => array(
		'does'   => __( 'Width of the logo in pixels. The height follows the proportions.', 'ux-studio' ),
		'effect' => __( 'A very wide logo can overflow a phone screen.', 'ux-studio' ),
	),
	'background_colour' => array(
		'does'   => __( 'Background color of the maintenance page.', 'ux-studio' ),
		'effect' => __( 'Shown behind the card and when no background image is used.', 'ux-studio' ),
	),
	'enable_background_image' => array(
		'does'   => __( 'Shows a picture behind the content of the maintenance page.', 'ux-studio' ),
		'effect' => __( 'A picture makes the page look finished. The image field appears once you turn it on.', 'ux-studio' ),
		'risk'   => __( 'A large photo slows the page on a weak connection. Use a compressed image.', 'ux-studio' ),
	),
	'background_image' => array(
		'does'   => __( 'The picture shown behind the content of the maintenance page.', 'ux-studio' ),
		'effect' => __( 'It fills the whole screen, so choose a wide picture and leave space for the text.', 'ux-studio' ),
		'risk'   => __( 'A large photo slows the page on a weak connection. Use a compressed image.', 'ux-studio' ),
	),
);
