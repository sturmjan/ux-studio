<?php
/**
 * Source: Yoast Duplicate Post, Duplicate Page, Post Duplicator.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `duplicate-post`: post types, status after duplicating, redirect,
 * elements to copy and the title format.
 */
final class DuplicatePostSource extends AbstractSource {

	private const MODULE = 'duplicate-post';

	/** Statuses the module offers (get_post_statuses()). */
	private const STATUSES = array( 'draft', 'pending', 'private', 'publish' );

	protected function option_names(): array {
		switch ( $this->slug ) {
			case 'duplicate-page':
				return array( 'duplicate_page_options' );
			case 'post-duplicator':
				return array( 'mtphr_post_duplicator_settings' );
			default:
				return array(
					'duplicate_post_types_enabled',
					'duplicate_post_copytitle',
					'duplicate_post_copycontent',
					'duplicate_post_title_prefix',
					'duplicate_post_title_suffix',
				);
		}
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		if ( ! $this->has_data() ) {
			$m->note( __( 'The plugin has no stored settings, the module keeps its defaults.', 'ux-studio' ) );
			return $m;
		}
		switch ( $this->slug ) {
			case 'duplicate-page':
				$this->map_duplicate_page( $m );
				break;
			case 'post-duplicator':
				$this->map_post_duplicator( $m );
				break;
			default:
				$this->map_yoast( $m );
		}
		return $m;
	}

	/**
	 * Duplicate Page: `duplicate_page_options` { duplicate_post_status, _redirect, _suffix, _editor }.
	 *
	 * @param Mapping $m Mapping to fill.
	 */
	private function map_duplicate_page( Mapping $m ): void {
		$o = (array) $this->opt( 'duplicate_page_options' );

		$status = (string) ( $o['duplicate_post_status'] ?? '' );
		if ( in_array( $status, self::STATUSES, true ) ) {
			$m->set( self::MODULE, 'post_status', $status, __( 'Post status', 'ux-studio' ), 'duplicate_page_options:duplicate_post_status' );
		} elseif ( '' !== $status ) {
			$m->lossy(
				sprintf(
					/* translators: %s: post status. */
					__( 'The status "%s" is not offered by the module, the module keeps its own.', 'ux-studio' ),
					$status
				)
			);
		}

		if ( isset( $o['duplicate_post_redirect'] ) ) {
			$m->set( self::MODULE, 'post_redirect', 'to_page' === $o['duplicate_post_redirect'], __( 'Redirect to post', 'ux-studio' ), 'duplicate_page_options:duplicate_post_redirect' );
		}

		$suffix = trim( (string) ( $o['duplicate_post_suffix'] ?? '' ) );
		if ( '' !== $suffix ) {
			$m->set( self::MODULE, 'custom_title', true, __( 'Customise post title', 'ux-studio' ), 'duplicate_page_options:duplicate_post_suffix' );
			$m->set( self::MODULE, 'custom_title_format', '{post_title} -- ' . $suffix, __( 'Post title format', 'ux-studio' ), 'duplicate_page_options:duplicate_post_suffix' );
		} elseif ( isset( $o['duplicate_post_suffix'] ) ) {
			$m->set( self::MODULE, 'custom_title', false, __( 'Customise post title', 'ux-studio' ), 'duplicate_page_options:duplicate_post_suffix' );
		}

		$m->lossy( __( 'The plugin offers duplication for every post type with a list screen; the module offers it only for the post types selected in its settings (default: posts and pages). Check the list after switching over.', 'ux-studio' ) );
	}

	/**
	 * Post Duplicator: `mtphr_post_duplicator_settings`.
	 *
	 * @param Mapping $m Mapping to fill.
	 */
	private function map_post_duplicator( Mapping $m ): void {
		$o = (array) $this->opt( 'mtphr_post_duplicator_settings' );

		$status = (string) ( $o['status'] ?? 'draft' );
		if ( in_array( $status, self::STATUSES, true ) ) {
			$m->set( self::MODULE, 'post_status', $status, __( 'Post status', 'ux-studio' ), 'mtphr_post_duplicator_settings:status' );
		} else {
			$m->lossy(
				sprintf(
					/* translators: %s: post status or "same". */
					__( 'The copy status "%s" (same as the original or scheduled) does not exist in the module, it uses a fixed status.', 'ux-studio' ),
					$status
				)
			);
		}

		$title = trim( (string) ( $o['title'] ?? '' ) );
		if ( '' !== $title ) {
			$m->set( self::MODULE, 'custom_title', true, __( 'Customise post title', 'ux-studio' ), 'mtphr_post_duplicator_settings:title' );
			$m->set( self::MODULE, 'custom_title_format', '{post_title} ' . $title, __( 'Post title format', 'ux-studio' ), 'mtphr_post_duplicator_settings:title' );
		}

		$action = (string) ( $o['single_after_duplication_action'] ?? '' );
		if ( '' !== $action ) {
			$m->set( self::MODULE, 'post_redirect', in_array( $action, array( 'new_tab', 'same_tab' ), true ), __( 'Redirect to post', 'ux-studio' ), 'mtphr_post_duplicator_settings:single_after_duplication_action' );
		}

		if ( isset( $o['post_types_config'] ) && is_array( $o['post_types_config'] ) ) {
			$types = array();
			foreach ( $o['post_types_config'] as $type => $cfg ) {
				if ( is_array( $cfg ) && ! empty( $cfg['allow_duplication'] ) ) {
					$types[] = (string) $type;
				}
			}
			$m->set( self::MODULE, 'post_types', $types, __( 'Post types', 'ux-studio' ), 'mtphr_post_duplicator_settings:post_types_config' );
		}

		$lost = array();
		if ( 'same' !== ( $o['type'] ?? 'same' ) ) {
			$lost[] = __( 'post type of the copy', 'ux-studio' );
		}
		if ( 'current' !== ( $o['timestamp'] ?? 'current' ) ) {
			$lost[] = __( 'date of the copy', 'ux-studio' );
		}
		if ( 'current_user' !== ( $o['post_author'] ?? 'current_user' ) ) {
			$lost[] = __( 'author of the copy', 'ux-studio' );
		}
		if ( '' !== trim( (string) ( $o['slug'] ?? 'copy' ) ) ) {
			$lost[] = __( 'suffix of the slug', 'ux-studio' );
		}
		if ( $lost ) {
			$m->lossy(
				sprintf(
					/* translators: %s: comma-separated list of plugin features. */
					__( 'Not available in the module: %s.', 'ux-studio' ),
					implode( ', ', $lost )
				)
			);
		}
	}

