<?php
/**
 * Plain-language help of the Export Posts settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'export_types'   => array(
		'does'   => __( 'The file formats offered in the export action of a row and in the bulk actions of the list.', 'ux-studio' ),
		'effect' => __( 'Only the chosen formats appear in the menu.', 'ux-studio' ),
	),
	'default_fields' => array(
		'does'   => __( 'The basic data of a post that goes into the exported file, such as title, date, status and address.', 'ux-studio' ),
		'effect' => __( 'The fewer fields, the smaller and easier to read the file.', 'ux-studio' ),
		'risk'   => __( 'An exported file may contain drafts and private content. Keep it safe and do not share it.', 'ux-studio' ),
	),
	'meta_fields'    => array(
		'does'   => __( 'Custom fields of posts (extra values stored by plugins and themes, also called post meta) that are added to the exported file.', 'ux-studio' ),
		'effect' => __( 'You get the data of your own fields next to the standard ones.', 'ux-studio' ),
		'risk'   => __( 'Custom fields can contain private data, for example e-mail addresses from forms or internal notes. Add only what you need.', 'ux-studio' ),
	),
);
