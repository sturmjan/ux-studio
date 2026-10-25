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
		// Visitors who are not logged in may still comment: the anonymous check would find the form on purpose.
		if ( in_array( 'logged-out-users', (array) ( $settings['disable_comments_allowed_roles'] ?? array() ), true ) ) {
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
		$disabling  = false;

		if ( $everywhere ) {
			$disabling = true;
			$m->set( self::MODULE, 'disable_comments', true, __( 'Disable comments', 'ux-studio' ), $origin . ':remove_everywhere' );
			$m->set( self::MODULE, 'disable_comments_scope', 'all', __( 'Where to disable comments', 'ux-studio' ), $origin . ':remove_everywhere' );
			$m->set( self::MODULE, 'disable_comments_admin_ui', true, __( 'Remove comment tools from the admin', 'ux-studio' ), $origin . ':remove_everywhere' );
			$m->set( self::MODULE, 'disable_comments_block_endpoints', true, __( 'Block comment endpoints', 'ux-studio' ), $origin . ':remove_everywhere' );
			if ( empty( $o['remove_xmlrpc_comments'] ) ) {
				$m->warn( __( 'The plugin leaves the XML-RPC comment methods alone, the module blocks them together with the REST comment routes.', 'ux-studio' ) );
			}
		} elseif ( $types ) {
			$disabling = true;
			$m->set( self::MODULE, 'disable_comments', true, __( 'Disable comments', 'ux-studio' ), $origin . ':disabled_post_types' );
			$m->set( self::MODULE, 'disable_comments_scope', 'selected', __( 'Where to disable comments', 'ux-studio' ), $origin . ':disabled_post_types' );
			$m->set( self::MODULE, 'disable_comments_post_types', $types, __( 'Post types without comments', 'ux-studio' ), $origin . ':disabled_post_types' );
			if ( ! empty( $o['remove_rest_API_comments'] ) || ! empty( $o['remove_xmlrpc_comments'] ) ) {
				$m->lossy( __( 'Blocking the REST and XML-RPC comment endpoints works in the module only when comments are disabled everywhere.', 'ux-studio' ) );
			}
		}

		// Rules that work without disabling comments too: closing old posts and refusing comment types.
		$days    = ! empty( $o['enable_conditional_rules'] ) ? max( 0, (int) ( $o['auto_close_days'] ?? 0 ) ) : 0;
		$allowed = array_values( array_filter( array_map( 'strval', (array) ( $o['allowed_comment_types'] ?? array() ) ) ) );
		$blocked = array_values( array_filter( array_map( 'strval', (array) ( $o['blocked_comment_types'] ?? array() ) ) ) );
		$refuse  = array_values( array_intersect( $blocked, array( 'pingback', 'trackback', 'review' ) ) );

		if ( ! $disabling && 0 === $days && ! $refuse ) {
			$m->note( __( 'The plugin currently disables comments nowhere, so the module setting is left as it is.', 'ux-studio' ) );
			return $m;
		}

		if ( $disabling ) {
			// "Show existing comments" in the plugin = "do not hide them" in the module.
			$m->set( self::MODULE, 'disable_comments_hide_existing', empty( $o['show_existing_comments'] ), __( 'Hide existing comments', 'ux-studio' ), $origin . ':show_existing_comments' );
		}

		// Roles that are not affected (they may still comment, and keep the comment tools).
		if ( ! empty( $o['enable_exclude_by_role'] ) && ! empty( $o['exclude_by_role'] ) && ( $disabling || $days > 0 ) ) {
			$wanted = array_values( array_unique( array_filter( array_map( 'strval', (array) $o['exclude_by_role'] ) ) ) );
			$known  = $this->role_ids();
			$keep   = $known ? array_values( array_intersect( $wanted, array_merge( $known, array( 'logged-out-users' ) ) ) ) : $wanted;
			$gone   = array_values( array_diff( $wanted, $keep ) );
			if ( $keep ) {
				$m->set( self::MODULE, 'disable_comments_allowed_roles', $keep, __( 'Roles that may still comment', 'ux-studio' ), $origin . ':exclude_by_role' );
			}
			if ( $gone ) {
				$m->lossy(
					sprintf(
						/* translators: %s: role names. */
						__( 'These roles of the exceptions no longer exist on the site and are not transferred: %s.', 'ux-studio' ),
						implode( ', ', $gone )
					)
				);
			}
		}

		// Comment types: pingbacks and trackbacks can stay allowed while comments are off, pingbacks, trackbacks and reviews can be refused.
		$allow_ok = $disabling ? array_values( array_intersect( $allowed, array( 'pingback', 'trackback' ) ) ) : array();
		if ( $allow_ok ) {
			$m->set( self::MODULE, 'disable_comments_allowed_types', $allow_ok, __( 'Comment types that stay allowed', 'ux-studio' ), $origin . ':allowed_comment_types' );
		}
		if ( $refuse ) {
			$m->set( self::MODULE, 'comments_blocked_types', $refuse, __( 'Comment types to refuse', 'ux-studio' ), $origin . ':blocked_comment_types' );
		}
		$other = array_values( array_unique( array_merge( $disabling ? array_diff( $allowed, array( 'pingback', 'trackback' ) ) : array(), array_diff( $blocked, array( 'pingback', 'trackback', 'review' ) ) ) ) );
		if ( $other ) {
			$m->lossy(
				sprintf(
					/* translators: %s: comment type names. */
					__( 'The module can allow or refuse only pingbacks, trackbacks and (refuse) product reviews. Rules for these comment types are not transferred: %s.', 'ux-studio' ),
					implode( ', ', $other )
				)
			);
		}

		// Closing comments on old posts.
		if ( $days > 0 ) {
			$m->set( self::MODULE, 'comments_close_after_days', $days, __( 'Close comments on posts older than (days)', 'ux-studio' ), $origin . ':auto_close_days' );
			if ( empty( $o['show_existing_comments'] ) ) {
				$m->lossy( __( 'The plugin also hid the existing comments of old posts that it closed. The module only closes them, the existing comments stay visible.', 'ux-studio' ) );
			}
		}
		if ( ! empty( $o['enable_conditional_rules'] ) && ! empty( $o['conditional_rules'] ) ) {
			$m->lossy( __( 'Conditional rules by taxonomy term or page template are not available in the module.', 'ux-studio' ) );
		}
		if ( ! empty( $o['extra_post_types'] ) || ! empty( $o['disabled_sites'] ) ) {
			$m->lossy( __( 'Network (multisite) settings are not transferred.', 'ux-studio' ) );
		}
		return $m;
	}
}
