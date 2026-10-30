<?php
/**
 * Plain-language help of the DNS Manager settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'manage_user_ids' => array(
		'does'   => __( 'Chooses which administrators may add, change, delete and revert [[dns|DNS records]] of the domain. The other administrators can only look at them. By default nobody is chosen.', 'ux-studio' ),
		'effect' => __( 'The settings that say where the domain points are in the hands of a few people instead of everyone with an administrator account.', 'ux-studio' ),
		'risk'   => __( 'A wrong record can take down the website and the e-mail of the whole domain. Choose only people who know what they are doing.', 'ux-studio' ),
		'off'    => __( 'Leave it empty if nobody should change DNS from the administration.', 'ux-studio' ),
	),
);
