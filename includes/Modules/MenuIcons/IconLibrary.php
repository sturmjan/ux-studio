<?php
/**
 * Curated Lucide icon allowlist for the Menu Icons module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MenuIcons;

defined( 'ABSPATH' ) || exit;

/**
 * Reads inner `<svg>` markup for a fixed, curated set of Lucide icon names
 * from local files under `assets/lucide/`. Icons are never fetched at
 * runtime (no CDN, no network call) - the files are synced once at build
 * time from the `lucide-static` npm package via `npm run icons:sync`
 * (see bin/sync-menu-icons.mjs), matching the ALLOWLIST below.
 *
 * Only names in ALLOWLIST are ever looked up, so this doubles as the
 * server-side whitelist that save_fields() validates against - an id that
 * is not in this list is rejected even if a same-named file existed on disk.
 */
final class IconLibrary {

	/**
	 * Curated icon names (kebab-case, matching Lucide's own naming).
	 * Extend this list + re-run `npm run icons:sync` to add more.
	 *
	 * @var string[]
	 */
	private const ALLOWLIST = array(
		'house', 'mail', 'phone', 'map-pin', 'star', 'heart', 'user', 'users',
		'search', 'menu', 'x', 'check', 'chevron-right', 'chevron-down',
		'arrow-right', 'external-link', 'calendar', 'clock', 'globe',
		'facebook', 'instagram', 'linkedin', 'youtube', 'twitter',
		'download', 'upload', 'file-text', 'settings', 'info', 'bell',
		'lock', 'shopping-cart', 'tag', 'circle-help', 'message-circle',
	);

