<?php
/**
 * Fetches URLs/sitemaps and converts HTML to plain text for RAG training.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

defined( 'ABSPATH' ) || exit;

final class WebCrawler {

	private const USER_AGENT = 'UxStudio-AI-Assistant/1.0 (+WordPress)';
	private const TIMEOUT    = 30;

	/**
	 * Fetch one URL and return its extracted title/text.
	 *
	 * @return array{title:string,text:string,url:string,success:bool,error?:string}
	 */
	public function crawl_url( string $url ): array {
		$result = array( 'title' => '', 'text' => '', 'url' => $url, 'success' => false );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => self::USER_AGENT,
				'sslverify'  => true,
				'headers'    => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			return $result;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$result['error'] = "HTTP {$code}";
			return $result;
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$body         = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			$result['error'] = __( 'Empty response.', 'ux-studio' );
			return $result;
		}

		if ( false !== stripos( (string) $content_type, 'application/pdf' ) ) {
			$result['error'] = __( 'PDF URLs are not supported here; upload the PDF as a document instead.', 'ux-studio' );
			return $result;
		}

		$result['title']   = $this->extract_title( $body );
		$result['text']    = $this->html_to_text( $body );
		$result['success'] = ! empty( $result['text'] );

		if ( ! $result['success'] ) {
			$result['error'] = __( 'Could not extract text from the page.', 'ux-studio' );
		}

		return $result;
	}

	/**
	 * Parse a sitemap (or sitemap index) and return the list of URLs.
	 *
	 * @return array<int, string>
	 */
	public function parse_sitemap( string $sitemap_url, int $depth = 0 ): array {
		if ( $depth > 3 ) {
			return array();
		}

		$response = wp_remote_get( $sitemap_url, array( 'timeout' => self::TIMEOUT, 'user-agent' => self::USER_AGENT ) );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		libxml_use_internal_errors( false );

		if ( false === $xml ) {
			return array();
		}

		$urls = array();
		foreach ( $xml->url as $url_node ) {
			$loc = (string) ( $url_node->loc ?? '' );
			if ( '' !== $loc ) {
				$urls[] = $loc;
			}
		}
		foreach ( $xml->sitemap as $sitemap_node ) {
			$loc = (string) ( $sitemap_node->loc ?? '' );
			if ( '' !== $loc ) {
				$urls = array_merge( $urls, $this->parse_sitemap( $loc, $depth + 1 ) );
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Extract <title> (falling back to the first <h1>).
	 */
	private function extract_title( string $html ): string {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/si', $html, $matches ) ) {
			return html_entity_decode( trim( $matches[1] ), ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/si', $html, $matches ) ) {
			return html_entity_decode( wp_strip_all_tags( trim( $matches[1] ) ), ENT_QUOTES, 'UTF-8' );
		}
		return '';
	}

	/**
	 * Convert HTML to plain text, preferring <article>/<main>/[role=main]
	 * content and stripping nav/header/footer noise.
	 */
	private function html_to_text( string $html ): string {
		$html = (string) preg_replace( '/<(script|style|noscript|svg|iframe)[^>]*>.*?<\/\1>/si', '', $html );
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );

		$main_content = $this->extract_main_content( $html );
		if ( '' !== $main_content ) {
			$html = $main_content;
		} else {
			$html = (string) preg_replace( '/<(header|nav|footer|aside)[^>]*>.*?<\/\1>/si', '', $html );
		}

		$html = (string) preg_replace( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/si', "\n\n$1\n\n", $html );
		$html = (string) preg_replace( '/<(p|div|br|li|tr)[^>]*\/?>/si', "\n", $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[^\S\n]+/', ' ', $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = trim( $text );

		return mb_strlen( $text ) < 50 ? '' : $text;
	}

	/**
	 * Try to isolate the main content region of the page.
	 */
	private function extract_main_content( string $html ): string {
		$patterns = array(
			'/<article[^>]*>(.*?)<\/article>/si',
			'/<main[^>]*>(.*?)<\/main>/si',
			'/<div[^>]*role=["\']main["\'][^>]*>(.*?)<\/div>/si',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $html, $matches ) && mb_strlen( wp_strip_all_tags( $matches[1] ) ) > 100 ) {
				return $matches[1];
			}
		}

		return '';
	}
}
