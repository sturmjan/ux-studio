<?php
/**
 * Plain-language help of the Auto Image Upload settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'server_filter' => array(
		'does'   => __( 'When you save a post, the plugin looks for images that point to other websites, downloads them into your media library and swaps the address in the content. It also works for content made in page builders, custom fields and direct edits.', 'ux-studio' ),
		'effect' => __( 'The images belong to your site, so they stay when the other site removes them or goes down.', 'ux-studio' ),
		'risk'   => __( 'Saving rewrites the content automatically and a single click cannot undo it, so keep a backup. Saving a post with many external images is slower. Make sure you have the right to use the images you copy.', 'ux-studio' ),
		'off'    => __( 'Turn it off when you want external images to stay linked, or when content is imported in bulk and the saves should be fast.', 'ux-studio' ),
	),
	'max_width'     => array(
		'does'   => __( 'Downloaded images wider than this number of pixels are shrunk to it. The proportions stay the same.', 'ux-studio' ),
		'effect' => __( 'Saves disk space and makes pages lighter.', 'ux-studio' ),
		'risk'   => __( 'The original size is not kept, the shrunk file is all you get. Set it too low and images look blurry on large screens.', 'ux-studio' ),
		'off'    => __( 'Set 0 to keep the images in their full size.', 'ux-studio' ),
	),
	'max_height'    => array(
		'does'   => __( 'Downloaded images taller than this number of pixels are shrunk to it. It works together with the largest width.', 'ux-studio' ),
		'effect' => __( 'Limits very tall images. With 0 only the width limit applies.', 'ux-studio' ),
		'risk'   => __( 'The original size is not kept, the shrunk file is all you get.', 'ux-studio' ),
		'off'    => __( 'Leave 0 unless you meet very tall images.', 'ux-studio' ),
	),
);
