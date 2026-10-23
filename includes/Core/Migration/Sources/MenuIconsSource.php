<?php
/**
 * Source: Menu Icons.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractMetaSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\MenuIcons\IconLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `menu-icons`: the menu item gets `_uxstudio_menu_item_icon_type` (dashicons | lucide | media),
 * `_uxstudio_menu_item_icon_value` and, when not "before", `_uxstudio_menu_item_icon_position`.
 *
 * Menu Icons keeps one array in the meta `menu-icons` per menu item: `type` (dashicons, fa, genericon,
 * image, svg ...), `icon` (class name or, for image and svg, an attachment id), `hide_label`, `position`.
 * A Dashicon stays the same Dashicon: the module has the whole WordPress core set and stores the class name, 1:1.
 * Any other font icon (Font Awesome, Genericons ...) is mapped to the nearest icon of the module's curated Lucide list
 * (IconLibrary); an icon without a counterpart is reported by name and the item gets no icon.
 * Image and SVG icons become media icons.
 *
 * Visual change, but a second icon next to the old plugin's would look broken, so it follows the same rule:
 * module on = prepared, module off = written.
 */
final class MenuIconsSource extends AbstractMetaSource {

	/** Dashicons class → Lucide name (all names are on the module allowlist, checked by the tests). */
	public const DASHICONS = array(
		'admin-home' => 'house', 'email' => 'mail', 'email-alt' => 'mail', 'phone' => 'phone', 'location' => 'map-pin',
		'location-alt' => 'map-pin', 'star-filled' => 'star', 'star-empty' => 'star', 'heart' => 'heart', 'admin-users' => 'user',
		'businessman' => 'user', 'groups' => 'users', 'search' => 'search', 'menu' => 'menu', 'no' => 'x', 'no-alt' => 'x',
		'yes' => 'check', 'yes-alt' => 'check', 'arrow-right' => 'arrow-right', 'arrow-right-alt' => 'arrow-right',
		'arrow-right-alt2' => 'arrow-right', 'arrow-down-alt2' => 'chevron-down', 'external' => 'external-link', 'calendar' => 'calendar',
		'calendar-alt' => 'calendar', 'clock' => 'clock', 'admin-site' => 'globe', 'admin-site-alt' => 'globe', 'admin-site-alt2' => 'globe',
		'admin-site-alt3' => 'globe', 'facebook' => 'facebook', 'facebook-alt' => 'facebook', 'instagram' => 'instagram',
		'linkedin' => 'linkedin', 'youtube' => 'youtube', 'twitter' => 'twitter', 'download' => 'download', 'upload' => 'upload',
		'media-text' => 'file-text', 'media-document' => 'file-text', 'admin-settings' => 'settings', 'admin-generic' => 'settings',
		'info' => 'info', 'info-outline' => 'info', 'bell' => 'bell', 'lock' => 'lock', 'cart' => 'shopping-cart', 'tag' => 'tag',
		'editor-help' => 'circle-help', 'format-chat' => 'message-circle', 'testimonial' => 'message-circle', 'admin-comments' => 'message-circle',
	);

	/** Font Awesome (4, 5 and 6 names without the `fa-` prefix) → Lucide name. */
	public const FONT_AWESOME = array(
		'home' => 'house', 'house' => 'house', 'envelope' => 'mail', 'envelope-o' => 'mail', 'phone' => 'phone', 'map-marker' => 'map-pin',
		'map-marker-alt' => 'map-pin', 'location-dot' => 'map-pin', 'star' => 'star', 'star-o' => 'star', 'heart' => 'heart',
		'heart-o' => 'heart', 'user' => 'user', 'user-o' => 'user', 'users' => 'users', 'search' => 'search', 'magnifying-glass' => 'search',
		'bars' => 'menu', 'times' => 'x', 'xmark' => 'x', 'check' => 'check', 'chevron-right' => 'chevron-right', 'angle-right' => 'chevron-right',
		'chevron-down' => 'chevron-down', 'angle-down' => 'chevron-down', 'arrow-right' => 'arrow-right', 'external-link' => 'external-link',
		'external-link-alt' => 'external-link', 'up-right-from-square' => 'external-link', 'calendar' => 'calendar', 'calendar-alt' => 'calendar',
		'calendar-days' => 'calendar', 'clock' => 'clock', 'clock-o' => 'clock', 'globe' => 'globe', 'facebook' => 'facebook',
		'facebook-f' => 'facebook', 'facebook-square' => 'facebook', 'instagram' => 'instagram', 'linkedin' => 'linkedin',
		'linkedin-in' => 'linkedin', 'youtube' => 'youtube', 'twitter' => 'twitter', 'x-twitter' => 'twitter', 'download' => 'download',
		'upload' => 'upload', 'file-text' => 'file-text', 'file-text-o' => 'file-text', 'file-lines' => 'file-text', 'cog' => 'settings',
		'gear' => 'settings', 'info' => 'info', 'info-circle' => 'info', 'circle-info' => 'info', 'bell' => 'bell', 'bell-o' => 'bell',
		'lock' => 'lock', 'shopping-cart' => 'shopping-cart', 'cart-shopping' => 'shopping-cart', 'tag' => 'tag', 'question-circle' => 'circle-help',
		'circle-question' => 'circle-help', 'comment' => 'message-circle', 'comments' => 'message-circle', 'comment-o' => 'message-circle',
	);

