<?php
/**
 * Plain-language definitions of technical terms used in the help texts.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The glossary behind the `[[term]]` markers of the help texts (docs/help-texts.md). The SPA receives it with the
 * boot data of the admin page (already in the language of the user) and turns each marker into the term plus a
 * small "?" popover with the definition.
 *
 * A term id is the lower-case name with spaces and dots turned into hyphens, for example `[[REST API]]` is
 * `rest-api`, `[[llms.txt]]` is `llms-txt`. `[[term|shown text]]` shows another text but explains the same term.
 * Aliases (`cron` for `wp-cron`) resolve to the same definition. A marker that matches nothing is shown as plain
 * text, so a typo never breaks a screen.
 */
final class Glossary {

	/**
	 * Alias => term id.
	 */
	public const ALIASES = array(
		'cron'                    => 'wp-cron',
		'wordpress-cron'          => 'wp-cron',
		'rest'                    => 'rest-api',
		'useragent'               => 'user-agent',
		'application-password'    => 'application-passwords',
		'schema'                  => 'schema-org',
		'jsonld'                  => 'json-ld',
		'llms'                    => 'llms-txt',
		'canonical'               => 'canonical-url',
		'rate-limiting'           => 'rate-limit',
		'pow'                     => 'proof-of-work',
		'content-security-policy' => 'csp',
	);

	/**
	 * Normalise the text of a marker to a term id.
	 *
	 * @param string $name Text between the brackets (without a `|shown text` part).
	 */
	public static function id_of( string $name ): string {
		$id = strtolower( trim( $name ) );
		$id = (string) preg_replace( '/[^a-z0-9]+/', '-', $id );
		$id = trim( $id, '-' );
		return self::ALIASES[ $id ] ?? $id;
	}

	/**
	 * One term, or null.
	 *
	 * @param string $name Term id or marker text.
	 * @return array{term:string,text:string}|null
	 */
	public static function get( string $name ): ?array {
		return self::terms()[ self::id_of( $name ) ] ?? null;
	}

