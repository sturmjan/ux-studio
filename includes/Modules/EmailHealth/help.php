<?php
/**
 * Plain-language help of the Email Health settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'test_recipient' => array(
		'does'   => __( 'The e-mail address that receives the test message. Empty uses the address of the site administrator.', 'ux-studio' ),
		'effect' => __( 'Use a mailbox you really read, so you notice when the test does not arrive.', 'ux-studio' ),
	),
	'auto_test'      => array(
		'does'   => __( 'Sends the test message by itself at the interval below and records the result.', 'ux-studio' ),
		'effect' => __( 'You find out about broken sending before customers do. Each test is one real message to the recipient.', 'ux-studio' ),
		'risk'   => __( 'A short interval fills the mailbox with test messages, and some mail services count them toward your daily limit. The test runs when somebody visits the site, so on a quiet site it can be late.', 'ux-studio' ),
		'off'    => __( 'Leave it off if you test by hand whenever you change something.', 'ux-studio' ),
	),
	'test_interval'  => array(
		'does'   => __( 'How often the scheduled test message is sent.', 'ux-studio' ),
		'effect' => __( 'Once a day is enough for most sites. A shorter interval finds problems sooner but sends more messages.', 'ux-studio' ),
	),
);
