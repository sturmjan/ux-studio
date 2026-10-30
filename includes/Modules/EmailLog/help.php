<?php
/**
 * Plain-language help of the Email Log settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'retention_days' => array(
		'does'   => __( 'How many days the messages stay in the log. Older ones are deleted automatically.', 'ux-studio' ),
		'effect' => __( 'The log holds the full text of every message, so a long period makes the database grow and keeps personal data for longer.', 'ux-studio' ),
		'risk'   => __( 'Deleted messages cannot be restored or sent again. Messages often contain addresses, names and password reset links; keep this short.', 'ux-studio' ),
	),
	'detect_source'  => array(
		'does'   => __( 'Works out which plugin, theme or must-use plugin sent each message and shows it in the Source column.', 'ux-studio' ),
		'effect' => __( 'When a strange message appears, you can see who sent it. This costs a little time on every message.', 'ux-studio' ),
		'off'    => __( 'Turn it off on a site that sends a lot of mail, or when you do not need to know the sender.', 'ux-studio' ),
	),
);