	/**
	 * The Dashicons that ship with WordPress core (wp-includes/css/dashicons.css, class names without the
	 * `dashicons-` prefix). They are drawn by the `dashicons` stylesheet WordPress already has, so nothing is downloaded
	 * and nothing is added to the plugin; the list doubles as the server-side whitelist for the stored value.
	 *
	 * @var string[]
	 */
	private const DASHICONS = array(
		'admin-appearance', 'admin-collapse', 'admin-comments', 'admin-customizer', 'admin-generic', 'admin-home',
		'admin-links', 'admin-media', 'admin-multisite', 'admin-network', 'admin-page', 'admin-plugins', 'admin-post',
		'admin-settings', 'admin-site', 'admin-site-alt', 'admin-site-alt2', 'admin-site-alt3', 'admin-tools',
		'admin-users', 'airplane', 'album', 'align-center', 'align-full-width', 'align-left', 'align-none',
		'align-pull-left', 'align-pull-right', 'align-right', 'align-wide', 'amazon', 'analytics', 'archive', 'arrow-down',
		'arrow-down-alt', 'arrow-down-alt2', 'arrow-left', 'arrow-left-alt', 'arrow-left-alt2', 'arrow-right',
		'arrow-right-alt', 'arrow-right-alt2', 'arrow-up', 'arrow-up-alt', 'arrow-up-alt2', 'arrow-up-duplicate', 'art',
		'awards', 'backup', 'bank', 'beer', 'before', 'bell', 'block-default', 'book', 'book-alt', 'buddicons-activity',
		'buddicons-bbpress-logo', 'buddicons-buddypress-logo', 'buddicons-community', 'buddicons-forums',
		'buddicons-friends', 'buddicons-groups', 'buddicons-pm', 'buddicons-replies', 'buddicons-topics',
		'buddicons-tracking', 'building', 'businessman', 'businessperson', 'businesswoman', 'button', 'calculator',
		'calendar', 'calendar-alt', 'camera', 'camera-alt', 'car', 'carrot', 'cart', 'category', 'chart-area', 'chart-bar',
		'chart-line', 'chart-pie', 'clipboard', 'clock', 'cloud', 'cloud-saved', 'cloud-upload', 'code-standards',
		'coffee', 'color-picker', 'columns', 'controls-back', 'controls-forward', 'controls-pause', 'controls-play',
		'controls-repeat', 'controls-skipback', 'controls-skipforward', 'controls-volumeoff', 'controls-volumeon',
		'cover-image', 'dashboard', 'database', 'database-add', 'database-export', 'database-import', 'database-remove',
		'database-view', 'desktop', 'dismiss', 'download', 'drumstick', 'edit', 'edit-large', 'edit-page',
		'editor-aligncenter', 'editor-alignleft', 'editor-alignright', 'editor-bold', 'editor-break', 'editor-code',
		'editor-code-duplicate', 'editor-contract', 'editor-customchar', 'editor-distractionfree', 'editor-expand',
		'editor-help', 'editor-indent', 'editor-insertmore', 'editor-italic', 'editor-justify', 'editor-kitchensink',
		'editor-ltr', 'editor-ol', 'editor-ol-rtl', 'editor-outdent', 'editor-paragraph', 'editor-paste-text',
		'editor-paste-word', 'editor-quote', 'editor-removeformatting', 'editor-rtl', 'editor-spellcheck',
		'editor-strikethrough', 'editor-table', 'editor-textcolor', 'editor-ul', 'editor-underline', 'editor-unlink',
		'editor-video', 'ellipsis', 'email', 'email-alt', 'email-alt2', 'embed-audio', 'embed-generic', 'embed-photo',
		'embed-post', 'embed-video', 'excerpt-view', 'exerpt-view', 'exit', 'external', 'facebook', 'facebook-alt',
		'feedback', 'filter', 'flag', 'food', 'format-aside', 'format-audio', 'format-chat', 'format-gallery',
		'format-image', 'format-links', 'format-quote', 'format-standard', 'format-status', 'format-video', 'forms',
		'fullscreen-alt', 'fullscreen-exit-alt', 'games', 'google', 'googleplus', 'grid-view', 'groups', 'hammer',
		'heading', 'heart', 'hidden', 'hourglass', 'html', 'id', 'id-alt', 'image-crop', 'image-filter',
		'image-flip-horizontal', 'image-flip-vertical', 'image-rotate', 'image-rotate-left', 'image-rotate-right',
		'images-alt', 'images-alt2', 'index-card', 'info', 'info-outline', 'insert', 'insert-after', 'insert-before',
		'instagram', 'laptop', 'layout', 'leftright', 'lightbulb', 'linkedin', 'list-view', 'location', 'location-alt',
		'lock', 'lock-duplicate', 'marker', 'media-archive', 'media-audio', 'media-code', 'media-default',
		'media-document', 'media-interactive', 'media-spreadsheet', 'media-text', 'media-video', 'megaphone', 'menu',
		'menu-alt', 'menu-alt2', 'menu-alt3', 'microphone', 'migrate', 'minus', 'money', 'money-alt', 'move', 'nametag',
		'networking', 'no', 'no-alt', 'open-folder', 'palmtree', 'paperclip', 'pdf', 'performance', 'pets', 'phone',
		'pinterest', 'playlist-audio', 'playlist-video', 'plugins-checked', 'plus', 'plus-alt', 'plus-alt2', 'podio',
		'portfolio', 'post-status', 'post-trash', 'pressthis', 'printer', 'privacy', 'products', 'randomize', 'reddit',
		'redo', 'remove', 'rest-api', 'rss', 'saved', 'schedule', 'screenoptions', 'search', 'share', 'share-alt',
		'share-alt2', 'share1', 'shield', 'shield-alt', 'shortcode', 'slides', 'smartphone', 'smiley', 'sort', 'sos',
		'spotify', 'star-empty', 'star-filled', 'star-half', 'sticky', 'store', 'superhero', 'superhero-alt',
		'table-col-after', 'table-col-before', 'table-col-delete', 'table-row-after', 'table-row-before',
		'table-row-delete', 'tablet', 'tag', 'tagcloud', 'testimonial', 'text', 'text-page', 'thumbs-down', 'thumbs-up',
		'tickets', 'tickets-alt', 'tide', 'translation', 'trash', 'twitch', 'twitter', 'twitter-alt', 'undo',
		'universal-access', 'universal-access-alt', 'unlock', 'update', 'update-alt', 'upload', 'vault', 'video-alt',
		'video-alt2', 'video-alt3', 'visibility', 'warning', 'welcome-add-page', 'welcome-comments', 'welcome-edit-page',
		'welcome-learn-more', 'welcome-view-site', 'welcome-widgets-menus', 'welcome-write-blog', 'whatsapp', 'wordpress',
		'wordpress-alt', 'xing', 'yes', 'yes-alt', 'youtube',
	);