	/**
	 * All terms, translated for the current locale.
	 *
	 * @return array<string, array{term:string,text:string}>
	 */
	public static function terms(): array {
		$terms = array(
			'csp'                   => array( 'CSP', __( 'Content Security Policy. A rule that tells the browser which sources a page may load scripts, styles and images from. It blocks injected code, but a rule that is too strict also blocks things you need.', 'ux-studio' ) ),
			'hsts'                  => array( 'HSTS', __( 'A header that tells browsers to open your site only over HTTPS for a set time. Once a browser remembers it, there is no way back to plain HTTP until the time runs out.', 'ux-studio' ) ),
			'report-only'           => array( 'Report-Only', __( 'A trial mode of a security rule: the browser does not block anything, it only reports what it would have blocked. Use it to see the effect before you enforce the rule.', 'ux-studio' ) ),
			'heartbeat'             => array( 'Heartbeat', __( 'A small request that the WordPress editor and dashboard send to the server every 15 to 60 seconds. It keeps the login alive and warns about simultaneous editing, but on a busy site it adds server load.', 'ux-studio' ) ),
			'xml-rpc'               => array( 'XML-RPC', __( 'An old WordPress interface (xmlrpc.php) for remote apps. Few sites need it today, and attackers like it for password guessing, so it is usually safe to turn off.', 'ux-studio' ) ),
			'rest-api'              => array( 'REST API', __( 'The interface through which apps, the block editor and plugins read and write site data over web addresses starting with /wp-json/.', 'ux-studio' ) ),
			'wp-cron'               => array( 'WP-Cron', __( 'The WordPress scheduler. It runs timed jobs such as publishing scheduled posts, checking updates or sending e-mails, and it wakes up when someone opens a page.', 'ux-studio' ) ),
			'ttl'                   => array( 'TTL', __( 'Time to live: how many seconds a copy of the data (a DNS answer, a cached page) may be reused before it is fetched again. A short TTL means changes show up faster, a long one means fewer requests.', 'ux-studio' ) ),
			'dns'                   => array( 'DNS', __( 'The address book of the internet. It turns a domain name into the server that serves it and says where the e-mail of the domain goes.', 'ux-studio' ) ),
			'spf'                   => array( 'SPF', __( 'A DNS record that lists the servers allowed to send e-mail for your domain, so receivers can spot forged messages.', 'ux-studio' ) ),
			'dkim'                  => array( 'DKIM', __( 'A digital signature added to every e-mail you send. The receiver checks it against a key in your DNS to be sure the message is yours and was not changed on the way.', 'ux-studio' ) ),
			'dmarc'                 => array( 'DMARC', __( 'A DNS record that tells receivers what to do with e-mail that fails the SPF and DKIM checks (nothing, spam folder, reject) and where to send reports.', 'ux-studio' ) ),
			'oauth'                 => array( 'OAuth', __( 'A way to let one service act on your account at another service (for example read your Google reviews) without giving it your password. You approve the access once and can withdraw it later.', 'ux-studio' ) ),
			'webhook'               => array( 'Webhook', __( 'A web address that another service calls the moment something happens, for example when a form is sent, so it can react without asking again and again.', 'ux-studio' ) ),
			'regex'                 => array( 'Regex', __( 'Regular expression. A compact pattern for matching text, such as every address that starts with /blog/ followed by digits. Powerful, but a small mistake can match far more than you meant.', 'ux-studio' ) ),
			'301'                   => array( '301', __( 'A permanent redirect. The old address is gone for good, visitors and search engines move to the new one, and the ranking of the old page is passed on.', 'ux-studio' ) ),
			'302'                   => array( '302', __( 'A temporary redirect. The old address will come back, so search engines keep it in their index and do not move the ranking.', 'ux-studio' ) ),
			'410'                   => array( '410', __( 'Gone. The page was removed on purpose and will not return, which makes search engines drop it faster than an ordinary "not found" (404).', 'ux-studio' ) ),
			'canonical-url'         => array( 'Canonical URL', __( 'The one address a page wants search engines to count when the same content can be reached under several addresses (with parameters, with or without a slash).', 'ux-studio' ) ),
			'slug'                  => array( 'Slug', __( 'The part of a web address that names a page, for example "about-us" in example.com/about-us/.', 'ux-studio' ) ),
			'transient'             => array( 'Transient', __( 'A piece of data that WordPress stores temporarily, with an expiry time, so it does not have to be computed again on every request.', 'ux-studio' ) ),
			'object-cache'          => array( 'Object cache', __( 'A fast memory (usually Redis or Memcached) where WordPress keeps results of database queries, so pages build faster. It needs a server that provides it.', 'ux-studio' ) ),
			'lazy-load'             => array( 'Lazy load', __( 'Images and frames further down the page are loaded only when the visitor scrolls near them. The first screen opens faster and less data is transferred.', 'ux-studio' ) ),
			'webp'                  => array( 'WebP', __( 'A modern image format that is usually 25 to 35 percent smaller than JPEG at the same quality. All current browsers show it.', 'ux-studio' ) ),
			'avif'                  => array( 'AVIF', __( 'A newer image format, often smaller than WebP at the same quality. Most current browsers show it, but it takes longer to create.', 'ux-studio' ) ),
			'lcp'                   => array( 'LCP', __( 'Largest Contentful Paint. How long it takes until the biggest thing on the first screen (usually a photo or a heading) is visible. Google counts under 2.5 seconds as good.', 'ux-studio' ) ),
			'cls'                   => array( 'CLS', __( 'Cumulative Layout Shift. How much the page jumps while it loads, for example when an image appears and pushes the text down. Lower is better, under 0.1 is good.', 'ux-studio' ) ),
			'cdn'                   => array( 'CDN', __( 'Content delivery network. A set of servers around the world that keep copies of your images, styles and scripts and serve them from the place closest to the visitor.', 'ux-studio' ) ),
			'honeypot'              => array( 'Honeypot', __( 'A form field that real visitors never see. A bot fills in every field it finds, so a filled honeypot tells the form that the sender is not a person.', 'ux-studio' ) ),
			'proof-of-work'         => array( 'Proof-of-work', __( 'A small puzzle that the visitor\'s browser solves by itself in a fraction of a second. It costs a single visitor nothing, but a bot that sends thousands of requests has to solve thousands of puzzles.', 'ux-studio' ) ),
			'user-enumeration'      => array( 'User enumeration', __( 'Finding out the login names of a site, for example through /?author=1 or the REST API. A known login name makes password guessing easier.', 'ux-studio' ) ),
			'application-passwords' => array( 'Application passwords', __( 'Separate passwords that WordPress creates for apps and scripts that connect to your site. Each can be revoked on its own without changing your login password.', 'ux-studio' ) ),
			'sso'                   => array( 'SSO', __( 'Single sign-on. You log in once, for example with Google, and are let into other services without typing a password again.', 'ux-studio' ) ),
			'mcp'                   => array( 'MCP', __( 'Model Context Protocol. A standard way for AI assistants to use tools and data of another program, here of your site, in a controlled way.', 'ux-studio' ) ),
			'rag'                   => array( 'RAG', __( 'Retrieval-augmented generation. The AI first looks up the matching parts of your own content and answers from them, instead of guessing from general knowledge.', 'ux-studio' ) ),
			'llms-txt'              => array( 'llms.txt', __( 'A plain text file in the root of the site that gives AI assistants a short, tidy overview of what the site is about and which pages matter.', 'ux-studio' ) ),
			'json-ld'               => array( 'JSON-LD', __( 'A block of structured data in the page code that tells search engines exactly what the page is (an article, a product, an event) without changing how it looks.', 'ux-studio' ) ),
			'schema-org'            => array( 'Schema.org', __( 'The shared vocabulary of structured data that search engines understand. It lets a result show stars, prices or opening hours.', 'ux-studio' ) ),
			'vapid'                 => array( 'VAPID', __( 'A pair of keys that identifies your site to the browser push services, so that only your site can send push notifications to the people who subscribed.', 'ux-studio' ) ),
			'cidr'                  => array( 'CIDR', __( 'A short way to write a whole range of IP addresses, for example 192.168.1.0/24 stands for 256 addresses starting at 192.168.1.0.', 'ux-studio' ) ),
			'user-agent'            => array( 'User-Agent', __( 'The text with which a browser or a bot introduces itself to the server, for example the name of the browser and its version.', 'ux-studio' ) ),
			'rate-limit'            => array( 'Rate limit', __( 'A cap on how many requests one visitor or address may send in a given time. Anything above it is refused, which slows down bots and guessing of passwords.', 'ux-studio' ) ),
			'nonce'                 => array( 'Nonce', __( 'A one-time token that WordPress adds to forms and requests to prove that they really came from your own screen and not from a forged link.', 'ux-studio' ) ),
			'smtp'                  => array( 'SMTP', __( 'The standard way to send e-mail through a mail server with a login. Sending through a proper mail server lands in the inbox far more often than the default PHP mail.', 'ux-studio' ) ),
			'imap'                  => array( 'IMAP', __( 'The standard way to read e-mail from a mail server while the messages stay on the server. Used when the site needs to look into a mailbox.', 'ux-studio' ) ),
		);
		$out   = array();
		foreach ( $terms as $id => $pair ) {
			$out[ $id ] = array(
				'term' => $pair[0],
				'text' => $pair[1],
			);
		}

		/**
		 * Extension point: add terms (add-ons) or reword one. Keys are term ids, see Glossary::id_of().
		 *
		 * @param array<string, array{term:string,text:string}> $out Terms.
		 */
		return (array) apply_filters( 'uxstudio_glossary', $out );
	}

	/**
	 * Terms plus aliases for the SPA: id => {term, text}. Aliases are resolved in PHP, so the SPA only needs
	 * `normalizeTermId()` to match its own markers (it mirrors id_of() without the alias table, which is sent too).
	 *
	 * @return array{terms:array<string, array{term:string,text:string}>,aliases:array<string,string>}
	 */
	public static function for_script(): array {
		return array(
			'terms'   => self::terms(),
			'aliases' => self::ALIASES,
		);
	}
}
