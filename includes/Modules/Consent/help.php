<?php
/**
 * Plain-language help of the Cookie Consent settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'display_to' => array(
		'does'   => __( 'Decides who is asked for consent. With "Europe only" the banner appears for visitors from the EU, the European Economic Area, the United Kingdom and Switzerland.', 'ux-studio' ),
		'effect' => __( 'Visitors from other countries are treated as having agreed: nothing is held back for them and no banner is shown. The pages themselves stay the same for everyone, so a page cache keeps working.', 'ux-studio' ),
		'risk'   => __( 'The country is read from the CF-IPCountry header that Cloudflare adds. Without Cloudflare, or when the header is missing, everybody gets the banner. If your visitors are mostly European, "All visitors" is the safe and simpler choice.', 'ux-studio' ),
		'off'    => __( 'Keep "All visitors" unless you serve a mostly non-European audience behind Cloudflare.', 'ux-studio' ),
	),
	'policy_version' => array(
		'does'   => __( 'A number that goes with every saved choice. When you raise it, all earlier choices are ignored and everyone is asked again.', 'ux-studio' ),
		'effect' => __( 'Use it after you add a new kind of tracking or change your cookie policy in a way that needs a fresh agreement.', 'ux-studio' ),
		'risk'   => __( 'Raising it makes the banner appear again for all returning visitors.', 'ux-studio' ),
	),
	'expiry_days' => array(
		'does'   => __( 'How long a visitor choice is remembered, in days. The longest allowed value is 365.', 'ux-studio' ),
		'effect' => __( 'After this time the banner asks again.', 'ux-studio' ),
		'risk'   => __( 'Data protection authorities expect a choice to be renewed at the latest after about a year, so do not go above 365.', 'ux-studio' ),
	),
	'show_reopen' => array(
		'does'   => __( 'Shows a small round button in the corner of every page that reopens the cookie settings.', 'ux-studio' ),
		'effect' => __( 'Visitors can change or withdraw their consent at any time, which the rules require to be as easy as giving it.', 'ux-studio' ),
		'risk'   => __( 'If you turn it off, put the shortcode [uxstudio_cookie_settings] in your footer or privacy page so the choice can still be changed.', 'ux-studio' ),
	),
	'reopen_position' => array(
		'does'   => __( 'The corner where the small settings button sits.', 'ux-studio' ),
		'effect' => __( 'Pick the side that does not cover a chat widget or a back to top button.', 'ux-studio' ),
	),
	'title' => array(
		'does'   => __( 'The heading of the banner.', 'ux-studio' ),
		'effect' => __( 'It is read first by visitors and by screen readers.', 'ux-studio' ),
	),
	'message' => array(
		'does'   => __( 'The short explanation in the banner. Say plainly what the cookies are used for.', 'ux-studio' ),
		'effect' => __( 'Consent only counts when the visitor understands what they agree to, so keep it clear and honest.', 'ux-studio' ),
	),
	'policy_url' => array(
		'does'   => __( 'The address of your cookie or privacy policy page. If you leave it empty, the privacy page set in WordPress is used.', 'ux-studio' ),
		'effect' => __( 'The banner shows a link to it, so visitors can read the details before they decide.', 'ux-studio' ),
	),
	'policy_label' => array(
		'does'   => __( 'The text of the link to the policy page.', 'ux-studio' ),
		'effect' => __( 'It appears under the message when a policy address is known.', 'ux-studio' ),
	),
	'btn_accept' => array(
		'does'   => __( 'The label of the button that allows every category.', 'ux-studio' ),
		'effect' => __( 'It looks exactly like the reject button on purpose.', 'ux-studio' ),
	),
	'btn_reject' => array(
		'does'   => __( 'The label of the button that refuses every category except the necessary one.', 'ux-studio' ),
		'effect' => __( 'It has the same size and colour as the accept button. Refusing must be as easy as accepting.', 'ux-studio' ),
		'risk'   => __( 'Do not hide this button or make it look weaker than the accept button, that can make the consent invalid.', 'ux-studio' ),
	),
	'btn_settings' => array(
		'does'   => __( 'The label of the button that opens the choice by category.', 'ux-studio' ),
		'effect' => __( 'Visitors can allow only statistics, only preferences and so on.', 'ux-studio' ),
	),
	'btn_save' => array(
		'does'   => __( 'The label of the button that saves the categories picked in the detailed view.', 'ux-studio' ),
		'effect' => __( 'It stores exactly the boxes the visitor ticked.', 'ux-studio' ),
	),
	'services_preferences' => array(
		'does'   => __( 'Extra services you want listed under Preferences, one per line, for example "Chat widget, remembers the conversation".', 'ux-studio' ),
		'effect' => __( 'Visitors see these names in the detailed view. Services that the plugin recognises on a page are added automatically.', 'ux-studio' ),
	),
	'services_statistics' => array(
		'does'   => __( 'Extra services you want listed under Statistics, one per line.', 'ux-studio' ),
		'effect' => __( 'Visitors see these names in the detailed view. Services that the plugin recognises on a page are added automatically.', 'ux-studio' ),
	),
	'services_marketing' => array(
		'does'   => __( 'Extra services you want listed under Marketing, one per line.', 'ux-studio' ),
		'effect' => __( 'Visitors see these names in the detailed view. Services that the plugin recognises on a page are added automatically.', 'ux-studio' ),
	),
	'layout' => array(
		'does'   => __( 'A box in a corner of the screen, or a bar across the bottom.', 'ux-studio' ),
		'effect' => __( 'The box covers less of the page. The bar is more noticeable.', 'ux-studio' ),
	),
	'position' => array(
		'does'   => __( 'The side of the screen where the box appears.', 'ux-studio' ),
		'effect' => __( 'Pick the side that suits your design.', 'ux-studio' ),
	),
	'color_bg' => array(
		'does'   => __( 'Background colour of the banner.', 'ux-studio' ),
		'effect' => __( 'Keep enough contrast with the text colour, at least 4.5 to 1, so it is readable for everyone.', 'ux-studio' ),
	),
	'color_text' => array(
		'does'   => __( 'Colour of the text in the banner.', 'ux-studio' ),
		'effect' => __( 'Keep enough contrast with the background.', 'ux-studio' ),
	),
	'color_accent' => array(
		'does'   => __( 'Colour of the buttons.', 'ux-studio' ),
		'effect' => __( 'All main buttons share it, so accepting and refusing look equal.', 'ux-studio' ),
	),
	'color_accent_text' => array(
		'does'   => __( 'Colour of the text on the buttons.', 'ux-studio' ),
		'effect' => __( 'Keep enough contrast with the button colour.', 'ux-studio' ),
	),
	'radius' => array(
		'does'   => __( 'How round the corners of the banner and the buttons are, in pixels.', 'ux-studio' ),
		'effect' => __( '0 gives sharp corners.', 'ux-studio' ),
	),
	'block_scripts' => array(
		'does'   => __( 'Holds back the scripts of known tracking services (Google Analytics, Meta Pixel, Hotjar and others) until the visitor allows their category.', 'ux-studio' ),
		'effect' => __( 'Without agreement these services are not loaded and set no cookies. Once the visitor agrees, they start without a reload.', 'ux-studio' ),
		'risk'   => __( 'Only scripts that WordPress loads in the usual way are caught, plus the ones from Pixel and Tag Manager and GTM Kit. A snippet pasted straight into a template needs the option "Also catch snippets pasted directly into the page".', 'ux-studio' ),
		'off'    => __( 'Turn it off only when another tool already controls your scripts.', 'ux-studio' ),
	),
	'block_iframes' => array(
		'does'   => __( 'Replaces embedded YouTube, Vimeo, Google Maps, Mapy.cz and similar content by a box with a "Load content" button.', 'ux-studio' ),
		'effect' => __( 'The service gets no data until the visitor allows marketing or presses the button for that one item.', 'ux-studio' ),
		'risk'   => __( 'Embeds look different until loaded, so check pages with videos or maps.', 'ux-studio' ),
	),
	'youtube_nocookie' => array(
		'does'   => __( 'Loads YouTube videos from youtube-nocookie.com, the privacy-enhanced address.', 'ux-studio' ),
		'effect' => __( 'YouTube sets fewer cookies until the video is played. It is still held back until the visitor agrees.', 'ux-studio' ),
	),
	'buffer_guard' => array(
		'does'   => __( 'Looks through the finished page for known tracking snippets that were typed into a template or a page builder, and holds them back too.', 'ux-studio' ),
		'effect' => __( 'Catches what the normal blocking cannot see. It runs once when a page is built, so with a page cache it costs almost nothing.', 'ux-studio' ),
		'risk'   => __( 'It works on the HTML text, so an unusual snippet may slip through or, rarely, a harmless one may be held back. Test your main pages after turning it on.', 'ux-studio' ),
		'off'    => __( 'Leave it off if the normal blocking already covers all your tracking.', 'ux-studio' ),
	),
	'custom_rules' => array(
		'does'   => __( 'Your own rules, one per line: an address part or a host name, a space, then the category (preferences, statistics, marketing or necessary). Example: static.example-stats.com statistics', 'ux-studio' ),
		'effect' => __( 'Scripts and embeds from that address are held back like the built-in ones. "necessary" lets something through that would otherwise be held back. Your rules win over the built-in list.', 'ux-studio' ),
		'risk'   => __( 'A rule with a short address part can match more than you mean. Use a host name when you can.', 'ux-studio' ),
	),
	'consent_mode' => array(
		'does'   => __( 'Tells Google tags what the visitor allowed (Google Consent Mode v2). Basic: Google tags do not load at all before consent. Advanced: they load at once, but send only anonymous pings without cookies until consent is given.', 'ux-studio' ),
		'effect' => __( 'Basic is the cautious choice. Advanced lets Google model the visits it could not measure, which can give fuller reports.', 'ux-studio' ),
		'risk'   => __( 'In advanced mode Google servers are contacted before the visitor decides. Some authorities see that as a data transfer that needs consent, so mention it in your privacy policy or stay on basic.', 'ux-studio' ),
		'off'    => __( 'Choose Off only when the site uses no Google tags at all.', 'ux-studio' ),
	),
	'wait_for_update' => array(
		'does'   => __( 'How many milliseconds Google tags wait for the saved choice of a returning visitor before they start.', 'ux-studio' ),
		'effect' => __( 'The default of 500 is enough on almost every site.', 'ux-studio' ),
	),
);
