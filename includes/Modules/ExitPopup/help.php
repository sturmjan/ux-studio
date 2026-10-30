<?php
/**
 * Plain-language help of the ExitPopup settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'headline' => array(
		'does'   => __( 'The large title at the top of the popup.', 'ux-studio' ),
		'effect' => __( 'It is the first thing a leaving visitor reads, so say what they get by staying.', 'ux-studio' ),
	),
	'body' => array(
		'does'   => __( 'The text under the headline. It can contain formatting and links.', 'ux-studio' ),
		'effect' => __( 'Explains the offer or the reason to stay. Keep it to a few lines, the window is small on a phone.', 'ux-studio' ),
	),
	'enable_cta' => array(
		'does'   => __( 'Adds a button to the popup that leads the visitor to a page of your choice (CTA means call to action).', 'ux-studio' ),
		'effect' => __( 'Visitors get one clear thing to click instead of only a way to close the window.', 'ux-studio' ),
		'off'    => __( 'Turn it off when the popup only collects e-mails or only informs.', 'ux-studio' ),
	),
	'cta_text' => array(
		'does'   => __( 'The words on the button.', 'ux-studio' ),
		'effect' => __( 'A short, concrete label such as "Show me the offer" works better than "Click here".', 'ux-studio' ),
	),
	'cta_url' => array(
		'does'   => __( 'The address the button opens. Type # (a hash) when the button should only close the popup and keep the visitor on the page.', 'ux-studio' ),
		'effect' => __( 'A full address (https://...) sends the visitor away from the current page. # keeps them where they are.', 'ux-studio' ),
		'risk'   => __( 'A mistyped address leads visitors to an error page, so open the button once after saving.', 'ux-studio' ),
	),
	'cta_new_tab' => array(
		'does'   => __( 'Opens the button address in a new browser tab.', 'ux-studio' ),
		'effect' => __( 'The visitor keeps your page open in the old tab and can come back to it.', 'ux-studio' ),
		'off'    => __( 'Turn it off for a link inside your own site, or when the button only closes the popup.', 'ux-studio' ),
	),
	'bg_color' => array(
		'does'   => __( 'Background color of the popup window.', 'ux-studio' ),
		'effect' => __( 'Pick a color that stands out from your page but keeps the text readable.', 'ux-studio' ),
	),
	'title_color' => array(
		'does'   => __( 'Color of the headline.', 'ux-studio' ),
		'effect' => __( 'Needs enough contrast with the background color, otherwise the headline is hard to read.', 'ux-studio' ),
	),
	'text_color' => array(
		'does'   => __( 'Color of the text under the headline.', 'ux-studio' ),
		'effect' => __( 'Needs enough contrast with the background color for visitors with weaker eyesight.', 'ux-studio' ),
	),
	'cta_bg_color' => array(
		'does'   => __( 'Background color of the button.', 'ux-studio' ),
		'effect' => __( 'The button should be the most visible spot of the popup.', 'ux-studio' ),
	),
	'cta_text_color' => array(
		'does'   => __( 'Color of the text on the button.', 'ux-studio' ),
		'effect' => __( 'Keep it in strong contrast with the button background.', 'ux-studio' ),
	),
	'overlay_opacity' => array(
		'does'   => __( 'How strongly the page behind the popup is darkened, from 0 (not at all) to 100 (black).', 'ux-studio' ),
		'effect' => __( 'A darker overlay pulls the attention to the popup, a light one keeps the page visible behind it.', 'ux-studio' ),
	),
	'delay_seconds' => array(
		'does'   => __( 'For this many seconds after the page loads the popup cannot appear, whatever the visitor does.', 'ux-studio' ),
		'effect' => __( 'Stops the popup from jumping at someone who has just arrived and moves the mouse out of the window by accident.', 'ux-studio' ),
		'risk'   => __( 'With 0 the popup can appear at once, which feels pushy and may hurt your search ranking on mobile.', 'ux-studio' ),
	),
	'frequency_mode' => array(
		'does'   => __( 'How often the same visitor sees the popup: once per browser session, once every set number of days, or on every page view.', 'ux-studio' ),
		'effect' => __( 'Once per session or per several days is polite. A returning visitor is not asked the same thing again.', 'ux-studio' ),
		'risk'   => __( '"Every page view" shows the popup again and again and annoys visitors. The days option remembers the visitor with a cookie, so it needs your cookie notice to mention it.', 'ux-studio' ),
	),
	'detect_mouse_leave' => array(
		'does'   => __( 'Shows the popup when the mouse leaves the browser window upwards, which usually means the visitor is going for the tab bar or the close button.', 'ux-studio' ),
		'effect' => __( 'The classic exit intent. It works only with a mouse, so it does nothing on phones and tablets.', 'ux-studio' ),
		'off'    => __( 'Turn it off when you rely on another trigger only, for example inactivity or time on page.', 'ux-studio' ),
	),
	'mouse_leave_delay' => array(
		'does'   => __( 'How many milliseconds the mouse must stay outside the window before the popup opens (1000 ms is one second).', 'ux-studio' ),
		'effect' => __( 'A short delay lets the popup ignore a quick slip of the mouse that returns at once.', 'ux-studio' ),
	),
	'detect_tab_change' => array(
		'does'   => __( 'Shows the popup when the visitor switches to another browser tab or minimizes the window. The popup is waiting when they come back.', 'ux-studio' ),
		'effect' => __( 'It also works on phones, where there is no mouse to leave the window with.', 'ux-studio' ),
		'risk'   => __( 'Many people switch tabs only to check a message, so the popup can appear to someone who had no intention to leave.', 'ux-studio' ),
	),
	'detect_window_blur' => array(
		'does'   => __( 'Shows the popup when the browser window loses focus, for example after a click into another program.', 'ux-studio' ),
		'effect' => __( 'Catches visitors who leave for another application without leaving the mouse path of the window.', 'ux-studio' ),
		'risk'   => __( 'A click into a video, a map or another embedded part of the page can count as losing focus and open the popup by mistake. Test the pages that have such parts.', 'ux-studio' ),
		'off'    => __( 'Leave it off if you already use the tab switch trigger, the two overlap.', 'ux-studio' ),
	),
	'detect_idle' => array(
		'does'   => __( 'Shows the popup when the visitor does nothing (no mouse, keyboard, touch or scrolling) for a set time.', 'ux-studio' ),
		'effect' => __( 'Reaches people who stopped reading and may be about to leave.', 'ux-studio' ),
		'risk'   => __( 'A visitor who reads a long article without touching anything gets the popup in the middle of the text.', 'ux-studio' ),
	),
	'idle_timeout' => array(
		'does'   => __( 'How many seconds of inactivity must pass before the popup opens.', 'ux-studio' ),
		'effect' => __( 'A longer time is safer for pages with long texts. 30 seconds suits short pages.', 'ux-studio' ),
	),
	'detect_scroll_up' => array(
		'does'   => __( 'Shows the popup when the visitor quickly scrolls back up the page, which is a typical gesture before leaving on a phone.', 'ux-studio' ),
		'effect' => __( 'A replacement of the mouse exit trigger for touch screens.', 'ux-studio' ),
		'risk'   => __( 'Someone who scrolls up to re-read a paragraph may get the popup by mistake. Raise the pixel limit if it appears too often.', 'ux-studio' ),
	),
	'scroll_up_desktop' => array(
		'does'   => __( 'How many pixels the page must jump up within a moment to count as a fast scroll up on a computer.', 'ux-studio' ),
		'effect' => __( 'A higher number means only a big jump counts, so the popup appears less often.', 'ux-studio' ),
	),
	'scroll_up_mobile' => array(
		'does'   => __( 'The same limit for phones and tablets, in pixels.', 'ux-studio' ),
		'effect' => __( 'Screens are shorter, so the default is lower than for a computer.', 'ux-studio' ),
	),
	'time_on_page' => array(
		'does'   => __( 'Shows the popup after this many seconds on the page, without waiting for any exit gesture. 0 turns this trigger off.', 'ux-studio' ),
		'effect' => __( 'Works on every device and does not depend on what the visitor does.', 'ux-studio' ),
		'risk'   => __( 'It is not an exit popup any more, it interrupts a visitor who may be reading. Use a long time, and mind that very early popups on mobile can hurt search ranking.', 'ux-studio' ),
		'off'    => __( 'Leave 0 for a pure exit popup.', 'ux-studio' ),
	),
	'enable_email' => array(
		'does'   => __( 'Adds an e-mail field and a submit button to the popup. Addresses are stored in the Subscribers list on the E-mail capture tab.', 'ux-studio' ),
		'effect' => __( 'You collect contacts from visitors who would leave anyway.', 'ux-studio' ),
		'risk'   => __( 'Collecting e-mail addresses is personal data processing. The popup must say what the address is for and your privacy policy must cover it.', 'ux-studio' ),
		'off'    => __( 'Turn it off when the popup only informs or leads to a page.', 'ux-studio' ),
	),
	'email_placeholder' => array(
		'does'   => __( 'The grey hint shown inside the empty e-mail field.', 'ux-studio' ),
		'effect' => __( 'Tells the visitor what to type.', 'ux-studio' ),
	),
	'email_button_text' => array(
		'does'   => __( 'The words on the button that sends the e-mail address.', 'ux-studio' ),
		'effect' => __( 'Say what happens, for example "Send me the coupon".', 'ux-studio' ),
	),
	'email_success_message' => array(
		'does'   => __( 'The message shown after the visitor sends a valid address.', 'ux-studio' ),
		'effect' => __( 'Confirms the address arrived and says what comes next.', 'ux-studio' ),
	),
	'enable_autoresponder' => array(
		'does'   => __( 'Sends an automatic e-mail to every address that is entered in the popup.', 'ux-studio' ),
		'effect' => __( 'The subscriber gets the promised content (a coupon, a guide) right away.', 'ux-studio' ),
		'risk'   => __( 'The mail goes out from your site, so it needs working outgoing e-mail, otherwise it lands in spam or does not arrive. Strangers can type any address, so someone may get a mail they did not ask for.', 'ux-studio' ),
		'off'    => __( 'Turn it off when you send the follow-up from another tool.', 'ux-studio' ),
	),
	'autoresponder_subject' => array(
		'does'   => __( 'The subject line of the automatic e-mail.', 'ux-studio' ),
		'effect' => __( 'It decides whether the message is opened, so make it clear and honest.', 'ux-studio' ),
	),
	'autoresponder_body' => array(
		'does'   => __( 'The text of the automatic e-mail. It can contain formatting and links.', 'ux-studio' ),
		'effect' => __( 'Put what you promised in the popup here, such as the coupon code.', 'ux-studio' ),
	),
	'targeting_mode' => array(
		'does'   => __( 'Limits the popup to chosen addresses: everywhere (on the post types above), only on matching addresses, or everywhere except matching addresses.', 'ux-studio' ),
		'effect' => __( 'Lets you keep the popup away from pages where it would be in the way, such as the cart.', 'ux-studio' ),
		'risk'   => __( 'Do not show the popup on the checkout, a login page or a page that already asks for data. It would interrupt a purchase.', 'ux-studio' ),
	),
	'hide_for_logged_in' => array(
		'does'   => __( 'Never shows the popup to visitors who are logged in.', 'ux-studio' ),
		'effect' => __( 'You and your team do not see the popup while working on the site. Logged-in customers are not asked either.', 'ux-studio' ),
		'risk'   => __( 'With this on you cannot see the popup while logged in, so test it in a private browser window.', 'ux-studio' ),
		'off'    => __( 'Turn it off while you are testing the popup.', 'ux-studio' ),
	),
	'cookie_days' => array(
		'does'   => __( 'How many days the browser remembers that the visitor has seen the popup (used by "Once every N days").', 'ux-studio' ),
		'effect' => __( 'After this time the visitor can see the popup again.', 'ux-studio' ),
		'risk'   => __( 'The popup is remembered in a cookie, so mention it in your cookie notice. A visitor who deletes cookies sees the popup again.', 'ux-studio' ),
	),
);