	/** Style and size classes of Font Awesome that are not the icon itself. */
	private const FA_NOISE = array( 'fa', 'fas', 'far', 'fab', 'fal', 'fad', 'fa-solid', 'fa-regular', 'fa-brands', 'fa-light', 'fa-thin', 'fa-duotone', 'fa-fw', 'fa-lg', 'fa-2x', 'fa-3x', 'fa-4x', 'fa-5x', 'fa-xs', 'fa-sm' );

	protected function meta_type(): string {
		return 'post';
	}

	protected function data_kind(): string {
		return 'menu_icon';
	}

	protected function data_label(): string {
		return __( 'Menu icons', 'ux-studio' );
	}

	/**
	 * The Dashicon class ("dashicons-admin-home") in the class list the plugin stored, or null when it is not on the core
	 * list of the module (for example a name from a newer or older WordPress); such an icon falls back to the nearest Lucide.
	 *
	 * @param string $icon Class name(s) stored by the plugin.
	 */
	public static function dashicon_for( string $icon ): ?string {
		foreach ( preg_split( '/\s+/', trim( $icon ) ) ?: array() as $token ) {
			if ( 0 === strpos( $token, 'dashicons-' ) ) {
				return IconLibrary::dashicon_exists( $token ) ? $token : null;
			}
		}
		return null;
	}

	/**
	 * Lucide name for a font icon, or null.
	 *
	 * @param string $type Menu Icons font type (`dashicons`, `fa`, ...).
	 * @param string $icon Class name(s) stored by the plugin.
	 */
	public static function lucide_for( string $type, string $icon ): ?string {
		$tokens = preg_split( '/\s+/', trim( $icon ) ) ?: array();
		if ( 'dashicons' === $type ) {
			foreach ( $tokens as $token ) {
				if ( 0 === strpos( $token, 'dashicons-' ) ) {
					return self::DASHICONS[ substr( $token, 10 ) ] ?? null;
				}
			}
			return null;
		}
		if ( 'fa' === $type || 0 === strpos( $type, 'font-awesome' ) || 0 === strpos( $type, 'fa' ) ) {
			$name = '';
			foreach ( $tokens as $token ) {
				if ( 0 === strpos( $token, 'fa-' ) && ! in_array( $token, self::FA_NOISE, true ) ) {
					$name = substr( $token, 3 );
				}
			}
			return self::FONT_AWESOME[ $name ] ?? null;
		}
		return null;
	}