	/**
	 * In-request cache of loaded svg inner markup, name => markup.
	 *
	 * @var array<string, string>
	 */
	private static array $cache = array();

	/**
	 * Allowed icon names.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return self::ALLOWLIST;
	}

	/**
	 * name => label list for a <select>.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$choices = array();
		foreach ( self::ALLOWLIST as $name ) {
			$choices[ $name ] = ucwords( str_replace( '-', ' ', $name ) );
		}
		return $choices;
	}

	/**
	 * Dashicons class names including the prefix ("dashicons-admin-home"), the form the value is stored in (and the form
	 * the Menu Icons plugin stores).
	 *
	 * @return string[]
	 */
	public static function dashicons(): array {
		return array_map( static fn ( string $name ): string => 'dashicons-' . $name, self::DASHICONS );
	}

	/**
	 * Whether a value is a core Dashicon class ("dashicons-admin-home").
	 *
	 * @param string $class Stored value.
	 */
	public static function dashicon_exists( string $class ): bool {
		return 0 === strpos( $class, 'dashicons-' ) && in_array( substr( $class, 10 ), self::DASHICONS, true );
	}

	/**
	 * Class => label list for a <select> ("dashicons-admin-home" => "Admin home").
	 *
	 * @return array<string, string>
	 */
	public static function dashicon_choices(): array {
		$choices = array();
		foreach ( self::DASHICONS as $name ) {
			$choices[ 'dashicons-' . $name ] = ucfirst( str_replace( '-', ' ', $name ) );
		}
		return $choices;
	}

	/** Whether a name is on the allowlist. */
	public static function exists( string $name ): bool {
		return in_array( $name, self::ALLOWLIST, true );
	}

	/**
	 * Sanitized inner SVG content (viewBox 0 0 24 24, stroke-based) for one
	 * icon, ready to wrap in our own `<svg>` element with runtime attributes.
	 *
	 * @param string $name Icon name.
	 * @return string Inner markup, or '' if not allowlisted / file missing.
	 */
	public static function svg_inner( string $name ): string {
		if ( ! self::exists( $name ) ) {
			return '';
		}

		if ( isset( self::$cache[ $name ] ) ) {
			return self::$cache[ $name ];
		}

		$file = __DIR__ . '/assets/lucide/' . $name . '.svg';
		if ( ! is_readable( $file ) ) {
			self::$cache[ $name ] = '';
			return '';
		}

		$raw = (string) file_get_contents( $file );
		// Files come from our own synced assets, but still run them through the
		// same strict sanitizer as user-pasted SVG before ever echoing them.
		$sanitized = SvgSanitizer::sanitize( $raw );
		if ( '' === $sanitized ) {
			self::$cache[ $name ] = '';
			return '';
		}

		// Strip the outer <svg ...> wrapper, keep only the inner shapes - the
		// caller supplies its own <svg> root with runtime size/color attrs.
		$inner = preg_replace( '/^<svg\b[^>]*>|<\/svg>\s*$/i', '', $sanitized ) ?? '';
		self::$cache[ $name ] = trim( $inner );
		return self::$cache[ $name ];
	}

	/**
	 * Full self-contained `<svg>` markup for an icon (used in admin previews
	 * and localized JS data).
	 *
	 * @param string $name Icon name.
	 * @param int    $size Pixel size for width/height.
	 */
	public static function svg_markup( string $name, int $size = 20 ): string {
		$inner = self::svg_inner( $name );
		if ( '' === $inner ) {
			return '';
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
			$size,
			$inner
		);
	}
}
