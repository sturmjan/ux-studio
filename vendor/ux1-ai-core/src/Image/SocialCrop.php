<?php
/**
 * Social media export geometry: center-crop rectangle for a target size. Pure math; the pixel work (GD/Imagick)
 * and storage stay in the host. Ported from CA's ImageResizer::cropResize().
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

final class SocialCrop {

	public const PRESETS = array(
		'ig_feed_1x1' => array( 'w' => 1080, 'h' => 1080, 'label' => 'Instagram feed (1:1)' ),
		'ig_feed_4x5' => array( 'w' => 1080, 'h' => 1350, 'label' => 'Instagram feed (4:5)' ),
		'ig_story'    => array( 'w' => 1080, 'h' => 1920, 'label' => 'IG/FB Story (9:16)' ),
		'fb_feed'     => array( 'w' => 1200, 'h' => 630, 'label' => 'Facebook feed' ),
	);

	/**
	 * Largest centered source rectangle with the target aspect ratio. Copy it (resampled) onto a target_w x
	 * target_h canvas, i.e. imagecopyresampled( $dst, $src, 0, 0, x, y, target_w, target_h, w, h ).
	 *
	 * @return array{x:int,y:int,w:int,h:int}
	 * @throws \InvalidArgumentException On non-positive dimensions.
	 */
	public static function crop_rect( int $src_w, int $src_h, int $target_w, int $target_h ): array {
		if ( $src_w <= 0 || $src_h <= 0 || $target_w <= 0 || $target_h <= 0 ) {
			throw new \InvalidArgumentException( 'Image dimensions must be positive.' );
		}

		$src_ratio    = $src_w / $src_h;
		$target_ratio = $target_w / $target_h;

		if ( $src_ratio > $target_ratio ) {
			$crop_h = $src_h;
			$crop_w = (int) round( $src_h * $target_ratio );
			return array(
				'x' => (int) round( ( $src_w - $crop_w ) / 2 ),
				'y' => 0,
				'w' => $crop_w,
				'h' => $crop_h,
			);
		}

		$crop_h = (int) round( $src_w / $target_ratio );
		return array(
			'x' => 0,
			'y' => (int) round( ( $src_h - $crop_h ) / 2 ),
			'w' => $src_w,
			'h' => $crop_h,
		);
	}

	/**
	 * Crop plan for a preset.
	 *
	 * @return array{target_w:int,target_h:int,x:int,y:int,w:int,h:int}
	 * @throws \InvalidArgumentException On an unknown preset.
	 */
	public static function plan( string $preset, int $src_w, int $src_h ): array {
		if ( ! isset( self::PRESETS[ $preset ] ) ) {
			throw new \InvalidArgumentException( 'Unknown social preset: ' . $preset );
		}
		$spec = self::PRESETS[ $preset ];
		return array( 'target_w' => $spec['w'], 'target_h' => $spec['h'] ) + self::crop_rect( $src_w, $src_h, $spec['w'], $spec['h'] );
	}
}
