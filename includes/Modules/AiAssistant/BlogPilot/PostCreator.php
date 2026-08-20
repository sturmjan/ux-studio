<?php
/**
 * Generates one Blog Pilot article via the AI provider and creates the
 * resulting WordPress post (markdown -> HTML, category/tags, usage logging).
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (includes/blog-pilot/PostCreator.php).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

use UxStudio\Modules\AiAssistant\ContentHelper;
use UxStudio\Modules\AiAssistant\ProviderFactory;
use UxStudio\Modules\AiAssistant\UsageTracker;

defined( 'ABSPATH' ) || exit;

final class PostCreator {

	/**
	 * Generates an article via AI and creates the WP post.
	 *
	 * @return array{post_id:int,title:string,usage:array{input_tokens:int,output_tokens:int}}
	 */
	public function generate( object $generator, string $topic, string $article_type ): array {
		$config      = (array) $generator->config;
		$provider_id = $generator->provider ?: null;
		$model       = $generator->model ?: '';

		$messages = PromptBuilder::build_messages( $topic, $article_type, $config );

		$provider = ProviderFactory::create( $provider_id );

		if ( '' === $model ) {
			$models = $provider->get_models();
			$model  = array_key_first( $models );
		}

		$result = $provider->generate_content(
			$messages[0]['content'],
			$messages[1]['content'],
			$model,
			array(
				'max_tokens'  => 4096,
				'temperature' => 0.8,
			)
		);

		$ai_content = $result['content'] ?? '';
		$usage      = $result['usage'] ?? array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);

		$parsed = PromptBuilder::parse_ai_response( $ai_content );
		if ( null === $parsed ) {
			throw new \RuntimeException( __( 'The AI returned an invalid response. Please try again.', 'ux-studio' ) );
		}

		$post_id = $this->create_post( $parsed, $config );

		UsageTracker::log(
			$provider->get_id(),
			$model,
			'blog_pilot',
			$usage['input_tokens'],
			$usage['output_tokens']
		);

		return array(
			'post_id' => $post_id,
			'title'   => $parsed['title'],
			'usage'   => $usage,
		);
	}

	/**
	 * @param array{title:string,content:string,excerpt:string,tags:array<int,string>,category:string} $parsed
	 * @param array<string, mixed>                                                                       $config
	 */
	private function create_post( array $parsed, array $config ): int {
		$post_status = $config['post_status'] ?? 'draft';
		$author_id   = (int) ( $config['author_id'] ?? 0 );

		$html_content = $this->markdown_to_html( $parsed['content'] );

		$post_data = array(
			'post_title'   => $parsed['title'],
			'post_content' => $html_content,
			'post_excerpt' => $parsed['excerpt'] ?? '',
			'post_status'  => in_array( $post_status, array( 'draft', 'publish', 'pending' ), true ) ? $post_status : 'draft',
			'post_type'    => 'post',
		);

		if ( $author_id > 0 ) {
			$post_data['post_author'] = $author_id;
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: WP_Error message */
					__( 'Error creating the post: %s', 'ux-studio' ),
					$post_id->get_error_message()
				)
			);
		}

		$category_id = (int) ( $config['category_id'] ?? 0 );
		if ( $category_id > 0 ) {
			wp_set_post_categories( $post_id, array( $category_id ) );
		} elseif ( ! empty( $parsed['category'] ) ) {
			$cat_id = ContentHelper::get_or_create_category( $parsed['category'] );
			if ( $cat_id > 0 ) {
				wp_set_post_categories( $post_id, array( $cat_id ) );
			}
		}

		if ( ! empty( $parsed['tags'] ) && is_array( $parsed['tags'] ) ) {
			$tag_ids = ContentHelper::get_or_create_tags( $parsed['tags'] );
			if ( ! empty( $tag_ids ) ) {
				wp_set_post_tags( $post_id, $tag_ids );
			}
		}

		update_post_meta( $post_id, '_uxstudio_blog_pilot', '1' );

		return (int) $post_id;
	}

	/**
	 * Minimal markdown -> HTML conversion (headings/bold/italic/lists/code/
	 * links/hr), finished off by wpautop() for paragraphs.
	 */
	private function markdown_to_html( string $markdown ): string {
		$html = $markdown;

		$html = preg_replace( '/^######\s+(.+)$/m', '<h6>$1</h6>', $html );
		$html = preg_replace( '/^#####\s+(.+)$/m', '<h5>$1</h5>', $html );
		$html = preg_replace( '/^####\s+(.+)$/m', '<h4>$1</h4>', $html );
		$html = preg_replace( '/^###\s+(.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^##\s+(.+)$/m', '<h2>$1</h2>', $html );
		$html = preg_replace( '/^#\s+(.+)$/m', '<h2>$1</h2>', $html ); // H1 -> H2 (post title is already the H1).

		$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $html );

		$html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/((?:<li>.*<\/li>\n?)+)/s', "<ul>\n$1</ul>\n", $html );

		$html = preg_replace( '/^\d+\.\s+(.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.*<\/li>\n?)(?!<li>)/s', "<ol>\n$1</ol>\n", $html );

		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );
		$html = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html );
		$html = preg_replace( '/^---+$/m', '<hr>', $html );

		$html = wpautop( $html );

		return wp_kses_post( $html );
	}
}
