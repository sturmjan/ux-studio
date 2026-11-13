<?php
/**
 * Plain-language help of the Indexing & Sharing settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'social_refresh_enabled' => array(
		'does'   => __( 'When you save a published page, post or product, Facebook is asked to forget the old preview of its link and fetch the title, description and image again. This happens about two minutes after the save, so your page cache has time to clear.', 'ux-studio' ),
		'effect' => __( 'Someone who shares the link on Facebook or Messenger sees the new text and image. Without this, the old preview can stay for weeks. LinkedIn has no automatic refresh, so the editor shows a link to its Post Inspector. X, Pinterest, Telegram, Slack and Discord cannot be refreshed from outside, they renew their previews themselves.', 'ux-studio' ),
		'risk'   => __( 'Facebook keeps an image under its address. A picture you replace under the same file name can still look old. Upload it under a new name. Nothing is sent for pages protected by a password, set to noindex, or while the maintenance page or a site that discourages search engines is on.', 'ux-studio' ),
		'off'    => __( 'Leave it off if the site is not shared on social networks.', 'ux-studio' ),
	),
	'social_key_source'      => array(
		'does'   => __( 'Facebook only answers an app that is registered with it. UX1 Network app means this site asks UX1 Network app to do the refresh with its app, so you fill in nothing. My own Facebook app means this site calls Facebook itself with keys of your app.', 'ux-studio' ),
		'effect' => __( 'With UX1 Network app the site sends only the addresses of changed pages. The secret of UX1 Network app never reaches the site. With your own app the limits and the responsibility are yours alone.', 'ux-studio' ),
		'risk'   => __( 'UX1 Network app option works only when the site is connected to it. Without the connection no preview is refreshed, and the settings show a warning.', 'ux-studio' ),
		'off'    => __( 'Choose your own app if the site is not managed by UX1 Network app, or if you want it to be independent of it.', 'ux-studio' ),
	),
	'social_fb_app_id'       => array(
		'does'   => __( 'The number of your Facebook app. Open developers.facebook.com, go to My Apps and create an app (type Other or Business is fine). Then open Settings, Basic and copy the App ID. Menu names there change from time to time. Your app does not need to be reviewed by Facebook.', 'ux-studio' ),
		'effect' => __( 'The site uses it to ask Facebook for a fresh preview.', 'ux-studio' ),
	),
	'social_fb_app_secret'   => array(
		'does'   => __( 'The App secret from the same page (Settings, Basic, click Show). It is saved encrypted and never shown again. Leave the field empty to keep the saved one.', 'ux-studio' ),
		'effect' => __( 'Together with the App ID it lets the site ask Facebook for a fresh preview. Use the Verify keys button below to check both values.', 'ux-studio' ),
		'risk'   => __( 'Anyone who knows the secret can act as your app. Do not send it by e-mail or chat. It is not part of a settings export.', 'ux-studio' ),
	),
	'social_linkedin_link'   => array(
		'does'   => __( 'Adds a link to the LinkedIn Post Inspector to the box beside the editor.', 'ux-studio' ),
		'effect' => __( 'LinkedIn cannot be refreshed by a program. When you open the inspector with the address of the page, LinkedIn fetches the page again.', 'ux-studio' ),
		'off'    => __( 'Turn it off if you do not share on LinkedIn.', 'ux-studio' ),
	),
	'indexnow_enabled'       => array(
		'does'   => __( 'Tells the search engines that support IndexNow (Bing, Seznam, Yandex, Naver, Yep) that a page changed, right after you save it. The site proves it owns the address with a short key that it shows at the address /key.txt on your domain. You do not create any file.', 'ux-studio' ),
		'effect' => __( 'These search engines learn about a new or changed page at once, not at the next visit of their robot. Google does not support IndexNow and keeps using the sitemap.', 'ux-studio' ),
		'risk'   => __( 'Rank Math, Yoast SEO and the IndexNow plugin can send the same message. When one of them has it on, this site stays quiet so a page is not sent twice. Nothing is sent while search engines are discouraged in Settings, Reading.', 'ux-studio' ),
		'off'    => __( 'Turn it off if you do not care about Bing and Seznam, or if another plugin already does it.', 'ux-studio' ),
	),
);
