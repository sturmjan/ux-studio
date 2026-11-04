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
	'thumbnail_sizes'    => array(
		'does'   => __( 'WordPress makes several smaller copies of every image, for example for lists, galleries and mobile screens. This setting also compresses those copies and creates their WebP and AVIF versions.', 'ux-studio' ),
		'effect' => __( 'Pages use the small copies much more often than the full-size image, so this is where most of the saved data comes from. Without it browsers would still download the old, heavy small copies.', 'ux-studio' ),
		'risk'   => __( 'Every size is backed up next to the file before it is compressed, so the media folder grows. Optimizing many sizes of many images takes longer.', 'ux-studio' ),
		'off'    => __( 'Leave it empty to process all sizes. Pick only some sizes when you want to save disk space or time and the other sizes are rarely shown.', 'ux-studio' ),
	),
	'convert_heic'       => array(
		'does'   => __( 'Photos taken with an iPhone are often saved as HEIC, a format most browsers cannot show. With this on, such a file is turned into a JPEG while it is uploaded.', 'ux-studio' ),
		'effect' => __( 'Editors can upload photos straight from the phone and they show up on the site. The media library only contains the JPEG.', 'ux-studio' ),
		'risk'   => __( 'It only works when the server can read HEIC. When it cannot, the upload stays as it was. The HEIC file is deleted after the conversion, so the original HEIC is not kept.', 'ux-studio' ),
		'off'    => __( 'Leave it off when nobody uploads photos from iPhones.', 'ux-studio' ),
	),
	'excluded'           => array(
		'does'   => __( 'Images listed here are skipped by automatic optimization and by the bulk run. Write one attachment ID or one file name per line. A star stands for any text, so logo-*.png matches every logo file.', 'ux-studio' ),
		'effect' => __( 'These images stay exactly as they were uploaded. In the media library they are marked as excluded.', 'ux-studio' ),
		'risk'   => __( 'You can still optimize an excluded image by hand with its Optimize button. A wrong pattern can leave more images untouched than you want.', 'ux-studio' ),
		'off'    => __( 'Leave it empty to optimize everything. Use it for logos, print files or images that must keep every pixel.', 'ux-studio' ),
	),
	'delivery_mode'      => array(
		'does'   => __( 'Decides how visitors receive the smaller AVIF and WebP copies. The .htaccess way writes a few rules into the .htaccess file in the uploads folder, so the same image address returns the smaller file. The picture tag way changes the HTML of the page so the browser chooses the best file itself.', 'ux-studio' ),
		'effect' => __( 'Visitors with modern browsers download less data. The picture tag way needs no server rules, so it also works on nginx and behind a [[CDN]]. Only copies that really exist on disk are offered.', 'ux-studio' ),
		'risk'   => __( 'The .htaccess way changes a server file, works only on Apache and LiteSpeed, and a CDN in front of the site may cache the wrong format. The rules sit in their own marked block, other rules in that file are kept, and the block is removed when you switch it off. The picture tag way adds an extra element around images, so a theme style that targets the image directly can look different, and it does not change images in places that bypass the normal content, such as CSS backgrounds.', 'ux-studio' ),
		'off'    => __( 'Choose Off when another plugin or your CDN already serves next-generation images.', 'ux-studio' ),
	),
);
