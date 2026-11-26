<?php
/**
 * Failure of a provider call that carries structured detail next to the (unchanged, English) message, so a host can
 * build its own localized message without parsing text. Still a \RuntimeException: existing catch blocks keep working.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Provider;

class ProviderException extends \RuntimeException {

	/**
	 * @param int         $status      HTTP status of the failed response (0 = no response).
	 * @param string|null $api_message Error message extracted from the API's JSON body (null when absent).
	 */
	public function __construct(
		string $message,
		public readonly int $status = 0,
		public readonly ?string $api_message = null,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	/** True when the request never got an HTTP response (DNS, TLS, timeout...). */
	public function is_transport_error(): bool {
		return $this->getPrevious() instanceof \Ux1\AiCore\Http\HttpException;
	}
}
