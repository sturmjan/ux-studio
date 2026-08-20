<?php
/**
 * AI-assisted FAQ generation: from past chat conversations (analyze) or from
 * a free-form topic/instruction (generate).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy ai-assistant module's FaqAnalyzer.
 */
final class FaqAnalyzer {

	private const MAX_TRANSCRIPT_CHARS = 16000;

	/**
	 * Analyze the last N public-chat conversations and propose new FAQs,
	 * deduplicated against the existing FAQ set.
	 *
	 * @return array{success:bool,suggestions?:array<int,array{question:string,answer:string,category:string}>,analyzed?:int,error?:string}
	 */
	public function analyze( int $conv_limit = 60, int $max_suggestions = 10 ): array {
		global $wpdb;

		$conv_limit      = max( 1, min( $conv_limit, 300 ) );
		$max_suggestions = max( 1, min( $max_suggestions, 30 ) );

		$table = "{$wpdb->prefix}uxstudio_ai_assistant_conversations";
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT messages FROM {$table}
				 WHERE messages IS NOT NULL AND messages != ''
				 ORDER BY created_at DESC
				 LIMIT %d",
				$conv_limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array( 'success' => false, 'error' => __( 'There are no conversations to analyze yet.', 'ux-studio' ) );
		}

		$questions = array();
		$analyzed  = 0;
		foreach ( $rows as $row ) {
			$messages = json_decode( (string) $row['messages'], true );
			if ( ! is_array( $messages ) ) {
				continue;
			}
			++$analyzed;
			foreach ( $messages as $m ) {
				$role    = is_array( $m ) ? ( $m['role'] ?? '' ) : '';
				$content = is_array( $m ) ? ( $m['content'] ?? '' ) : '';
				if ( 'user' === $role && is_string( $content ) ) {
					$content = trim( wp_strip_all_tags( $content ) );
					if ( mb_strlen( $content ) >= 4 ) {
						$questions[] = $content;
					}
				}
			}
		}

		if ( empty( $questions ) ) {
			return array( 'success' => false, 'error' => __( 'No customer questions found in the conversations.', 'ux-studio' ), 'analyzed' => $analyzed );
		}

		$transcript = '';
		foreach ( $questions as $q ) {
			$line = '- ' . $q . "\n";
			if ( mb_strlen( $transcript ) + mb_strlen( $line ) > self::MAX_TRANSCRIPT_CHARS ) {
				break;
			}
			$transcript .= $line;
		}

		$existing           = ( new FaqManager() )->get_all();
		$existing_questions = array_map(
			static fn( array $f ): string => (string) ( $f['question'] ?? '' ),
			$existing
		);

