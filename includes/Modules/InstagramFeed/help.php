<?php
/**
 * Plain-language help of the InstagramFeed settings, read by Core\SettingHelp (settings screen). Format: docs/help-texts.md.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'sync_interval' => array(
		'does'   => __( 'How often the site fetches new posts from Instagram. It runs in the background through the scheduled tasks of WordPress ([[cron]]).', 'ux-studio' ),
		'effect' => __( 'A shorter interval shows new posts sooner.', 'ux-studio' ),
		'risk'   => __( 'Scheduled tasks run only when somebody visits the site, so a quiet site refreshes later than set. Each fetch is a request to Instagram, which has its own limits.', 'ux-studio' ),
	),
);
