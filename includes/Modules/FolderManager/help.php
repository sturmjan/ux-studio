<?php
/**
 * Plain-language help of the Folder Manager settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'post_types'         => array(
		'does'   => __( 'The chosen content types get a tree of folders next to their list. Every type has its own independent folders.', 'ux-studio' ),
		'effect' => __( 'You can sort media files, pages or posts into folders. The folders exist only in the administration, the addresses of the files do not change.', 'ux-studio' ),
	),
	'show_folder_counts' => array(
		'does'   => __( 'Shows the number of items next to the name of each folder.', 'ux-studio' ),
		'effect' => __( 'You see at once how full a folder is.', 'ux-studio' ),
		'off'    => __( 'Turn it off if counting makes the list slow on a site with a huge number of items.', 'ux-studio' ),
	),
);
