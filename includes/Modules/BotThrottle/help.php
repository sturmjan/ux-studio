<?php
/**
 * Plain-language help of the Bot Throttle settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'enabled'             => array(
		'does'   => __( 'Turns the whole module on or off. When it is on, the page requests of known robots (search engines, AI crawlers, SEO tools) are checked and, depending on the rules below and the load of the server, delayed, served from a short cache or turned away.', 'ux-studio' ),
		'effect' => __( 'Robots stop overloading a small server. Visitors who are logged in as administrators are never touched, and search engines are never blocked.', 'ux-studio' ),
		'risk'   => __( 'A wrong rule can slow down or turn away a robot that matters to you, such as an AI assistant that sends you visitors. Check the Log tab after a few days.', 'ux-studio' ),
	),
	'verify_rdns'         => array(
		'does'   => __( 'For the big search engines (Google, Bing, Yandex, DuckDuckGo, Apple, Seznam) the module asks the domain name system who really owns the address ([[dns|reverse DNS]]) and checks that it fits the name the robot gave. The answer is remembered for a day.', 'ux-studio' ),
		'effect' => __( 'A fake robot that only pretends to be Googlebot (its address belongs to somebody else) is treated as an unknown crawler: it is slowed down strongly, and turned away when the server is overloaded. A real one is let through as a search engine. When the address gives no answer at all, the robot gets the benefit of the doubt and is not slowed down.', 'ux-studio' ),
		'risk'   => __( 'Every new robot address costs one extra name lookup, which can be slow when the DNS server of the host is slow.', 'ux-studio' ),
		'off'    => __( 'Leave it off if you do not see fake search engine robots in the Log tab.', 'ux-studio' ),
	),
	'requests_per_minute' => array(
		'does'   => __( 'The most page requests one IP address may make per minute, whoever it is. The limit protects against floods and runaway scripts (a simple denial-of-service protection). 0 turns it off.', 'ux-studio' ),
		'effect' => __( 'An address over the limit gets the refusal code chosen below until the minute is over. Administrators are never limited, and neither are images or files served directly by the server.', 'ux-studio' ),
		'risk'   => __( 'Many people behind one address, such as an office, a school or a mobile network, share the limit and can be turned away together. This also applies to monitoring tools and to your allowlist, which does not exempt from this limit. Keep it generous (the default is 120).', 'ux-studio' ),
		'off'    => __( 'Set 0 when the server or Cloudflare already limits requests.', 'ux-studio' ),
	),
	'block_response_code' => array(
		'does'   => __( 'The HTTP answer sent to an address that went over the rate limit: 429 "Too many requests" or 503 "Service unavailable". Both tell the sender to come back later.', 'ux-studio' ),
		'effect' => __( '429 says clearly that the sender was too fast, which well-behaved robots respect. 503 says the server is busy, and search engines treat it more gently.', 'ux-studio' ),
		'off'    => __( 'Leave 429 unless you want the refusal to look like a short outage.', 'ux-studio' ),
	),
	'retention_days'      => array(
		'does'   => __( 'How many days the log of robot requests is kept. Older rows are deleted once a day.', 'ux-studio' ),
		'effect' => __( 'The log stays small. Only a salted hash of the address is stored, never the address itself.', 'ux-studio' ),
		'risk'   => __( 'Deleted rows cannot be restored. On a busy site a long retention makes the log table large.', 'ux-studio' ),
	),
	'rule_search_engines' => array(
		'does'   => __( 'What to do with Google, Bing, Seznam and other search engines.', 'ux-studio' ),
		'effect' => __( 'Search engines are never blocked, whatever you choose, so your site cannot drop out of search by mistake. Under heavy load they get a ready copy of the page.', 'ux-studio' ),
	),
	'rule_ai_assistants'  => array(
		'does'   => __( 'What to do with robots that fetch your pages while a person asks an AI assistant a question, for example ChatGPT-User or Perplexity.', 'ux-studio' ),
		'effect' => __( 'Slowing them lightly keeps the server calm. They are the robots that can send visitors to you.', 'ux-studio' ),
		'risk'   => __( 'Blocking them means AI assistants cannot read your pages and cannot recommend you.', 'ux-studio' ),
	),
	'rule_ai_training'    => array(
		'does'   => __( 'What to do with robots that collect pages to train AI models, for example GPTBot or ClaudeBot.', 'ux-studio' ),
		'effect' => __( 'They send you no visitors and can generate a lot of traffic, so slowing them is usual.', 'ux-studio' ),
		'risk'   => __( 'This only slows them down. To forbid training use robots.txt as well, because a polite robot respects it.', 'ux-studio' ),
	),
	'rule_seo_tools'      => array(
		'does'   => __( 'What to do with the crawlers of SEO services such as Ahrefs or Semrush.', 'ux-studio' ),
		'effect' => __( 'They can make heavy traffic and bring you nothing directly. Slowing them is usual.', 'ux-studio' ),
		'risk'   => __( 'If you use such a service to check your own site, slowing or blocking its robot makes its reports incomplete.', 'ux-studio' ),
	),
	'rule_social'         => array(
		'does'   => __( 'What to do with the robots of social networks and chat apps that fetch the preview of a shared link.', 'ux-studio' ),
		'effect' => __( 'Letting them through keeps the picture and title when somebody shares your page.', 'ux-studio' ),
		'risk'   => __( 'Blocking them means a shared link shows no preview.', 'ux-studio' ),
	),
	'rule_archivers'      => array(
		'does'   => __( 'What to do with web archives such as the Wayback Machine.', 'ux-studio' ),
		'effect' => __( 'They visit rarely, so a light slowdown is enough.', 'ux-studio' ),
		'risk'   => __( 'Blocking them means your site is not saved in the archive.', 'ux-studio' ),
	),
	'rule_generic_crawlers' => array(
		'does'   => __( 'What to do with unnamed crawlers and scripts that identify themselves as a robot.', 'ux-studio' ),
		'effect' => __( 'They are usually not useful to you, so a strong slowdown is the default.', 'ux-studio' ),
		'risk'   => __( 'A monitoring service or an app you use may be among them; blocking would break it.', 'ux-studio' ),
	),
	'threshold_yellow'    => array(
		'does'   => __( 'The server load, on a scale of 0 to 100, at which the status turns YELLOW (busy). From here bots with a "throttle" rule start to be delayed.', 'ux-studio' ),
		'effect' => __( 'A lower number starts slowing bots earlier. The load is a mix of response time, database queries and memory.', 'ux-studio' ),
	),
	'threshold_orange'    => array(
		'does'   => __( 'The server load at which the status turns ORANGE (heavy). From here bots get a ready copy of the page or are turned away with 429 when no copy exists.', 'ux-studio' ),
		'effect' => __( 'Keep it above the YELLOW value.', 'ux-studio' ),
	),
	'threshold_red'       => array(
		'does'   => __( 'The server load at which the status turns RED (overloaded). Throttled bots are turned away with 503 unless a ready copy exists.', 'ux-studio' ),
		'effect' => __( 'Keep it above the ORANGE value. Ordinary visitors and search engines are still served.', 'ux-studio' ),
	),
	'min_delay_ms'        => array(
		'does'   => __( 'A small pause, in milliseconds, added for search engines when the server is busy (YELLOW). 0 means no pause.', 'ux-studio' ),
		'effect' => __( 'A tiny delay eases the pressure without hurting crawling.', 'ux-studio' ),
	),
	'light_min'           => array(
		'does'   => __( 'The shortest pause, in milliseconds, for bots on the "light throttle" rule while the server is busy. The real pause is a random value between the minimum and the maximum.', 'ux-studio' ),
		'effect' => __( 'While a bot waits, the request keeps a server worker busy, so keep the pauses short.', 'ux-studio' ),
	),
	'light_max'           => array(
		'does'   => __( 'The longest pause, in milliseconds, for bots on the "light throttle" rule.', 'ux-studio' ),
		'effect' => __( 'At most 5000 ms is ever used.', 'ux-studio' ),
	),
	'aggressive_min'      => array(
		'does'   => __( 'The shortest pause, in milliseconds, for bots on the "aggressive" rule while the server is busy.', 'ux-studio' ),
		'effect' => __( 'Long pauses discourage a bot but also keep a server worker waiting, so use them with care.', 'ux-studio' ),
	),
	'aggressive_max'      => array(
		'does'   => __( 'The longest pause, in milliseconds, for bots on the "aggressive" rule. At most 5000 ms is ever used.', 'ux-studio' ),
		'effect' => __( 'The real pause is a random value between the minimum and this maximum.', 'ux-studio' ),
	),
	'whitelist_ua'        => array(
		'does'   => __( 'Robots whose [[user-agent|User-Agent]] contains one of these words, one per line, are never throttled. Letter case does not matter, and a part of the name is enough.', 'ux-studio' ),
		'effect' => __( 'Use it for a monitoring or reporting robot you rely on. Example: UptimeRobot.', 'ux-studio' ),
		'risk'   => __( 'The User-Agent can be faked by anyone. A short word that also appears in other names can let unwanted robots through, so use a distinctive word.', 'ux-studio' ),
	),
	'whitelist_ip'        => array(
		'does'   => __( 'Addresses never treated as bots, one per line. Write a single address (203.0.113.7) or a range in [[cidr|CIDR]] notation (192.168.0.0/16 covers a whole internal network).', 'ux-studio' ),
		'effect' => __( 'Use it for your office or a partner\'s server. These addresses skip bot throttling, but still count toward the per-minute limit.', 'ux-studio' ),
		'risk'   => __( 'Everything behind such an address is trusted. Behind a shared address, the whole group is trusted.', 'ux-studio' ),
	),
	'blacklist_ua'        => array(
		'does'   => __( 'Robots whose [[user-agent|User-Agent]] contains one of these words, one per line, are always treated as unwelcome crawlers, even when the module would otherwise not know them. The list is filled with common SEO crawlers.', 'ux-studio' ),
		'effect' => __( 'These robots are always slowed down, a little when the server is calm and strongly when it is busy, and turned away when it is overloaded.', 'ux-studio' ),
		'risk'   => __( 'A word that also appears in the name of a useful robot would slow it down as well. Use a distinctive word.', 'ux-studio' ),
	),
);
