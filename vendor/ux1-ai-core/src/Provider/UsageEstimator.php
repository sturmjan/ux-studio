<?php
/**
 * Token estimate for providers that report no usage (~4 characters per token). Without it, usage limits and
 * statistics would never count those calls.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Provider;

final class UsageEstimator {

	public const CHARS_PER_TOKEN = 4;

	/**
	 * @param array<int, array{role?:string,content?:mixed}> $messages Conversation sent.
	 * @return array{input_tokens:int,output_tokens:int}
	 */
	public static function estimate( array $messages, string $system, string $answer ): array {
		$input = mb_strlen( $system );
		foreach ( $messages as $message ) {
			$content = $message['content'] ?? '';
			$input  += mb_strlen( is_string( $content ) ? $content : (string) json_encode( $content ) );
		}
		return array(
			'input_tokens'  => (int) ceil( $input / self::CHARS_PER_TOKEN ),
			'output_tokens' => (int) ceil( mb_strlen( $answer ) / self::CHARS_PER_TOKEN ),
		);
	}
}