	protected function build(): array {
		global $wpdb;
		$out  = array(
			'items'   => array(),
			'skipped' => array(),
		);
		$ids  = $wpdb->get_col( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'menu-icons' AND p.post_type = 'nav_menu_item' AND p.post_status <> 'trash' ORDER BY m.post_id ASC" ); // phpcs:ignore WordPress.DB
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$icon = get_post_meta( $id, 'menu-icons', true );
			if ( ! is_array( $icon ) || empty( $icon['type'] ) || empty( $icon['icon'] ) ) {
				continue;
			}
			$type = (string) $icon['type'];
			$post = get_post( $id );
			$name = $post ? trim( (string) $post->post_title ) : '';
			$meta = array();
			if ( in_array( $type, array( 'image', 'svg' ), true ) ) {
				if ( ! get_post( (int) $icon['icon'] ) ) {
					$out['skipped']['missing'] = ( $out['skipped']['missing'] ?? 0 ) + 1;
					continue;
				}
				$meta['_uxstudio_menu_item_icon_type']  = 'media';
				$meta['_uxstudio_menu_item_icon_value'] = (string) (int) $icon['icon'];
			} else {
				$dashicon = 'dashicons' === $type ? self::dashicon_for( (string) $icon['icon'] ) : null;
				if ( null !== $dashicon ) {
					$meta['_uxstudio_menu_item_icon_type']  = 'dashicons';
					$meta['_uxstudio_menu_item_icon_value'] = $dashicon;
				} else {
					$lucide = self::lucide_for( $type, (string) $icon['icon'] );
					if ( null === $lucide || ! IconLibrary::exists( $lucide ) ) {
						$label                           = $type . ': ' . trim( (string) $icon['icon'] );
						$out['skipped'][ 'icon:' . $label ] = ( $out['skipped'][ 'icon:' . $label ] ?? 0 ) + 1;
						continue;
					}
					$meta['_uxstudio_menu_item_icon_type']  = 'lucide';
					$meta['_uxstudio_menu_item_icon_value'] = $lucide;
				}
			}
			if ( ! empty( $icon['hide_label'] ) ) {
				$meta['_uxstudio_menu_item_icon_position'] = 'only';
			} elseif ( 'after' === ( $icon['position'] ?? 'before' ) ) {
				$meta['_uxstudio_menu_item_icon_position'] = 'after';
			}
			foreach ( array( 'vertical_align', 'font_size', 'svg_width', 'image_size' ) as $detail ) {
				if ( ! empty( $icon[ $detail ] ) && ! in_array( (string) $icon[ $detail ], array( 'middle', 'thumbnail', '1.2' ), true ) ) {
					$out['skipped']['sizing'] = ( $out['skipped']['sizing'] ?? 0 ) + 1;
					break;
				}
			}
			$out['items'][] = array(
				'key'    => 'menu-icons:' . $id,
				'label'  => '' !== $name ? $name : '#' . $id,
				'id'     => $id,
				'meta'   => $meta,
				'review' => false,
			);
		}
		// "sizing" does not skip an item, it only reports that the look differs.
		return $out;
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->collect();
		if ( ! $c['items'] && ! $c['skipped'] ) {
			$m->note( __( 'No menu item has an icon, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$unmapped = array();
		foreach ( $c['skipped'] as $reason => $n ) {
			if ( 0 === strpos( $reason, 'icon:' ) ) {
				$unmapped[] = substr( $reason, 5 ) . ( $n > 1 ? ' (' . $n . 'x)' : '' );
			} elseif ( 'missing' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of icons. */
						__( '%d image icons point to a file that no longer exists and are not transferred.', 'ux-studio' ),
						$n
					)
				);
			} elseif ( 'sizing' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of icons. */
						__( '%d icons had their own size or vertical alignment. The module uses the single icon size from its settings.', 'ux-studio' ),
						$n
					)
				);
			}
		}
		if ( $unmapped ) {
			$m->lossy(
				sprintf(
					/* translators: %s: list of icon names. */
					__( 'These font icons have no counterpart among the icons of the module, so the menu items get no icon: %s.', 'ux-studio' ),
					implode( ', ', $unmapped )
				)
			);
		}
		$dash = 0;
		$font = 0;
		foreach ( $c['items'] as $item ) {
			$dash += 'dashicons' === $item['meta']['_uxstudio_menu_item_icon_type'] ? 1 : 0;
			$font += 'lucide' === $item['meta']['_uxstudio_menu_item_icon_type'] ? 1 : 0;
		}
		if ( $dash > 0 ) {
			$m->note(
				sprintf(
					/* translators: %d: number of icons. */
					__( '%d Dashicons are transferred as they are (the module has the whole WordPress Dashicons set).', 'ux-studio' ),
					$dash
				)
			);
		}
		if ( $font > 0 ) {
			$m->note( __( 'Other font icons (for example Font Awesome) are replaced by the nearest Lucide icon. Pick a different one on the menu screen if it does not fit.', 'ux-studio' ) );
		}
		return $m;
	}

	protected function data_plan( array $options ): array {
		$lucide = 0;
		$dash   = 0;
		$media  = 0;
		foreach ( $this->collect()['items'] as $item ) {
			if ( 'lucide' === $item['meta']['_uxstudio_menu_item_icon_type'] ) {
				++$lucide;
			} elseif ( 'dashicons' === $item['meta']['_uxstudio_menu_item_icon_type'] ) {
				++$dash;
			} else {
				++$media;
			}
		}
		return array(
			array(
				'key'   => 'dashicons',
				'label' => __( 'Dashicons kept as they are', 'ux-studio' ),
				'count' => $dash,
			),
			array(
				'key'   => 'lucide',
				'label' => __( 'Icons replaced by a Lucide icon', 'ux-studio' ),
				'count' => $lucide,
			),
			array(
				'key'   => 'media',
				'label' => __( 'Image and SVG icons', 'ux-studio' ),
				'count' => $media,
			),
		);
	}
}
