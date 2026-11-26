<?php
/**
 * Failure of a Claude Code Bridge call with the bridge's own structured detail (the status mapping lives in the
 * core, every host only translates the message).
 *
 * reason: config | transport | approval | rate_limit | forbidden | unavailable | unauthorized | http | empty
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Provider;

final class BridgeException extends ProviderException {

	/**
	 * @param string $reason      Machine-readable class of the failure (see above).
	 * @param string $error_code  Bridge error code from the response body (quota_exceeded, ip_not_allowed...).
	 * @param int    $retry_after Seconds until the request may be retried (0 = unknown).
	 * @param string $approve_url Where to approve the connection (202 only).
	 */
	public function __construct(
		string $message,
		public readonly string $reason,
		int $status = 0,
		?string $api_message = null,
		public readonly string $error_code = '',
		public readonly int $retry_after = 0,
		public readonly string $approve_url = '',
		?\Throwable $previous = null
	) {
		parent::__construct( $message, $status, $api_message, $previous );
	}
}
