<?php
/**
 * Source: Disable Comments.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Option `disable_comments_options` { remove_everywhere, disabled_post_types,
 * show_existing_comments, remove_xmlrpc_comments, remove_rest_API_comments,
 * enable_exclude_by_role + exclude_by_role, enable_conditional_rules, ... }
 * -> Security Optimization keys `disable_comments*`.
 */
final class DisableCommentsSource extends AbstractSource {

	private const MODULE = 'security-optimization';

	protected function option_names(): array {
		return array( 'disable_comments_options' );
	}

	public function smoke_checks(): array {
		$settings = (array) ( $this->mapping()->settings[ self::MODULE ] ?? array() );
		if ( empty( $settings['disable_comments'] ) ) {
			return array();
		}
		// With "selected types" the comment form only has to go from a post when posts are among them.
		if ( 'selected' === ( $settings['disable_comments_scope'] ?? 'all' ) && ! in_array( 'post', (array) ( $settings['disable_comments_post_types'] ?? array() ), true ) ) {
			return array();
		}
		return array(
			array(
				'id'    => 'comments',
				'label' => __( 'Comments are disabled (no comment form on a post)', 'ux-studio' ),
				'type'  => 'comment_form_absent',
			),
		);
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'disable_comments_options' );
		if ( ! is_array( $o ) ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$origin     = 'disable_comments_options';
		$everywhere = ! empty( $o['remove_everywhere'] );
		$types      = array_values( array_filter( array_map( 'strval', (array) ( $o['disabled_post_types'] ?? array() ) ) ) );

		if ( $everywhere ) {
			$m->set( self::MODULE, 'disable_comments', true, __( 'Disable comments', 'ux-studio' ), $origin . ':remove_everywhere' );
			$m->set( self::MODULE, 'disable_comments_scope', 'all', __( 'Where to disable comments', 'ux-studio' ), $origin . ':remove_everywhere' );
			$m->set( self::MODULE, 'disable_comments_admin_ui', true, __( 'Remove comment tools from the admin', 'ux-studio' ), $origin . ':remove_everywhere' );
			$m->set( self::MODULE, 'disable_comments_block_endpoints', true, __( 'Block comment endpoints', 'ux-studio' ), $origin . ':remove_everywhere' );
			if ( empty( $o['remove_xmlrpc_comments'] ) ) {
				$m->warn( __( 'The plugin leaves the XML-RPC comment methods alone, the module blocks them together with the REST comment routes.', 'ux-studio' ) );
			}
		} elseif ( $types ) {
			$m->set( self::MODULE, 'disable_comments', true, __( 'Disable comments', 'ux-studio' ), $origin . ':disabled_post_types' );
			$m->set( self::MODULE, 'disable_comments_scope', 'selected', __( 'Where to disable comments', 'ux-studio' ), $origin . ':disabled_post_types' );
			$m->set( self::MODULE, 'disable_comments_post_types', $types, __( 'Post types without comments', 'ux-studio' ), $origin . ':disabled_post_types' );
			if ( ! empty( $o['remove_rest_API_comments'] ) || ! empty( $o['remove_xmlrpc_comments'] ) ) {
				$m->lossy( __( 'Blocking the REST and XML-RPC comment endpoints works in the module only when comments are disabled everywhere.', 'ux-studio' ) );
			}
		} else {
			$m->note( __( 'The plugin currently disables comments nowhere, so the module setting is left as it is.', 'ux-studio' ) );
			return $m;
		}

		// "Show existing comments" in the plugin = "do not hide them" in the module.
		$m->set( self::MODULE, 'disable_comments_hide_existing', empty( $o['show_existing_comments'] ), __( 'Hide existing comments', 'ux-studio' ), $origin . ':show_existing_comments' );

		if ( ! empty( $o['enable_exclude_by_role'] ) && ! empty( $o['exclude_by_role'] ) ) {
			$m->lossy( __( 'Exceptions by user role (users who may still comment) are not available in the module.', 'ux-studio' ) );
		}
		if ( ! empty( $o['enable_conditional_rules'] ) && ( ! empty( $o['conditional_rules'] ) || (int) ( $o['auto_close_days'] ?? 0 ) > 0 ) ) {
			$m->lossy( __( 'Conditional rules and closing comments after a number of days are not available in the module.', 'ux-studio' ) );
		}
		if ( ! empty( $o['allowed_comment_types'] ) || ! empty( $o['blocked_comment_types'] ) ) {
			$m->lossy( __( 'Rules for single comment types (pingbacks, trackbacks, custom types) are not transferred.', 'ux-studio' ) );
		}
		if ( ! empty( $o['extra_post_types'] ) || ! empty( $o['disabled_sites'] ) ) {
			$m->lossy( __( 'Network (multisite) settings are not transferred.', 'ux-studio' ) );
		}
		return $m;
	}
}
