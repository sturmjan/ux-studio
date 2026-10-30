<?php
/**
 * Plain-language help of the Cron Control settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'mode'                => array(
		'does'   => __( 'Chooses what starts the scheduled tasks of the site ([[cron|WP-Cron]]): publishing planned posts, checking for updates, sending e-mails from plugins. Normally WordPress starts them whenever a visitor opens a page. Here you can leave it that way, switch it off completely, switch it off for visitors but keep it for the server itself, let the hosting start it, or let the central app start it.', 'ux-studio' ),
		'effect' => __( 'Starting tasks from the hosting or the central app is more reliable and takes work away from page loads. The module does it by adding a small file to the mu-plugins folder that sets DISABLE_WP_CRON, so wp-config.php stays untouched. In the modes that block outside access, the cron file also gets an access rule in .htaccess. That works on Apache only, on nginx you set the rule up yourself.', 'ux-studio' ),
		'risk'   => __( 'When you switch off the start from page visits and nothing else starts the tasks, they stop for good: planned posts are not published, backups and updates are not run, mail queues stand still. Use the hosting or central app mode only after the hosting cron or the central app really calls the site. The module cannot see whether the hosting cron is set up.', 'ux-studio' ),
		'off'    => __( 'Leave it at running normally if you do not know what to choose, or if the site has little traffic and nothing time-critical.', 'ux-studio' ),
	),
	'central_allowed_ips' => array(
		'does'   => __( 'In the central app mode these addresses may open the cron file (wp-cron.php) of the site, on top of requests from the server itself. You can list single addresses or whole ranges ([[CIDR]]), one per line.', 'ux-studio' ),
		'effect' => __( 'Nobody else can start the tasks from outside, so strangers cannot overload the site through the cron file.', 'ux-studio' ),
		'risk'   => __( 'If the address of the central app server is missing here, the app cannot start the tasks and they stop running.', 'ux-studio' ),
		'off'    => __( 'Leave it empty to allow only the server itself.', 'ux-studio' ),
	),
	'cron_watch_enabled'  => array(
		'does'   => __( 'Once a night it goes through all scheduled tasks and reports tasks it does not recognize or that look dangerous, for example a task whose code sits in the uploads folder. Malware often hides itself as a scheduled task.', 'ux-studio' ),
		'effect' => __( 'You find out about a strange task in the report. Nothing is changed or removed by this alone.', 'ux-studio' ),
		'off'    => __( 'Turn it off only if the nightly check slows down a very weak server.', 'ux-studio' ),
	),
	'cron_autoremove'     => array(
		'does'   => __( 'Tasks that the nightly check marks as suspicious (a random name, code in the uploads folder) are removed from the schedule automatically.', 'ux-studio' ),
		'effect' => __( 'A hidden task of malware stops running right after the check finds it.', 'ux-studio' ),
		'risk'   => __( 'The check can be wrong. A harmless task of a plugin with an unusual name or location is removed too, and the plugin stops doing its job until it schedules the task again. Review the findings first.', 'ux-studio' ),
		'off'    => __( 'Leave it off until you have read several reports and know that they contain no false alarms.', 'ux-studio' ),
	),
	'cron_whitelist_extra' => array(
		'does'   => __( 'Names of tasks that the nightly check must never report as unknown. One name per line, a star at the end covers a whole family, for example woocommerce_*.', 'ux-studio' ),
		'effect' => __( 'Tasks of plugins you trust stop appearing in the report, so the real findings stand out.', 'ux-studio' ),
		'risk'   => __( 'A name on this list is never reported, even when malware uses it. Do not add wide patterns such as a single star.', 'ux-studio' ),
	),
);
