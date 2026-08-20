<?php
/**
 * MCP resource exposing WordPress site settings (general, reading,
 * discussion, media, permalink, privacy, writing) as read-only context.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Resources;

defined( 'ABSPATH' ) || exit;

class SiteSettingsResource {

	private const RESOURCE_URI = 'wordpress://site-settings';

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'ai-assistant/site-settings',
			array(
				'label'               => __( 'Site Settings', 'ux-studio' ),
				'description'         => __( 'Provides detailed information about WordPress site settings including general, reading, discussion, media, permalink, privacy, and writing settings', 'ux-studio' ),
				'category'            => 'ai-assistant',
				'execute_callback'    => array( $this, 'get_site_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'uri'          => self::RESOURCE_URI,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'resource',
					),
					'annotations'  => array(
						'title'    => __( 'Site Settings', 'ux-studio' ),
						'readonly' => true,
					),
				),
			)
		);
	}

	public function get_site_settings(): array {
		return array(
			array(
				'uri'        => self::RESOURCE_URI,
				'text'       => '',
				'blob'       => '',
				'general'    => $this->get_general_settings(),
				'reading'    => $this->get_reading_settings(),
				'discussion' => $this->get_discussion_settings(),
				'media'      => $this->get_media_settings(),
				'permalink'  => $this->get_permalink_settings(),
				'privacy'    => $this->get_privacy_settings(),
				'writing'    => $this->get_writing_settings(),
				'misc'       => $this->get_misc_settings(),
			),
		);
	}

	private function get_general_settings(): array {
		return array(
			'site_title'    => get_bloginfo( 'name' ),
			'site_tagline'  => get_bloginfo( 'description' ),
			'site_url'      => get_bloginfo( 'url' ),
			'admin_email'   => get_bloginfo( 'admin_email' ),
			'membership'    => get_option( 'users_can_register' ) ? 'Anyone can register' : 'Only administrators can add new users',
			'default_role'  => get_option( 'default_role' ),
			'site_language' => get_bloginfo( 'language' ),
			'timezone'      => wp_timezone_string(),
			'date_format'   => get_option( 'date_format' ),
			'time_format'   => get_option( 'time_format' ),
			'start_of_week' => get_option( 'start_of_week' ),
		);
	}

	private function get_reading_settings(): array {
		$show_on_front = get_option( 'show_on_front' );
		$page_on_front = get_option( 'page_on_front' );
		$page_for_posts = get_option( 'page_for_posts' );

		$front_page    = 'posts';
		$front_page_id = 0;
		$posts_page_id = 0;

		if ( 'page' === $show_on_front ) {
			$front_page    = 'page';
			$front_page_id = $page_on_front;
		}

		if ( $page_for_posts ) {
			$posts_page_id = $page_for_posts;
		}

		return array(
			'front_page_displays' => $front_page,
			'front_page_id'       => $front_page_id,
			'posts_page_id'       => $posts_page_id,
			'posts_per_page'      => get_option( 'posts_per_page' ),
			'posts_per_rss'       => get_option( 'posts_per_rss' ),
			'rss_use_excerpt'     => get_option( 'rss_use_excerpt' ),
		);
	}

	private function get_discussion_settings(): array {
		return array(
			'default_comment_status'       => get_option( 'default_comment_status' ),
			'default_ping_status'          => get_option( 'default_ping_status' ),
			'comment_moderation'           => get_option( 'comment_moderation' ),
			'require_name_email'           => get_option( 'require_name_email' ),
			'comment_registration'         => get_option( 'comment_registration' ),
			'close_comments_for_old_posts' => get_option( 'close_comments_for_old_posts' ),
			'close_comments_days_old'      => get_option( 'close_comments_days_old' ),
			'thread_comments'              => get_option( 'thread_comments' ),
			'thread_comments_depth'        => get_option( 'thread_comments_depth' ),
			'page_comments'                => get_option( 'page_comments' ),
			'comments_per_page'            => get_option( 'comments_per_page' ),
			'default_comments_page'        => get_option( 'default_comments_page' ),
			'comment_order'                => get_option( 'comment_order' ),
			'comments_notify'              => get_option( 'comments_notify' ),
			'moderation_notify'            => get_option( 'moderation_notify' ),
			'comment_previously_approved'  => get_option( 'comment_previously_approved' ),
			'comment_max_links'            => get_option( 'comment_max_links' ),
			'moderation_keys'              => get_option( 'moderation_keys' ),
			'disallowed_keys'              => get_option( 'disallowed_keys' ),
		);
	}

	private function get_media_settings(): array {
		return array(
			'thumbnail_size_w'              => get_option( 'thumbnail_size_w' ),
			'thumbnail_size_h'              => get_option( 'thumbnail_size_h' ),
			'thumbnail_crop'                => get_option( 'thumbnail_crop' ),
			'medium_size_w'                 => get_option( 'medium_size_w' ),
			'medium_size_h'                 => get_option( 'medium_size_h' ),
			'large_size_w'                  => get_option( 'large_size_w' ),
			'large_size_h'                  => get_option( 'large_size_h' ),
			'image_default_size'            => get_option( 'image_default_size' ),
			'image_default_align'           => get_option( 'image_default_align' ),
			'image_default_link_type'       => get_option( 'image_default_link_type' ),
			'uploads_use_yearmonth_folders' => get_option( 'uploads_use_yearmonth_folders' ),
		);
	}

	private function get_permalink_settings(): array {
		$permalink_structure = get_option( 'permalink_structure' );

		return array(
			'permalink_structure'      => $permalink_structure,
			'category_base'            => get_option( 'category_base' ),
			'tag_base'                 => get_option( 'tag_base' ),
			'permalink_structure_name' => $this->get_permalink_structure_name( $permalink_structure ),
		);
	}

	private function get_privacy_settings(): array {
		$privacy_page_id = get_option( 'wp_page_for_privacy_policy' );

		return array(
			'privacy_policy_page_id'    => $privacy_page_id,
			'privacy_policy_page_title' => $privacy_page_id ? get_the_title( $privacy_page_id ) : '',
			'blog_public'               => get_option( 'blog_public' ),
		);
	}

	private function get_writing_settings(): array {
		return array(
			'default_category'      => get_option( 'default_category' ),
			'default_email_category' => get_option( 'default_email_category' ),
			'default_link_category' => get_option( 'default_link_category' ),
			'default_post_format'   => get_option( 'default_post_format' ),
		);
	}

	private function get_misc_settings(): array {
		return array(
			'use_smilies'      => get_option( 'use_smilies' ),
			'use_balanceTags'  => get_option( 'use_balanceTags' ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		);
	}

	private function get_permalink_structure_name( string $structure ): string {
		if ( empty( $structure ) ) {
			return 'Plain';
		}
		if ( '/%postname%/' === $structure ) {
			return 'Post name';
		}
		if ( '/archives/%post_id%' === $structure ) {
			return 'Numeric';
		}
		if ( false !== strpos( $structure, '%category%' ) ) {
			return 'Category based';
		}
		if ( false !== strpos( $structure, '%author%' ) ) {
			return 'Author based';
		}
		if ( false !== strpos( $structure, '%postname%' ) ) {
			return 'Custom Structure';
		}

		return 'Custom';
	}
}
