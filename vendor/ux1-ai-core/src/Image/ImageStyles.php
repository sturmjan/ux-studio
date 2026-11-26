<?php
/**
 * Style presets. Neither provider has a native "style" parameter, so a style is a natural-language suffix appended
 * to the prompt (ported 1:1 from CA).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

final class ImageStyles {

	public const STYLES = array(
		'none'         => array( 'label' => 'Bez stylu', 'suffix' => '' ),
		'photo'        => array( 'label' => 'Fotografie', 'suffix' => ', realistic photograph, natural lighting, high detail' ),
		'illustration' => array( 'label' => 'Ilustrace', 'suffix' => ', digital illustration, clean line art, vibrant colors' ),
		'3d'           => array( 'label' => '3D render', 'suffix' => ', 3D render, soft studio lighting' ),
		'watercolor'   => array( 'label' => 'Akvarel', 'suffix' => ', watercolor painting, soft edges, paper texture' ),
		'anime'        => array( 'label' => 'Anime', 'suffix' => ', anime style illustration, cel shading' ),
		'sketch'       => array( 'label' => 'Skica', 'suffix' => ', pencil sketch, hand-drawn linework' ),
	);

	public static function exists( string $style ): bool {
		return isset( self::STYLES[ $style ] );
	}

	/** Prompt with the style suffix; an unknown style behaves like "none" (as in CA). */
	public static function apply( string $prompt, string $style ): string {
		return $prompt . ( self::STYLES[ $style ]['suffix'] ?? '' );
	}
}
