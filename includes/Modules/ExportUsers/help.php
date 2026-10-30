<?php
/**
 * Plain-language help of the Export Users settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'export_types'   => array(
		'does'   => __( 'The file formats offered in the export action of a row, in the bulk actions and on the profile page.', 'ux-studio' ),
		'effect' => __( 'Only the chosen formats appear in the menu.', 'ux-studio' ),
	),
	'default_fields' => array(
		'does'   => __( 'The basic data of a user that goes into the exported file, such as name, e-mail and role.', 'ux-studio' ),
		'effect' => __( 'The fewer fields, the less personal data leaves the site.', 'ux-studio' ),
		'risk'   => __( 'The file holds personal data of real people. Under data protection rules (GDPR) you must keep it safe and not share it carelessly.', 'ux-studio' ),
	),
	'meta_fields'    => array(
		'does'   => __( 'Extra values stored with users by plugins and themes (also called user meta), such as phone numbers or billing addresses, that are added to the exported file.', 'ux-studio' ),
		'effect' => __( 'You get the data of your own fields next to the standard ones.', 'ux-studio' ),
		'risk'   => __( 'These fields often hold the most sensitive personal data. Add only what you really need.', 'ux-studio' ),
	),
);
