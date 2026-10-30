<?php
/**
 * Plain-language help of the SVG Upload settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'allowed_roles' => array(
		'does'   => __( 'Only users with these roles may upload SVG files (vector images such as logos and icons). By default only administrators.', 'ux-studio' ),
		'effect' => __( 'Every uploaded SVG is checked and cleaned before it is saved. Other roles still get the usual message that the type is not allowed.', 'ux-studio' ),
		'risk'   => __( 'An SVG is a text file that can hide scripts. The cleaning removes them, but no filter is perfect, so give the permission only to people you trust.', 'ux-studio' ),
		'off'    => __( 'Switch the module off if you do not need SVG files.', 'ux-studio' ),
	),
);
