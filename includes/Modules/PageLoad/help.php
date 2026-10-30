<?php
/**
 * Plain-language help of the Page Load settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'sample_rate'       => array(
		'does'   => __( 'Out of 100 visits to the public pages, this many are timed and written to the log. With 10, about every tenth page view is measured.', 'ux-studio' ),
		'effect' => __( 'A low number gives a good picture of the speed with almost no extra work for the server. Only normal page views are measured, not the administration and not background requests.', 'ux-studio' ),
		'risk'   => __( 'At 100 every page view writes a row to the database, which on a busy site fills the log quickly and adds load.', 'ux-studio' ),
		'off'    => __( 'Set 0 to stop measuring.', 'ux-studio' ),
	),
	'retention_days'    => array(
		'does'   => __( 'Measurements older than this number of days are deleted automatically.', 'ux-studio' ),
		'effect' => __( 'The log stays small and the database does not grow without end. Deleted measurements cannot be recovered.', 'ux-studio' ),
		'risk'   => __( 'A very high number on a busy site makes the table large.', 'ux-studio' ),
	),
	'admin_bar_enabled' => array(
		'does'   => __( 'Adds the load time of the page you are looking at, the number of database queries and the highest memory use to the black bar at the top. Only logged-in administrators see it.', 'ux-studio' ),
		'effect' => __( 'You see at a glance whether a page is slow. Visitors see nothing.', 'ux-studio' ),
		'off'    => __( 'Turn it off if you do not want the extra item in the bar.', 'ux-studio' ),
	),
	'benchmark_enabled' => array(
		'does'   => __( 'When you turn a plugin on or off, the module measures the load time of the front page before and after and compares the average.', 'ux-studio' ),
		'effect' => __( 'You can see roughly how much a plugin slows the site down.', 'ux-studio' ),
		'risk'   => __( 'A few seconds after the change a background task opens the front page several times, so the site does a little extra work for a moment. It needs scheduled tasks to run. The result is only an estimate: caching, server load and chance change the numbers.', 'ux-studio' ),
		'off'    => __( 'Turn it off on a weak server or when you often switch several plugins in a row.', 'ux-studio' ),
	),
	'benchmark_count'   => array(
		'does'   => __( 'How many times the front page is opened for each measurement. The average of them is the result. You can choose from 1 to 10.', 'ux-studio' ),
		'effect' => __( 'More requests give a steadier result, but the measuring takes longer and puts more load on the site.', 'ux-studio' ),
		'off'    => __( 'The default of 3 is a good compromise.', 'ux-studio' ),
	),
);
