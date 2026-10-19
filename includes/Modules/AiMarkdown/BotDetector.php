<?php
/**
 * AI bot detection by user agent.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiMarkdown;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a user agent to a human-readable bot name (port of the legacy
 * ux1 ai-markdown BotDetector). Only requests that match one of these
 * patterns are written to the access log.
 */
final class BotDetector {

	/**
	 * UA substring (case-insensitive) => bot name. Order matters: the first
	 * match wins, so the more specific pattern must come before the generic
	 * one (Applebot-Extended before Applebot).
	 *
	 * @var array<string, string>
	 */
	public const BOTS = array(
		'GPTBot'            => 'OpenAI GPTBot',
		'ChatGPT-User'      => 'ChatGPT',
		'OAI-SearchBot'     => 'OpenAI Search',
		'ClaudeBot'         => 'Anthropic Claude',
		'Claude-Web'        => 'Anthropic Claude',
		'PerplexityBot'     => 'Perplexity',
		'Google-Extended'   => 'Google AI',
		'Googlebot'         => 'Google',
		'Bingbot'           => 'Bing',
		'Bytespider'        => 'ByteDance',
		'CCBot'             => 'Common Crawl',
		'FacebookBot'       => 'Meta AI',
		'cohere-ai'         => 'Cohere',
		'Applebot-Extended' => 'Apple AI',
		'Applebot'          => 'Apple',
		'YouBot'            => 'You.com',
		'Diffbot'           => 'Diffbot',
		'ImagesiftBot'      => 'Imagesift',
		'Amazonbot'         => 'Amazon',
	);

	/**
	 * Bot name for a user agent, or null when it is not a known bot.
	 *
	 * @param string $user_agent Raw user agent.
	 */
	public static function detect( string $user_agent ): ?string {
		if ( '' === $user_agent ) {
			return null;
		}
		foreach ( self::BOTS as $pattern => $name ) {
			if ( false !== stripos( $user_agent, $pattern ) ) {
				return $name;
			}
		}
		return null;
	}
}
