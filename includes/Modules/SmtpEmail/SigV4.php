<?php
/**
 * AWS Signature Version 4 (no SDK).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SmtpEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Signs one request the way AWS documents it: canonical request, string to sign, derived signing key, signature. Pure
 * functions, tested against the vectors of the AWS documentation and of its signature test suite.
 */
final class SigV4 {

	/**
	 * Sign a request.
	 *
	 * @param string               $method     HTTP method.
	 * @param string               $path       Absolute URI path (already encoded as it is sent), `/` for the root.
	 * @param string               $query      Canonical query string (sorted, encoded), '' for none.
	 * @param array<string,string> $headers    Headers that are signed (name => value). `host` and `x-amz-date` must be among them.
	 * @param string               $payload    Request body.
	 * @param string               $access_key Access key id.
	 * @param string               $secret_key Secret access key.
	 * @param string               $region     Region, for example `eu-central-1`.
	 * @param string               $service    Service, for example `ses`.
	 * @param string               $amz_date   `YYYYMMDDTHHMMSSZ` (UTC).
	 * @return array{authorization:string,signature:string,canonical:string,string_to_sign:string,signed_headers:string,scope:string}
	 */
	public static function sign( string $method, string $path, string $query, array $headers, string $payload, string $access_key, string $secret_key, string $region, string $service, string $amz_date ): array {
		$canonical_headers = array();
		foreach ( $headers as $name => $value ) {
			// Values: leading and trailing blanks removed, runs of blanks folded.
			$canonical_headers[ strtolower( $name ) ] = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
		}
		ksort( $canonical_headers );

		$header_block   = '';
		foreach ( $canonical_headers as $name => $value ) {
			$header_block .= $name . ':' . $value . "\n";
		}
		$signed_headers = implode( ';', array_keys( $canonical_headers ) );

		$canonical = implode(
			"\n",
			array(
				strtoupper( $method ),
				'' === $path ? '/' : $path,
				$query,
				$header_block,
				$signed_headers,
				hash( 'sha256', $payload ),
			)
		);

		$date  = substr( $amz_date, 0, 8 );
		$scope = $date . '/' . $region . '/' . $service . '/aws4_request';

		$string_to_sign = implode(
			"\n",
			array(
				'AWS4-HMAC-SHA256',
				$amz_date,
				$scope,
				hash( 'sha256', $canonical ),
			)
		);

		$signature = hash_hmac( 'sha256', $string_to_sign, self::signing_key( $secret_key, $date, $region, $service ) );

		return array(
			'authorization'  => 'AWS4-HMAC-SHA256 Credential=' . $access_key . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature,
			'signature'      => $signature,
			'canonical'      => $canonical,
			'string_to_sign' => $string_to_sign,
			'signed_headers' => $signed_headers,
			'scope'          => $scope,
		);
	}

	/**
	 * Derived signing key (raw bytes).
	 *
	 * @param string $secret_key Secret access key.
	 * @param string $date       `YYYYMMDD`.
	 * @param string $region     Region.
	 * @param string $service    Service.
	 */
	public static function signing_key( string $secret_key, string $date, string $region, string $service ): string {
		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}
}
