<?php
/**
 * The "generate_image" chat tool, shared by both hosts so the AI chat in UX1 Studio and in UX1 Network app offers
 * image generation the same way. The host decides whether the tool is offered at all (setting "allow images in
 * chat", off by default), pays/charges for it and stores the result; the core only defines the tool and turns the
 * model's arguments into an ImageRequest.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Image;

final class ImageTool {

	public const NAME = 'generate_image';

	/** Ratios offered to the model; every provider supports these (unsupported ones are coerced by from_input()). */
	public const ASPECT_RATIOS = array( '1:1', '16:9', '9:16', '4:3', '3:4' );

	/**
	 * Tool definition in the core's provider-neutral format (name, description, JSON-schema parameters), as accepted
	 * by TextProvider::chat_with_tools().
	 *
	 * @return array{name:string,description:string,parameters:array<string,mixed>}
	 */
	public static function definition(): array {
		return array(
			'name'        => self::NAME,
			'description' => 'Generate an image from a text description and show it to the user. Use it only when the user '
				. 'explicitly asks for an image, picture, illustration or photo. Describe the image in English in the prompt, '
				. 'concretely (subject, setting, composition, mood). Never put text, logos or real people\'s faces in it.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'prompt'       => array(
						'type'        => 'string',
						'description' => 'Detailed description of the image, in English.',
					),
					'style'        => array(
						'type'        => 'string',
						'enum'        => array_keys( ImageStyles::STYLES ),
						'description' => 'Visual style; "none" when the user did not ask for one.',
					),
					'aspect_ratio' => array(
						'type'        => 'string',
						'enum'        => self::ASPECT_RATIOS,
						'description' => 'Image shape: 1:1 square, 16:9 wide (web banner), 9:16 tall (story), 4:3 or 3:4.',
					),
				),
				'required'   => array( 'prompt' ),
			),
		);
	}

	/**
	 * Turns the tool arguments into a request the provider can run. Chat images use the provider's cheaper defaults
	 * (1K, medium quality, preview tier where one exists) unless the host overrides them.
	 *
	 * @param array<string, mixed> $arguments    Tool-call arguments from the model.
	 * @param array<string, mixed> $capabilities ImageProvider::get_capabilities().
	 * @param array<string, mixed> $defaults     Host overrides, e.g. array( 'tier' => 'final', 'resolution' => '2K' ).
	 * @throws \InvalidArgumentException On a missing, empty or too long prompt.
	 */
	public static function to_request( array $arguments, array $capabilities, array $defaults = array() ): ImageRequest {
		$input = array_merge(
			array(
				'resolution' => '1K',
				'quality'    => 'medium',
				'tier'       => 'preview',
			),
			$defaults,
			array(
				'prompt'       => trim( (string) ( $arguments['prompt'] ?? '' ) ),
				'style'        => (string) ( $arguments['style'] ?? 'none' ),
				'aspect_ratio' => (string) ( $arguments['aspect_ratio'] ?? '1:1' ),
			)
		);
		return ImageRequest::from_input( $input, $capabilities );
	}
}