	/**
	 * Yoast Duplicate Post: one option per setting (`duplicate_post_*`).
	 *
	 * @param Mapping $m Mapping to fill.
	 */
	private function map_yoast( Mapping $m ): void {
		$types = $this->opt( 'duplicate_post_types_enabled' );
		if ( null !== $types ) {
			$m->set( self::MODULE, 'post_types', (array) $types, __( 'Post types', 'ux-studio' ), 'duplicate_post_types_enabled' );
		}

		$on = fn( string $name ): bool => ! empty( $this->opt( 'duplicate_post_' . $name ) );

		$elements = array();
		if ( $on( 'copycontent' ) ) {
			$elements[] = 'post_content';
		}
		if ( $on( 'copyexcerpt' ) ) {
			$elements[] = 'post_excerpt';
		}
		if ( $on( 'copythumbnail' ) ) {
			$elements[] = 'featured_image';
		}
		$elements[] = 'taxonomies';
		$elements[] = 'meta_fields';
		if ( $on( 'copymenuorder' ) ) {
			$elements[] = 'menu_order';
		}
		$elements[] = 'post_parent';
		if ( class_exists( 'WooCommerce' ) ) {
			$elements[] = 'product_gallery';
		}
		$m->set( self::MODULE, 'duplicate_elements', $elements, __( 'Elements to duplicate', 'ux-studio' ), 'duplicate_post_copy*' );

		$prefix = trim( (string) $this->opt( 'duplicate_post_title_prefix' ) );
		$suffix = trim( (string) $this->opt( 'duplicate_post_title_suffix' ) );
		if ( '' !== $prefix || '' !== $suffix ) {
			$format = ( '' !== $prefix ? $prefix . ' ' : '' ) . '{post_title}' . ( '' !== $suffix ? ' ' . $suffix : '' );
			$m->set( self::MODULE, 'custom_title', true, __( 'Customise post title', 'ux-studio' ), 'duplicate_post_title_prefix' );
			$m->set( self::MODULE, 'custom_title_format', $format, __( 'Post title format', 'ux-studio' ), 'duplicate_post_title_suffix' );
		} else {
			$m->set( self::MODULE, 'custom_title', false, __( 'Customise post title', 'ux-studio' ), 'duplicate_post_title_prefix' );
		}

		if ( $on( 'copystatus' ) ) {
			$m->lossy( __( 'The plugin gives the copy the same status as the original. The module uses one fixed status (draft unless you change it).', 'ux-studio' ) );
		}
		$extra = array();
		foreach ( array(
			'copydate'        => __( 'publish date', 'ux-studio' ),
			'copyslug'        => __( 'slug', 'ux-studio' ),
			'copyauthor'      => __( 'author', 'ux-studio' ),
			'copypassword'    => __( 'password', 'ux-studio' ),
			'copyformat'      => __( 'post format', 'ux-studio' ),
			'copytemplate'    => __( 'page template', 'ux-studio' ),
			'copyattachments' => __( 'attachments', 'ux-studio' ),
			'copychildren'    => __( 'child pages', 'ux-studio' ),
			'copycomments'    => __( 'comments', 'ux-studio' ),
		) as $name => $label ) {
			if ( $on( $name ) ) {
				$extra[] = $label;
			}
		}
		if ( $extra ) {
			$m->lossy(
				sprintf(
					/* translators: %s: comma-separated list of things the plugin copies. */
					__( 'The plugin also copies these, the module does not: %s.', 'ux-studio' ),
					implode( ', ', $extra )
				)
			);
		}
		if ( '' !== trim( (string) $this->opt( 'duplicate_post_blacklist' ) ) ) {
			$m->lossy( __( 'The list of custom fields that are not copied is not transferred - the module copies all custom fields.', 'ux-studio' ) );
		}
		if ( ! empty( $this->opt( 'duplicate_post_taxonomies_blacklist' ) ) ) {
			$m->lossy( __( 'The list of taxonomies that are not copied is not transferred - the module copies all taxonomies.', 'ux-studio' ) );
		}
		if ( (int) $this->opt( 'duplicate_post_increase_menu_order_by' ) > 0 ) {
			$m->lossy( __( 'Increasing the menu order of copies is not available in the module.', 'ux-studio' ) );
		}
		$m->lossy( __( 'Permissions by role, "Rewrite & Republish" and the "original item" references of the plugin do not exist in the module.', 'ux-studio' ) );
	}
}
