<?php
/**
 * Plain-language help of the Disable Auto Updates settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'disable_core_updates'        => array(
		'does'   => __( 'WordPress stops installing new versions of itself by itself. You can still update by hand in Dashboard > Updates.', 'ux-studio' ),
		'effect' => __( 'The site stays on the version it has now until somebody updates it. Visitors notice nothing.', 'ux-studio' ),
		'risk'   => __( 'WordPress releases fix security holes several times a year. A site that is never updated by hand slowly becomes an easy target for hackers. Turn on the security exception below unless somebody else takes care of updates.', 'ux-studio' ),
		'off'    => __( 'Leave it off when nobody checks the site regularly, so the security releases arrive on their own.', 'ux-studio' ),
	),
	'core_update_type'            => array(
		'does'   => __( 'Chooses which WordPress releases are blocked: all of them, only major releases (new features, for example 6.8 to 6.9) or only minor releases (small fixes, for example 6.8.1 to 6.8.2).', 'ux-studio' ),
		'effect' => __( 'Blocking only major releases is the usual compromise: the site keeps getting small fixes but never changes behavior overnight.', 'ux-studio' ),
		'risk'   => __( 'Minor releases carry the security fixes. Blocking them (or all releases) leaves known holes open.', 'ux-studio' ),
	),
	'allow_security_core'         => array(
		'does'   => __( 'Even with core updates disabled, WordPress still installs the small security releases by itself. Major releases stay blocked.', 'ux-studio' ),
		'effect' => __( 'You keep full control over big changes and still get the urgent security fixes without lifting a finger.', 'ux-studio' ),
		'off'    => __( 'Only when you test and install every release yourself, including the small ones.', 'ux-studio' ),
	),
	'disable_plugin_updates'      => array(
		'does'   => __( 'Plugins are no longer updated automatically. You can still update them by hand.', 'ux-studio' ),
		'effect' => __( 'A plugin never changes without you knowing, so an update cannot break the site while you are away.', 'ux-studio' ),
		'risk'   => __( 'Outdated plugins are the most common way a site gets hacked. Nobody installs the fixes unless you do it by hand.', 'ux-studio' ),
		'off'    => __( 'Leave it off for plugins you do not check often. Turn it on only for plugins where a bad update would be costly, such as a shop or booking plugin.', 'ux-studio' ),
	),
	'plugin_update_type'          => array(
		'does'   => __( 'Applies the block to all plugins, or only to the plugins you pick in the list below.', 'ux-studio' ),
		'effect' => __( 'Picking specific plugins lets everything else keep updating itself.', 'ux-studio' ),
	),
	'disabled_plugins'            => array(
		'does'   => __( 'The plugins in this list are never updated automatically. All other plugins update as usual.', 'ux-studio' ),
		'effect' => __( 'Use it for sensitive plugins (shop, booking, page builder) that you update by hand after a backup.', 'ux-studio' ),
		'risk'   => __( 'A plugin on this list stays outdated until you update it by hand, security fixes included.', 'ux-studio' ),
	),
	'disable_theme_updates'       => array(
		'does'   => __( 'Themes are no longer updated automatically. You can still update them by hand.', 'ux-studio' ),
		'effect' => __( 'The design of the site never changes overnight.', 'ux-studio' ),
		'risk'   => __( 'An outdated theme can contain security holes just like a plugin. Nobody installs the fixes unless you do it by hand.', 'ux-studio' ),
		'off'    => __( 'Leave it off for a theme that has no changes of your own. A theme with code changes made directly in its files should be updated by hand, because an update overwrites them.', 'ux-studio' ),
	),
	'theme_update_type'           => array(
		'does'   => __( 'Applies the block to all themes, or only to the themes you pick in the list below.', 'ux-studio' ),
		'effect' => __( 'Picking specific themes lets the other themes keep updating themselves.', 'ux-studio' ),
	),
	'disabled_themes'             => array(
		'does'   => __( 'The themes in this list are never updated automatically. All other themes update as usual.', 'ux-studio' ),
		'effect' => __( 'Use it for the theme that has your own changes, so an update cannot overwrite them.', 'ux-studio' ),
		'risk'   => __( 'A theme on this list stays outdated until you update it by hand, security fixes included.', 'ux-studio' ),
	),
	'disable_translation_updates' => array(
		'does'   => __( 'WordPress no longer downloads new language files for core, plugins and themes by itself.', 'ux-studio' ),
		'effect' => __( 'Texts in the admin and on the site keep the wording they have now. New or changed texts of a plugin may stay in English until you update the translations by hand.', 'ux-studio' ),
	),
	'hide_notices_core'           => array(
		'does'   => __( 'Hides the WordPress update notice, the counter in the menu and the admin bar and the update rows from everybody except the users listed below.', 'ux-studio' ),
		'effect' => __( 'The admin looks calmer, but nobody who is not on the list sees that an update is waiting.', 'ux-studio' ),
		'risk'   => __( 'Hidden updates are forgotten updates. Outdated software is the most common way a site gets hacked.', 'ux-studio' ),
		'off'    => __( 'Turn it on only when somebody else keeps the site up to date and you do not want clients to see the notices.', 'ux-studio' ),
	),
	'hide_notices_plugins'        => array(
		'does'   => __( 'Hides plugin update notices, the counter in the menu and the update rows on the Plugins page from everybody except the users listed below.', 'ux-studio' ),
		'effect' => __( 'The Plugins page looks clean, but nobody who is not on the list sees which plugins are outdated. Automatic updates still see them.', 'ux-studio' ),
		'risk'   => __( 'Hidden updates are forgotten updates. An outdated plugin is the most common entry for hackers.', 'ux-studio' ),
		'off'    => __( 'Turn it on only when somebody else keeps the site up to date.', 'ux-studio' ),
	),
	'hide_notices_themes'         => array(
		'does'   => __( 'Hides theme update notices and the update rows on the Themes page from everybody except the users listed below.', 'ux-studio' ),
		'effect' => __( 'Nobody who is not on the list sees that a theme is outdated. Automatic updates still see them.', 'ux-studio' ),
		'risk'   => __( 'Hidden updates are forgotten updates. An outdated theme can carry security holes.', 'ux-studio' ),
		'off'    => __( 'Turn it on only when somebody else keeps the site up to date.', 'ux-studio' ),
	),
	'updates_visible_to'          => array(
		'does'   => __( 'Users listed here (by login name, separated by commas) still see all update notices and can update as usual.', 'ux-studio' ),
		'effect' => __( 'Put your own login (or the login of the person who maintains the site) here, so the notices are hidden from clients only.', 'ux-studio' ),
		'risk'   => __( 'With an empty list the notices are hidden from administrators too, and then nobody sees them.', 'ux-studio' ),
	),
	'disable_checks_core'         => array(
		'does'   => __( 'WordPress stops asking wordpress.org whether a new version exists.', 'ux-studio' ),
		'effect' => __( 'No offers appear and nothing installs automatically. With the security exception turned on, this setting is ignored for WordPress itself.', 'ux-studio' ),
		'risk'   => __( 'The site does not even learn that a security release exists. Use it only for a site that is frozen on purpose.', 'ux-studio' ),
	),
	'disable_checks_plugins'      => array(
		'does'   => __( 'WordPress stops asking wordpress.org whether new plugin versions exist.', 'ux-studio' ),
		'effect' => __( 'No plugin offers appear and no plugin updates automatically. The Plugins page has nothing to offer either.', 'ux-studio' ),
		'risk'   => __( 'Security fixes of plugins stay invisible. Use it only for a site that is frozen on purpose.', 'ux-studio' ),
	),
	'disable_checks_themes'       => array(
		'does'   => __( 'WordPress stops asking wordpress.org whether new theme versions exist.', 'ux-studio' ),
		'effect' => __( 'No theme offers appear and no theme updates automatically.', 'ux-studio' ),
		'risk'   => __( 'Security fixes of themes stay invisible. Use it only for a site that is frozen on purpose.', 'ux-studio' ),
	),
);
