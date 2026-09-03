<?php
/**
 * Bot signature database: categories, User-Agent patterns, verifiable rDNS.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * Known bots grouped by category. Search engines are never blocked (deindex
 * risk); AI training crawlers and SEO tools are throttled aggressively by
 * default. Ported from the legacy bot-throttle module.
 */
final class Signatures {

	/**
	 * Default category definitions. Each bot: [ name, pattern ] where pattern is
	 * a case-insensitive regex fragment matched against the User-Agent.
	 *
	 * @return array<string, array{label:string,priority:string,default_action:string,bots:array<int,array{name:string,pattern:string}>}>
	 */
	public static function categories(): array {
		return array(
			'search_engines'   => array(
				'label'          => __( 'Search engines', 'ux-studio' ),
				'priority'       => 'high',
				'default_action' => 'pass_with_min_delay',
				'bots'           => array(
					array( 'name' => 'Googlebot', 'pattern' => '(?:^|[^a-z])Googlebot(?:[^a-z]|$)' ),
					array( 'name' => 'Googlebot-Image', 'pattern' => 'Googlebot-Image' ),
					array( 'name' => 'Googlebot-News', 'pattern' => 'Googlebot-News' ),
					array( 'name' => 'Googlebot-Video', 'pattern' => 'Googlebot-Video' ),
					array( 'name' => 'Bingbot', 'pattern' => 'bingbot' ),
					array( 'name' => 'Slurp', 'pattern' => 'Slurp' ),
					array( 'name' => 'DuckDuckBot', 'pattern' => 'DuckDuckBot' ),
					array( 'name' => 'Baiduspider', 'pattern' => 'Baiduspider' ),
					array( 'name' => 'YandexBot', 'pattern' => 'YandexBot' ),
					array( 'name' => 'SeznamBot', 'pattern' => 'SeznamBot' ),
					array( 'name' => 'Applebot', 'pattern' => 'Applebot(?!-Extended)' ),
					array( 'name' => 'Sogou', 'pattern' => 'Sogou' ),
					array( 'name' => 'Naver', 'pattern' => 'Yeti.*NaverBot|Naverbot' ),
				),
			),
			'ai_assistants'    => array(
				'label'          => __( 'AI assistants (real-time)', 'ux-studio' ),
				'priority'       => 'medium',
				'default_action' => 'throttle_light',
				'bots'           => array(
					array( 'name' => 'ChatGPT-User', 'pattern' => 'ChatGPT-User' ),
					array( 'name' => 'OAI-SearchBot', 'pattern' => 'OAI-SearchBot' ),
					array( 'name' => 'PerplexityBot', 'pattern' => 'PerplexityBot' ),
					array( 'name' => 'Perplexity-User', 'pattern' => 'Perplexity-User' ),
					array( 'name' => 'Claude-User', 'pattern' => 'Claude-User' ),
					array( 'name' => 'Claude-SearchBot', 'pattern' => 'Claude-SearchBot' ),
					array( 'name' => 'YouBot', 'pattern' => 'YouBot' ),
					array( 'name' => 'Phindbot', 'pattern' => 'Phindbot' ),
				),
			),
			'ai_training'      => array(
				'label'          => __( 'AI training crawlers', 'ux-studio' ),
				'priority'       => 'low',
				'default_action' => 'throttle_aggressive',
				'bots'           => array(
					array( 'name' => 'GPTBot', 'pattern' => 'GPTBot' ),
					array( 'name' => 'ClaudeBot', 'pattern' => 'ClaudeBot' ),
					array( 'name' => 'Claude-Web', 'pattern' => 'Claude-Web' ),
					array( 'name' => 'anthropic-ai', 'pattern' => 'anthropic-ai' ),
					array( 'name' => 'CCBot', 'pattern' => 'CCBot' ),
					array( 'name' => 'Google-Extended', 'pattern' => 'Google-Extended' ),
					array( 'name' => 'Applebot-Extended', 'pattern' => 'Applebot-Extended' ),
					array( 'name' => 'Bytespider', 'pattern' => 'Bytespider' ),
					array( 'name' => 'Diffbot', 'pattern' => 'Diffbot' ),
					array( 'name' => 'FacebookBot', 'pattern' => 'FacebookBot|meta-externalagent' ),
					array( 'name' => 'ImagesiftBot', 'pattern' => 'ImagesiftBot' ),
					array( 'name' => 'Omgili', 'pattern' => 'Omgili|omgilibot' ),
					array( 'name' => 'Timpibot', 'pattern' => 'Timpibot' ),
					array( 'name' => 'Cohere-AI', 'pattern' => 'cohere-ai' ),
					array( 'name' => 'Amazonbot', 'pattern' => 'Amazonbot' ),
				),
			),
			'seo_tools'        => array(
				'label'          => __( 'SEO tools', 'ux-studio' ),
				'priority'       => 'low',
				'default_action' => 'throttle_aggressive',
				'bots'           => array(
					array( 'name' => 'AhrefsBot', 'pattern' => 'AhrefsBot' ),
					array( 'name' => 'SemrushBot', 'pattern' => 'SemrushBot' ),
					array( 'name' => 'MJ12bot', 'pattern' => 'MJ12bot' ),
					array( 'name' => 'DotBot', 'pattern' => 'DotBot' ),
					array( 'name' => 'BLEXBot', 'pattern' => 'BLEXBot' ),
					array( 'name' => 'SerpstatBot', 'pattern' => 'SerpstatBot' ),
					array( 'name' => 'rogerbot', 'pattern' => 'rogerbot' ),
					array( 'name' => 'PetalBot', 'pattern' => 'PetalBot' ),
					array( 'name' => 'DataForSeoBot', 'pattern' => 'DataForSeoBot' ),
				),
			),
			'social'           => array(
				'label'          => __( 'Social media', 'ux-studio' ),
				'priority'       => 'medium',
				'default_action' => 'pass_with_min_delay',
				'bots'           => array(
					array( 'name' => 'facebookexternalhit', 'pattern' => 'facebookexternalhit' ),
					array( 'name' => 'Twitterbot', 'pattern' => 'Twitterbot' ),
					array( 'name' => 'LinkedInBot', 'pattern' => 'LinkedInBot' ),
					array( 'name' => 'Slackbot', 'pattern' => 'Slackbot' ),
					array( 'name' => 'Discordbot', 'pattern' => 'Discordbot' ),
					array( 'name' => 'TelegramBot', 'pattern' => 'TelegramBot' ),
					array( 'name' => 'WhatsApp', 'pattern' => 'WhatsApp' ),
					array( 'name' => 'Pinterestbot', 'pattern' => 'Pinterestbot' ),
				),
			),
			'archivers'        => array(
				'label'          => __( 'Archivers', 'ux-studio' ),
				'priority'       => 'low',
				'default_action' => 'throttle_light',
				'bots'           => array(
					array( 'name' => 'ia_archiver', 'pattern' => 'ia_archiver' ),
					array( 'name' => 'archive.org_bot', 'pattern' => 'archive\.org_bot' ),
					array( 'name' => 'Wayback', 'pattern' => 'Wayback' ),
				),
			),
			'generic_crawlers' => array(
				'label'          => __( 'Generic crawlers', 'ux-studio' ),
				'priority'       => 'low',
				'default_action' => 'throttle_aggressive',
				'bots'           => array(
					array( 'name' => 'generic-bot', 'pattern' => '(?:^|\s)(?:bot|crawler|spider|scraper)(?:\s|$|/)' ),
				),
			),
		);
	}

	/**
	 * Verifiable reverse-DNS suffixes for the major search engines. If a UA
	 * claims to be one of these but rDNS does not match, it is likely spoofed.
	 *
	 * @return array<string, string[]>
	 */
	public static function verifiable_rdns(): array {
		return array(
			'Googlebot'   => array( '.googlebot.com', '.google.com' ),
			'Bingbot'     => array( '.search.msn.com' ),
			'YandexBot'   => array( '.yandex.ru', '.yandex.net', '.yandex.com' ),
			'DuckDuckBot' => array( '.duckduckgo.com' ),
			'Applebot'    => array( '.applebot.apple.com' ),
			'SeznamBot'   => array( '.seznam.cz' ),
		);
	}
}
