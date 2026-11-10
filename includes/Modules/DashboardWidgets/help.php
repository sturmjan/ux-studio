<?php
/**
 * Plain-language help of the Dashboard Widgets settings, read by Core\SettingHelp. Format: docs/help-texts.md.
 *
 * The switches of the widgets (`widget_*`) are shown in the details of a row of the widget table, not in a form.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'welcome_panel_title'  => array(
		'does'   => __( 'The heading of the welcome panel at the top of the dashboard. Leave it empty to keep the WordPress heading.', 'ux-studio' ),
		'effect' => __( 'People who open the dashboard first read your greeting, for example the name of your company or of the project.', 'ux-studio' ),
	),
	'disable_all_widgets'  => array(
		'does'   => __( 'Removes every widget of the dashboard and the welcome panel, except the widgets of UX Studio.', 'ux-studio' ),
		'effect' => __( 'The dashboard is almost empty, whatever the table of widgets says. Switch it off to bring the rules of the table back.', 'ux-studio' ),
	),
	'widget_disk_usage'    => array(
		'does'   => __( 'Shows the size of the WordPress files and of the database, and the free disk space.', 'ux-studio' ),
		'effect' => __( 'You see when the hosting runs low on space. The sizes are counted in the background and kept for 6 hours.', 'ux-studio' ),
		'risk'   => __( 'Counting the files of a very large site takes a few minutes of background work after the first switch-on.', 'ux-studio' ),
	),
	'widget_recent_posts'  => array(
		'does'   => __( 'Lists the 5 most recently published posts with their author and date.', 'ux-studio' ),
		'effect' => __( 'A quick way back into the latest articles from the dashboard.', 'ux-studio' ),
	),
	'widget_recent_media'  => array(
		'does'   => __( 'Shows the images uploaded last as a small gallery.', 'ux-studio' ),
		'effect' => __( 'You see at a glance what was uploaded lately and can open any picture.', 'ux-studio' ),
	),
	'widget_tasks'         => array(
		'does'   => __( 'A to-do list on the dashboard: add a task, tick it off, delete it.', 'ux-studio' ),
		'effect' => __( 'Every administrator sees the same list. It is kept in the database of the site.', 'ux-studio' ),
	),
	'widget_notes'         => array(
		'does'   => __( 'A notepad on the dashboard that saves itself a moment after you stop typing.', 'ux-studio' ),
		'effect' => __( 'Every administrator sees and edits the same note. Do not keep passwords in it.', 'ux-studio' ),
		'risk'   => __( 'The note is plain text in the database, readable by every administrator.', 'ux-studio' ),
	),
	'widget_pagespeed'     => array(
		'does'   => __( 'Shows the performance score of the home page that Google PageSpeed measured.', 'ux-studio' ),
		'effect' => __( 'You notice when the home page gets slower. The score is measured on request and kept for an hour.', 'ux-studio' ),
		'off'    => __( 'It stays empty until you add the Google PageSpeed API key in the API keys tab.', 'ux-studio' ),
	),
	'widget_user_activity' => array(
		'does'   => __( 'Lists the last 15 actions from the activity log.', 'ux-studio' ),
		'effect' => __( 'You see who changed what on the site without opening the log.', 'ux-studio' ),
	),
	'widget_recent_forms'  => array(
		'does'   => __( 'Lists the last 10 submissions of Elementor Pro forms.', 'ux-studio' ),
		'effect' => __( 'You read new enquiries from the dashboard.', 'ux-studio' ),
		'off'    => __( 'It works only with Elementor Pro, which keeps its submissions.', 'ux-studio' ),
	),
	'widget_analytics'     => array(
		'does'   => __( 'Shows the visits of today, of this month and of this year, and a 7-day chart from Google Analytics 4.', 'ux-studio' ),
		'effect' => __( 'You see how many people visit without leaving WordPress.', 'ux-studio' ),
		'off'    => __( 'It appears only after you add the GA4 property ID and the service-account JSON in the API keys tab.', 'ux-studio' ),
	),
	'widget_service_requests' => array(
		'does'   => __( 'Shows the latest service requests on the dashboard.', 'ux-studio' ),
		'effect' => __( 'You notice a new request as soon as you log in. Needs the Service Requests module.', 'ux-studio' ),
	),
	'widget_blocked_users' => array(
		'does'   => __( 'Shows the IP addresses that are blocked now and when each block ends.', 'ux-studio' ),
		'effect' => __( 'You see at once whether someone is attacking the login. Needs the Security module.', 'ux-studio' ),
	),
	'widget_forms'         => array(
		'does'   => __( 'Shows how many form submissions are unread and the latest ones.', 'ux-studio' ),
		'effect' => __( 'You do not miss an enquiry. Needs the Form Builder module.', 'ux-studio' ),
	),
	'widget_bot_throttle'  => array(
		'does'   => __( 'Shows how much bot traffic reached the site and how many bots were slowed down or banned.', 'ux-studio' ),
		'effect' => __( 'You see whether the throttling is doing anything. Needs the Bot Throttle module.', 'ux-studio' ),
	),
	'widget_dns_manager'   => array(
		'does'   => __( 'Lists the latest changes to the DNS records.', 'ux-studio' ),
		'effect' => __( 'You see who changed the DNS and when. Needs the DNS Management module.', 'ux-studio' ),
	),
	'widget_ai_panel'      => array(
		'does'   => __( 'Shows whether an AI agent has temporary remote access to the site.', 'ux-studio' ),
		'effect' => __( 'You always know when someone can reach the site remotely. Needs the AI Panel module.', 'ux-studio' ),
	),
	'widget_recent_orders' => array(
		'does'   => __( 'Lists the 10 most recent WooCommerce orders with their status.', 'ux-studio' ),
		'effect' => __( 'You see new orders on the dashboard. Needs WooCommerce.', 'ux-studio' ),
	),
	'widget_top_products'  => array(
		'does'   => __( 'Lists the best-selling WooCommerce products by quantity sold.', 'ux-studio' ),
		'effect' => __( 'You see what sells best. Needs WooCommerce.', 'ux-studio' ),
	),
);
