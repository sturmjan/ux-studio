<?php
/**
 * Plain-language help of the Redirects settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'permanent_cache_ttl' => array(
		'does  ' => __( 'How many seconds browsers may remember a permanent redirect ([[301]]) before asking your site again. 0 means they do not remember it.', 'ux-studio' ),
		'effect' => __( 'A longer time saves a request on every visit. A shorter time lets a changed or removed redirect take effect sooner.', 'ux-studio' ),
		'risk  ' => __( 'Browsers keep a remembered redirect even after you delete the rule, and you cannot clear it for them. Keep the default hour while you are testing.', 'ux-studio' ),
	),
	'monitor_slugs' => array(
		'does  ' => __( 'When you change the web address (slug) of a published post or page, or move it under another parent, a permanent redirect from the old address is created.', 'ux-studio' ),
		'effect' => __( 'Old links, bookmarks and search results still lead to the right place.', 'ux-studio' ),
		'risk  ' => __( 'It creates rules by itself. A page you rename and then rename back leaves a rule behind, check the list now and then.', 'ux-studio' ),
	),
	'pass_query' => array(
		'does  ' => __( 'Adds the part after the question mark of the old address (campaign tags such as utm_source or gclid) to the new address.', 'ux-studio' ),
		'effect' => __( 'Campaign tracking survives the redirect and your statistics keep the source of the visit.', 'ux-studio' ),
		'risk  ' => __( 'A target address that already has its own parameters gets the old ones added to it, which can change what it shows.', 'ux-studio' ),
	),
	'track_hits' => array(
		'does  ' => __( 'Counts how many times each rule was used and when last.', 'ux-studio' ),
		'effect' => __( 'You see which old addresses still get visitors and which rules you can safely delete.', 'ux-studio' ),
		'risk  ' => __( 'It writes one small record to the database on every redirect, which adds up on a very busy site.', 'ux-studio' ),
		'off   ' => __( 'Turn it off on a very busy site that you do not need the numbers for.', 'ux-studio' ),
	),
	'log_404' => array(
		'does  ' => __( 'Records addresses that visitors tried to open but do not exist (error 404), with a count and the page they came from. No IP addresses are stored.', 'ux-studio' ),
		'effect' => __( 'You find broken links and old addresses worth redirecting.', 'ux-studio' ),
		'risk  ' => __( 'Bots probe random addresses, so the list can fill with junk and grow the database. The retention below cleans it up.', 'ux-studio' ),
	),
	'log_404_retention_days' => array(
		'does  ' => __( 'After how many days without a new visit an entry of the 404 list is deleted. 0 keeps entries for ever.', 'ux-studio' ),
		'effect' => __( 'The list stays short and shows the current problems.', 'ux-studio' ),
		'risk  ' => __( 'With 0 the list only grows.', 'ux-studio' ),
	),
);