		return $this->run_generation(
			$this->build_system_prompt( $max_suggestions ),
			"EXISTING FAQS (do not duplicate):\n" . ( $this->format_list( $existing_questions ) ) . "\n\nCUSTOMER QUESTIONS FROM CHATS:\n{$transcript}",
			$existing_questions,
			$max_suggestions,
			'faq_analysis',
			$analyzed
		);
	}

	/**
	 * Generate FAQ suggestions directly from a free-form topic/instruction,
	 * without needing any prior chat history.
	 *
	 * @return array{success:bool,suggestions?:array<int,array{question:string,answer:string,category:string}>,error?:string}
	 */
	public function generate( string $topic, int $max_suggestions = 5 ): array {
		$topic = trim( $topic );
		if ( '' === $topic ) {
			return array( 'success' => false, 'error' => __( 'A topic or instruction is required.', 'ux-studio' ) );
		}

		$max_suggestions    = max( 1, min( $max_suggestions, 30 ) );
		$existing           = ( new FaqManager() )->get_all();
		$existing_questions = array_map(
			static fn( array $f ): string => (string) ( $f['question'] ?? '' ),
			$existing
		);

		return $this->run_generation(
			$this->build_system_prompt( $max_suggestions ),
			"EXISTING FAQS (do not duplicate):\n" . ( $this->format_list( $existing_questions ) ) . "\n\nTOPIC / INSTRUCTION:\n{$topic}",
			$existing_questions,
			$max_suggestions,
			'faq_generation'
		);
	}

	/**
	 * Shared AI call + parse + dedupe pipeline.
	 *
	 * @param string[] $existing_questions Existing FAQ questions to dedupe against.
	 */
	private function run_generation( string $system_prompt, string $user_prompt, array $existing_questions, int $max_suggestions, string $usage_feature, int $analyzed = 0 ): array {
		try {
			$provider    = ProviderFactory::create();
			$provider_id = $provider->get_id();

			$settings = new Settings( 'uxstudio_ai_assistant' );
			$model    = (string) $settings->get( $provider_id . '_model', '' );
			if ( '' === $model ) {
				$model = array_key_first( $provider->get_models() );
			}

			$result = $provider->generate_content(
				$system_prompt,
				$user_prompt,
				$model,
				array( 'max_tokens' => 3000, 'temperature' => 0.3 )
			);

			$usage = $result['usage'] ?? array( 'input_tokens' => 0, 'output_tokens' => 0 );
			UsageTracker::log( $provider_id, $model, $usage_feature, (int) ( $usage['input_tokens'] ?? 0 ), (int) ( $usage['output_tokens'] ?? 0 ) );

			$suggestions = $this->parse_suggestions( $result['content'] ?? '' );
			$suggestions = $this->filter_duplicates( $suggestions, $existing_questions );
			$suggestions = array_slice( $suggestions, 0, $max_suggestions );

			$response = array( 'success' => true, 'suggestions' => $suggestions );
			if ( $analyzed > 0 ) {
				$response['analyzed'] = $analyzed;
			}
			return $response;
		} catch ( \Throwable $e ) {
			$response = array( 'success' => false, 'error' => $e->getMessage() );
			if ( $analyzed > 0 ) {
				$response['analyzed'] = $analyzed;
			}
			return $response;
		}
	}

	/**
	 * Shared system prompt for both analyze() and generate().
	 */
	private function build_system_prompt( int $max_suggestions ): string {
		return "You are an experienced customer support analyst. From the input, propose useful FAQ (frequently asked questions) entries.\n\n"
			. "Rules:\n"
			. "- Return ONLY a valid JSON array, nothing else (no markdown, no commentary).\n"
			. "- Format: [{\"question\": \"...\", \"answer\": \"...\", \"category\": \"...\"}]\n"
			. "- Phrase questions generally and clearly (not verbatim from a raw customer message).\n"
			. "- Write concise, factual, useful answers.\n"
			. "- Do NOT duplicate any FAQ listed below as existing.\n"
			. "- Merge similar questions into a single FAQ.\n"
			. "- Choose a short, descriptive category (empty string if unsure).\n"
			. "- Maximum {$max_suggestions} items, ordered from most important/frequent.\n"
			. '- If no meaningful FAQ can be produced, return an empty array [].';
	}

	/**
	 * Render a question list for the prompt, or a placeholder when empty.
	 *
	 * @param string[] $questions Question list.
	 */
	private function format_list( array $questions ): string {
		$list = '';
		foreach ( $questions as $q ) {
			if ( '' !== $q ) {
				$list .= '- ' . $q . "\n";
			}
		}
		return '' === $list ? '(none yet)' : $list;
	}

	/**
	 * Parse the AI's JSON array response (tolerates ```json fences).
	 *
	 * @return array<int, array{question:string,answer:string,category:string}>
	 */
	private function parse_suggestions( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		$raw = (string) preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw = (string) preg_replace( '/\s*```$/', '', $raw );

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) && preg_match( '/\[.*\]/s', $raw, $m ) ) {
			$decoded = json_decode( $m[0], true );
		}

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = trim( (string) ( $item['question'] ?? '' ) );
			$answer   = trim( (string) ( $item['answer'] ?? '' ) );
			$category = trim( (string) ( $item['category'] ?? '' ) );
			if ( '' === $question || '' === $answer ) {
				continue;
			}
			$out[] = array( 'question' => $question, 'answer' => $answer, 'category' => $category );
		}

		return $out;
	}

	/**
	 * Drop suggestions that (near-)duplicate an existing FAQ or each other.
	 *
	 * @param array<int, array{question:string,answer:string,category:string}> $suggestions        Suggestions.
	 * @param string[]                                                          $existing_questions Existing FAQ questions.
	 * @return array<int, array{question:string,answer:string,category:string}>
	 */
	private function filter_duplicates( array $suggestions, array $existing_questions ): array {
		$normalize = static function ( string $s ): string {
			$s = mb_strtolower( $s );
			$s = (string) preg_replace( '/[^\p{L}\p{N}\s]/u', '', $s );
			$s = (string) preg_replace( '/\s+/u', ' ', $s );
			return trim( $s );
		};

		$existing_norm = array_map( $normalize, $existing_questions );
		$seen          = array();
		$out           = array();

		foreach ( $suggestions as $s ) {
			$norm = $normalize( $s['question'] );
			if ( '' === $norm || in_array( $norm, $existing_norm, true ) || in_array( $norm, $seen, true ) ) {
				continue;
			}
			$seen[] = $norm;
			$out[]  = $s;
		}

		return $out;
	}
}
