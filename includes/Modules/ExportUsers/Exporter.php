<?php
/**
 * CSV / JSON / TXT export streaming for the Export Users module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ExportUsers;

defined( 'ABSPATH' ) || exit;

/**
 * Streams a prepared export dataset (headers + body) to the browser as a file
 * download. Formatting ported 1:1 from the legacy export formatters.
 */
final class Exporter {

	/**
	 * Content types per export format.
	 */
	private const CONTENT_TYPES = array(
		'csv'  => 'text/csv',
		'json' => 'application/json',
		'txt'  => 'text/plain',
	);

	/**
	 * Stream the dataset as a download and terminate the request.
	 *
	 * @param array{headers:array,body:array} $data     Prepared dataset.
	 * @param string                          $type     Export type (csv|json|txt).
	 * @param string                          $filename Download filename.
	 * @param string[]                        $keys     Field keys (used by JSON).
	 */
	public function stream( array $data, string $type, string $filename, array $keys ): void {
		$content_type = self::CONTENT_TYPES[ $type ] ?? 'text/plain';

		nocache_headers();
		header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		switch ( $type ) {
			case 'json':
				echo $this->format_json( $data, $keys ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'txt':
				echo $this->format_txt( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'csv':
			default:
				$this->format_csv( $data );
				break;
		}

		exit;
	}

	/**
	 * Write CSV directly to the output stream.
	 *
	 * @param array{headers:array,body:array} $data Prepared dataset.
	 */
	private function format_csv( array $data ): void {
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			return;
		}

		fputcsv( $output, (array) $data['headers'] );

		foreach ( (array) $data['body'] as $row ) {
			fputcsv( $output, (array) $row );
		}

		fclose( $output );
	}

	/**
	 * Build the JSON payload.
	 *
	 * @param array{headers:array,body:array} $data Prepared dataset.
	 * @param string[]                        $keys Field keys.
	 */
	private function format_json( array $data, array $keys ): string {
		if ( array() !== $keys ) {
			$json_data = array();
			foreach ( (array) $data['body'] as $row ) {
				$json_data[] = array_combine( $keys, (array) $row );
			}
		} else {
			$json_data = array(
				'headers' => $data['headers'],
				'body'    => $data['body'],
			);
		}

		return (string) wp_json_encode( $json_data, JSON_PRETTY_PRINT );
	}

	/**
	 * Build the plain-text payload (one "label: value" line per field).
	 *
	 * @param array{headers:array,body:array} $data Prepared dataset.
	 */
	private function format_txt( array $data ): string {
		if ( empty( $data['body'] ) ) {
			return '';
		}

		$output  = '';
		$headers = (array) $data['headers'];

		foreach ( (array) $data['body'] as $row ) {
			foreach ( $headers as $index => $label ) {
				$value   = isset( $row[ $index ] ) ? esc_html( (string) $row[ $index ] ) : '';
				$output .= sprintf( "%s: %s\n", esc_html( (string) $label ), $value );
			}
			$output .= "\n";
		}

		return $output;
	}
}
