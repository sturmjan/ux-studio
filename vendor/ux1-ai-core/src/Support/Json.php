<?php
/**
 * JSON encoding that never fails on broken UTF-8 (a cut multibyte character in user text would otherwise make
 * json_encode() return false and send an empty request body).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Support;

final class Json {

	public static function encode( mixed $value ): string {
		$json = json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $json ) {
			throw new \RuntimeException( 'JSON encoding failed: ' . json_last_error_msg() );
		}
		return $json;
	}
}
