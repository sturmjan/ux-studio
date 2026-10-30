<?php
/**
 * Plain-language help of the Image Optimizer settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'quality'            => array(
		'does'   => __( 'Sets how strongly images are compressed. A lower number gives a smaller file and a slightly less sharp picture.', 'ux-studio' ),
		'effect' => __( 'Pages load faster because the images weigh less. Values between 75 and 85 are almost impossible to tell apart from the original.', 'ux-studio' ),
		'risk'   => __( 'Below about 60 you start to see blocks and blur, mainly in photos with fine detail.', 'ux-studio' ),
		'off'    => __( 'Raise it for portfolios and product photos where every detail counts.', 'ux-studio' ),
	),
	'jpeg_quality'       => array(
		'does'   => __( 'Compression strength for JPEG files only. With 0 the general quality is used.', 'ux-studio' ),
		'effect' => __( 'Lets you treat JPEG differently from the newer formats without changing the general value.', 'ux-studio' ),
		'off'    => __( 'Leave 0 unless you have a reason to compress JPEG differently.', 'ux-studio' ),
	),
	'webp_quality'       => array(
		'does'   => __( 'Compression strength for the [[WebP]] copies only. With 0 the general quality is used.', 'ux-studio' ),
		'effect' => __( 'WebP at the same number is usually smaller than JPEG, so you can keep the default.', 'ux-studio' ),
		'off'    => __( 'Leave 0 unless you have a reason to compress WebP differently.', 'ux-studio' ),
	),
	'avif_quality'       => array(
		'does'   => __( 'Compression strength for the [[AVIF]] copies only. With 0 the general quality is used.', 'ux-studio' ),
		'effect' => __( 'AVIF looks the same as JPEG at a lower number, so a value around 60 to 70 is often enough and gives much smaller files.', 'ux-studio' ),
		'off'    => __( 'Leave 0 unless you have a reason to compress AVIF differently.', 'ux-studio' ),
	),
	'max_width'          => array(
		'does'   => __( 'Images wider than this number of pixels are shrunk to this width when they are optimized. The proportions stay the same.', 'ux-studio' ),
		'effect' => __( 'Huge camera photos no longer waste disk space and bandwidth. The optimized file replaces the one in the media library, so it is smaller than what you uploaded.', 'ux-studio' ),
		'risk'   => __( 'The full-size file is replaced by the smaller one. The untouched original is kept next to it as a backup and Restore brings it back, but only until someone deletes that backup file. Do not set it below the widest place the image is shown, or it will look blurry there.', 'ux-studio' ),
		'off'    => __( 'Set 0 when you need the full resolution, for example for print or downloads.', 'ux-studio' ),
	),
	'max_height'         => array(
		'does'   => __( 'Images taller than this number of pixels are shrunk to this height. It works together with the maximum width and the proportions stay the same.', 'ux-studio' ),
		'effect' => __( 'Limits very tall images such as long screenshots. With 0 only the width limit applies.', 'ux-studio' ),
		'risk'   => __( 'The same as with the width: the full-size file is replaced by the smaller one, the original stays as a backup file.', 'ux-studio' ),
		'off'    => __( 'Leave 0 unless you upload very tall images.', 'ux-studio' ),
	),
	'convert_png_to_jpg' => array(
		'does'   => __( 'PNG images that have no transparency are saved as JPEG, which is usually much smaller.', 'ux-studio' ),
		'effect' => __( 'Photos and screenshots saved as PNG shrink a lot. Images with transparency are left alone.', 'ux-studio' ),
		'risk'   => __( 'The file name changes from .png to .jpg. Links to an already published PNG (in e-mails, on other sites, in old content) stop working. Sharp graphics with flat colors, such as logos or text, can look slightly worse as JPEG. The PNG is kept as a backup and Restore brings it back.', 'ux-studio' ),
		'off'    => __( 'Leave it off for existing sites with many published PNG files. It is safest together with optimizing new uploads automatically.', 'ux-studio' ),
	),
	'convert_to_webp'    => array(
		'does'   => __( 'Next to every optimized image it creates a copy in the [[WebP]] format.', 'ux-studio' ),
		'effect' => __( 'Browsers that understand WebP get a file that is roughly a quarter smaller than JPEG. The original stays in the media library, the copy is only an extra file.', 'ux-studio' ),
		'risk'   => __( 'The copies use disk space. If your server cannot create WebP, nothing is generated and no error appears on the site.', 'ux-studio' ),
		'off'    => __( 'Turn it off only when disk space is very tight.', 'ux-studio' ),
	),
	'convert_to_avif'    => array(
		'does'   => __( 'Next to every optimized image it creates a copy in the [[AVIF]] format.', 'ux-studio' ),
		'effect' => __( 'AVIF files are even smaller than WebP, so pages load faster in modern browsers.', 'ux-studio' ),
		'risk'   => __( 'Creating AVIF is slow and needs a lot of server memory. On weak hosting it can make optimizing time out. If the server cannot encode AVIF, the setting is ignored.', 'ux-studio' ),
		'off'    => __( 'Leave it off on shared hosting with strict limits.', 'ux-studio' ),
	),
	'auto_optimize'      => array(
		'does'   => __( 'Every image you upload is compressed, and the extra formats are created, right after the upload.', 'ux-studio' ),
		'effect' => __( 'You do not have to remember to optimize. Uploading a big photo takes a bit longer.', 'ux-studio' ),
		'risk'   => __( 'Together with the maximum size and the PNG conversion it changes every new upload. Uploads of many images at once can be slow.', 'ux-studio' ),
		'off'    => __( 'Turn it off when you prefer to optimize images by hand, in batches.', 'ux-studio' ),
	),
	'htaccess_delivery'  => array(
		'does'   => __( 'Writes a few rules into the .htaccess file in the uploads folder. A browser that understands AVIF or WebP then receives that smaller copy of an image automatically, under the same address.', 'ux-studio' ),
		'effect' => __( 'The images in your content do not have to change. Visitors with modern browsers download less data.', 'ux-studio' ),
		'risk'   => __( 'This changes a server file. The rules sit in their own marked block, other rules in that file are kept, and the block is removed when you turn the setting off. It works only on Apache and LiteSpeed. On nginx it does nothing, and a CDN in front of the site may cache the wrong format.', 'ux-studio' ),
		'off'    => __( 'Turn it off on nginx, or when a [[CDN]] or another plugin already serves the next-generation images.', 'ux-studio' ),
	),
);
