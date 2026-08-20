<?php
/**
 * Admin bar customisation: compact "Howdy" avatar, updates badge on the
 * right, plugin-added top-level items consolidated under a "+" dropdown,
 * and a compact external-link icon replacing the site name.
 *
 * Ported from the legacy admin-customiser module (includes/AdminBar.php).
 * The legacy version exposed a handful of on/off toggles for each of these
 * behaviours (hide_howdy_name, move_updates_right, consolidate_plugin_actions,
 * move_site_link_right) plus per-element color fields; the new settings
 * contract only has a single `admin_bar_enabled` toggle, so all behaviours
 * below are simply always-on once this class is instantiated.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

defined( 'ABSPATH' ) || exit;

final class AdminBar {

	/**
	 * Register admin bar hooks. Only instantiated when admin_bar_enabled=true.
	 */
	public function register(): void {
		add_filter( 'admin_bar_menu', array( $this, 'reorganize' ), PHP_INT_MAX - 100, 1 );
		add_action( 'wp_before_admin_bar_render', array( $this, 'reorganize_late' ), PHP_INT_MAX - 100 );

		add_action( 'wp_head', array( $this, 'print_styles' ), 100 );
		add_action( 'admin_head', array( $this, 'print_styles' ), 100 );

		add_action( 'wp_footer', array( $this, 'print_script' ), 100 );
		add_action( 'admin_footer', array( $this, 'print_script' ), 100 );
	}

	/**
	 * Wrapper for wp_before_admin_bar_render - the action does not pass the
	 * admin bar instance as an argument, so it is read from the global.
	 */
	public function reorganize_late(): void {
		if ( ! empty( $GLOBALS['wp_admin_bar'] ) && $GLOBALS['wp_admin_bar'] instanceof \WP_Admin_Bar ) {
			$this->reorganize( $GLOBALS['wp_admin_bar'] );
		}
	}

	/**
	 * Move "Updates" to the right, consolidate plugin-added top-level nodes
	 * under a "+" dropdown, and replace the site name with a compact
	 * external-link icon. Runs after plugins have added their own nodes.
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar instance.
	 */
	public function reorganize( $admin_bar ) {
		if ( ! is_admin_bar_showing() ) {
			return $admin_bar;
		}

		// 1) "Howdy, {Name}" is compacted to a bare avatar purely via CSS (print_styles()).

		// 2) Move "Updates" to the right (top-secondary), keeping WP's default icon.
		$updates = $admin_bar->get_node( 'updates' );
		if ( $updates ) {
			$admin_bar->remove_node( 'updates' );
			$admin_bar->add_node(
				array(
					'id'     => 'updates',
					'parent' => 'top-secondary',
					'href'   => $updates->href,
					'title'  => $updates->title,
					'meta'   => array_merge(
						is_array( $updates->meta ) ? $updates->meta : array(),
						array( 'class' => trim( ( $updates->meta['class'] ?? '' ) . ' uxstudio-ab-updates' ) )
					),
				)
			);
		}

		// 3) Consolidate plugin-added top-level nodes under a "+" icon on the right.
		$plus_id = 'uxstudio-plus-menu';

		$keep_ids = apply_filters(
			'uxstudio/admin_customiser/plus_keep_ids',
			array(
				'top-secondary', 'root-default', 'secondary',
				'my-account', 'user-actions', 'user-info', 'edit-profile', 'logout', 'my-sites', 'search',
				'site-name', 'updates', 'wp-logo',
				'uxstudio-admin-bar-search', 'uxstudio-plus-menu', 'uxstudio-site-link',
				'uxstudio-server-clock',
			)
		);

		$candidates = array();
		foreach ( $admin_bar->get_nodes() as $id => $node ) {
			if ( in_array( $id, $keep_ids, true ) ) {
				continue;
			}
			if ( strpos( $id, 'uxstudio-plus-' ) === 0 ) {
				continue;
			}
			$parent       = $node->parent ?? '';
			$is_top_level = ( '' === $parent || false === $parent || 'root' === $parent || 'top-secondary' === $parent );
			if ( ! $is_top_level ) {
				continue;
			}
			$candidates[ $id ] = $node;
		}

		$plus_icon = '<span class="ab-icon uxstudio-plus" aria-hidden="true"><svg viewBox="0 0 20 20" width="16" height="16" focusable="false"><path fill="currentColor" d="M9 3h2v6h6v2h-6v6H9v-6H3V9h6V3z"/></svg></span>';

		$admin_bar->add_node(
			array(
				'id'     => $plus_id,
				'parent' => 'top-secondary',
				'title'  => $plus_icon,
				'href'   => false,
				'meta'   => array(
					'class' => 'uxstudio-ab-plus',
					'title' => __( 'Quick actions', 'ux-studio' ),
				),
			)
		);

		$admin_bar->remove_node( 'uxstudio-plus-quick-heading' );
		foreach ( $this->quick_actions() as $action ) {
			$admin_bar->remove_node( 'uxstudio-plus-qa-' . sanitize_key( $action['id'] ) );
		}

		foreach ( $candidates as $id => $node ) {
			$meta            = is_array( $node->meta ) ? $node->meta : array();
			$meta['class']   = trim( ( $meta['class'] ?? '' ) . ' uxstudio-plus-plugin-item' );

			$admin_bar->add_node(
				array(
					'id'     => $node->id,
					'parent' => $plus_id,
					'title'  => $node->title,
					'href'   => $node->href,
					'meta'   => $meta,
				)
			);
		}

		$admin_bar->add_node(
			array(
				'id'     => 'uxstudio-plus-quick-heading',
				'parent' => $plus_id,
				'title'  => '<span class="uxstudio-plus-heading">' . esc_html__( 'Quick actions', 'ux-studio' ) . '</span>',
				'href'   => false,
				'meta'   => array( 'class' => 'uxstudio-plus-heading-item' ),
			)
		);

		foreach ( $this->quick_actions() as $action ) {
			$admin_bar->add_node(
				array(
					'id'     => 'uxstudio-plus-qa-' . sanitize_key( $action['id'] ),
					'parent' => $plus_id,
					'title'  => $action['title'],
					'href'   => $action['href'],
					'meta'   => array( 'class' => 'uxstudio-plus-qa-item' ),
				)
			);
		}

		// 4) Site name -> compact external-link icon on the right.
		$admin_bar->remove_node( 'site-name' );

		$link_icon = '<span class="ab-icon uxstudio-extlink" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg></span>';

		$admin_bar->add_node(
			array(
				'id'     => 'uxstudio-site-link',
				'parent' => 'top-secondary',
				'title'  => $link_icon,
				'href'   => home_url( '/' ),
				'meta'   => array(
					'target' => '_blank',
					'rel'    => 'noopener',
					'class'  => 'uxstudio-ab-sitelink',
					// translators: %s is the site title.
					'title'  => sprintf( __( 'Open %s in a new tab', 'ux-studio' ), get_bloginfo( 'name' ) ),
				),
			)
		);

		// 5) Force visual order of top-secondary items (search, bell, updates, plus, site-link, avatar).
		$this->force_top_secondary_order( $admin_bar );

		return $admin_bar;
	}

	/**
	 * Sort priority for a top-secondary node id. Lower = leftmost.
	 *
	 * @param string $id Bare node id.
	 */
	private function top_secondary_priority( string $id ): int {
		$exact = array(
			'uxstudio-admin-bar-search' => 10,
			'updates'                   => 30,
			'uxstudio-plus-menu'        => 40,
			'uxstudio-site-link'        => 50,
			'my-account'                => 60,
		);
		if ( isset( $exact[ $id ] ) ) {
			return $exact[ $id ];
		}

		if ( preg_match( '/notif|bell|inbox/i', $id ) ) {
			return 20;
		}

		return 35;
	}

	/**
	 * Remove and re-add every top-secondary child in deterministic order.
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar instance.
	 */
	private function force_top_secondary_order( $admin_bar ): void {
		$children = array();
		foreach ( $admin_bar->get_nodes() as $id => $node ) {
			$parent = $node->parent ?? '';
			if ( 'top-secondary' !== $parent ) {
				continue;
			}
			$children[ $id ] = $node;
		}

		if ( empty( $children ) ) {
			return;
		}

		$i         = 0;
		$sortable  = array();
		foreach ( $children as $id => $node ) {
			$sortable[] = array(
				'id'    => $id,
				'node'  => $node,
				'prio'  => $this->top_secondary_priority( $id ),
				'order' => $i++,
			);
		}
		usort(
			$sortable,
			static function ( $a, $b ) {
				return $a['prio'] === $b['prio'] ? $a['order'] <=> $b['order'] : $a['prio'] <=> $b['prio'];
			}
		);

		foreach ( $sortable as $item ) {
			$admin_bar->remove_node( $item['id'] );
		}
		foreach ( $sortable as $item ) {
			$n = $item['node'];
			$admin_bar->add_node(
				array(
					'id'     => $item['id'],
					'parent' => 'top-secondary',
					'title'  => $n->title ?? '',
					'href'   => $n->href ?? false,
					'group'  => $n->group ?? false,
					'meta'   => is_array( $n->meta ?? null ) ? $n->meta : array(),
				)
			);
		}
	}

	/**
	 * Quick-action links shown at the bottom of the "+" dropdown, filtered by
	 * the current user's capabilities.
	 */
	private function quick_actions(): array {
		$actions = array(
			array(
				'id'    => 'new-post',
				'title' => esc_html__( 'New post', 'ux-studio' ),
				'href'  => admin_url( 'post-new.php' ),
				'cap'   => 'edit_posts',
			),
			array(
				'id'    => 'new-page',
				'title' => esc_html__( 'New page', 'ux-studio' ),
				'href'  => admin_url( 'post-new.php?post_type=page' ),
				'cap'   => 'edit_pages',
			),
			array(
				'id'    => 'new-media',
				'title' => esc_html__( 'Upload media', 'ux-studio' ),
				'href'  => admin_url( 'media-new.php' ),
				'cap'   => 'upload_files',
			),
			array(
				'id'    => 'new-user',
				'title' => esc_html__( 'New user', 'ux-studio' ),
				'href'  => admin_url( 'user-new.php' ),
				'cap'   => 'create_users',
			),
			array(
				'id'    => 'plugins',
				'title' => esc_html__( 'Manage plugins', 'ux-studio' ),
				'href'  => admin_url( 'plugins.php' ),
				'cap'   => 'activate_plugins',
			),
			array(
				'id'    => 'customize',
				'title' => esc_html__( 'Customize theme', 'ux-studio' ),
				'href'  => admin_url( 'customize.php' ),
				'cap'   => 'customize',
			),
		);

		$filtered = array();
		foreach ( $actions as $action ) {
			if ( current_user_can( $action['cap'] ) ) {
				$filtered[] = $action;
			}
		}

		return apply_filters( 'uxstudio/admin_customiser/plus_quick_actions', $filtered );
	}

	/**
	 * Inline CSS for the reorganized admin bar (compact icons, "+" dropdown styling).
	 */
	public function print_styles(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$css  = '#wpadminbar #wp-admin-bar-uxstudio-plus-menu > .ab-item,';
		$css .= '#wpadminbar #wp-admin-bar-uxstudio-site-link > .ab-item {';
		$css .= 'display: inline-flex !important; align-items: center; justify-content: center;';
		$css .= 'width: 32px; height: 32px; padding: 0 !important; margin: 0; line-height: 1 !important;';
		$css .= '}';

		$css .= '#wpadminbar #wp-admin-bar-uxstudio-plus-menu .ab-icon.uxstudio-plus,';
		$css .= '#wpadminbar #wp-admin-bar-uxstudio-site-link .ab-icon.uxstudio-extlink {';
		$css .= 'display: inline-flex; align-items: center; justify-content: center;';
		$css .= 'width: 18px; height: 18px; margin: 0 !important; padding: 0 !important; line-height: 1;';
		$css .= '}';

		// Compact "Howdy" avatar - hide every child, paint a static SVG via background-image.
		$svg     = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='%23ffffff' d='M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-3.3 0-8 1.7-8 5v2h16v-2c0-3.3-4.7-5-8-5z'/></svg>";
		$svg_url = 'data:image/svg+xml;utf8,' . $svg;

		$css .= '#wpadminbar #wp-admin-bar-my-account > .ab-item {';
		$css .= 'display: inline-block !important; box-sizing: border-box !important;';
		$css .= 'width: 32px !important; height: 32px !important; padding: 0 !important; margin: 0 !important;';
		$css .= 'line-height: 32px !important; font-size: 0 !important; text-indent: 100% !important;';
		$css .= 'white-space: nowrap !important; overflow: hidden !important;';
		$css .= 'background-image: url("' . $svg_url . '") !important;';
		$css .= 'background-repeat: no-repeat !important; background-position: center center !important; background-size: 20px 20px !important;';
		$css .= '}';
		$css .= '#wpadminbar #wp-admin-bar-my-account > .ab-item > * { display: none !important; visibility: hidden !important; }';
		$css .= '#wpadminbar #wp-admin-bar-my-account.menupop > .ab-sub-wrapper { top: 32px !important; right: 0 !important; left: auto !important; margin-top: 0 !important; }';

		// Updates badge - compact, badge anchored top-right.
		$css .= '#wpadminbar #wp-admin-bar-updates { position: relative; overflow: visible; }';
		$css .= '#wpadminbar #wp-admin-bar-updates > .ab-item { display: inline-flex !important; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0 !important; line-height: 1 !important; position: relative; overflow: visible; }';
		$css .= '#wpadminbar #wp-admin-bar-updates .ab-label, #wpadminbar #wp-admin-bar-updates .update-count, #wpadminbar #wp-admin-bar-updates .update-plugins {';
		$css .= 'position: absolute !important; top: 3px !important; right: 2px !important; left: auto !important; bottom: auto !important;';
		$css .= 'min-width: 14px !important; width: auto !important; height: 14px !important; line-height: 14px !important; padding: 0 3px !important;';
		$css .= 'font-size: 9px !important; font-weight: 700 !important; border-radius: 9px !important; background: #d63638 !important; color: #fff !important;';
		$css .= 'text-align: center !important; box-sizing: border-box !important; display: inline-block !important; margin: 0 !important;';
		$css .= '}';

		// "+" dropdown panel.
		$css .= '#wpadminbar #wp-admin-bar-uxstudio-plus-menu.menupop > .ab-sub-wrapper {';
		$css .= 'top: 32px !important; right: 0 !important; left: auto !important; margin-top: 0 !important; z-index: 99999 !important; min-width: 240px; padding: 4px 0 !important;';
		$css .= '}';

		$row = '#wpadminbar #wp-admin-bar-uxstudio-plus-menu-default > li.uxstudio-plus-plugin-item';
		$css .= $row . ' > .ab-item { display: grid !important; grid-template-columns: 14px 1fr auto !important; align-items: center !important; column-gap: 10px !important; min-height: 26px !important; height: auto !important; padding: 3px 14px !important; margin: 0 !important; color: #fff !important; text-decoration: none !important; background: transparent !important; }';
		$css .= $row . ':hover > .ab-item, ' . $row . ' > .ab-item:focus { background: rgba(255,255,255,0.06) !important; }';

		$qa_row = '#wpadminbar #wp-admin-bar-uxstudio-plus-menu-default > li.uxstudio-plus-qa-item';
		$css .= $qa_row . ' > .ab-item { display: grid !important; grid-template-columns: 14px 1fr !important; align-items: center !important; column-gap: 10px !important; min-height: 26px !important; height: auto !important; padding: 3px 14px !important; margin: 0 !important; color: #fff !important; text-decoration: none !important; background: transparent !important; }';
		$css .= $qa_row . ':hover > .ab-item, ' . $qa_row . ' > .ab-item:focus { background: rgba(255,255,255,0.06) !important; }';

		$css .= '#wpadminbar #wp-admin-bar-uxstudio-plus-quick-heading { border-top: 1px solid rgba(255,255,255,0.12) !important; margin-top: 4px !important; padding-top: 4px !important; pointer-events: none; }';
		$css .= '#wpadminbar #wp-admin-bar-uxstudio-plus-quick-heading .uxstudio-plus-heading { font-size: 10px !important; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.55; font-weight: 600; }';

		// Server clock row inside the "+" dropdown (see ServerClock::PLUS_MENU position).
		$clock_li = '#wpadminbar #wp-admin-bar-uxstudio-plus-menu-default > li.uxstudio-plus-clock-item';
		$css     .= $clock_li . ' { border-top: 1px solid rgba(255,255,255,0.12) !important; margin-top: 4px !important; padding-top: 4px !important; }';
		$css     .= $clock_li . ' > .ab-item { display: block !important; padding: 8px 14px !important; height: auto !important; cursor: default !important; background: transparent !important; }';
		$css     .= $clock_li . ' .uxstudio-plus-clock__time { font-size: 16px !important; font-weight: 600 !important; color: #fff !important; }';
		$css     .= $clock_li . ' .uxstudio-plus-clock__label, ' . $clock_li . ' .uxstudio-plus-clock__date, ' . $clock_li . ' .uxstudio-plus-clock__tz { font-size: 10px !important; color: rgba(255,255,255,0.55) !important; }';

		// Top-secondary alignment.
		$css .= '#wpadminbar #wp-admin-bar-top-secondary { display: flex !important; flex-wrap: nowrap; align-items: center; justify-content: flex-end; }';
		$css .= '#wpadminbar #wp-admin-bar-top-secondary > li { float: none !important; display: inline-flex; align-items: center; height: 32px; }';
		$css .= '#wpadminbar #wp-admin-bar-top-secondary > li > .ab-item { height: 32px !important; line-height: 32px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; }';

		printf( '<style id="uxstudio-admin-bar-reorganize">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * JS post-processor: moves any leftover top-level admin bar items (added
	 * via JS or very late PHP hooks) into the "+" dropdown, and normalizes
	 * their label/icon so the compact dropdown row layout stays consistent.
	 */
	public function print_script(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$keep_ids  = array(
			'wp-admin-bar-top-secondary', 'wp-admin-bar-root-default', 'wp-admin-bar-secondary',
			'wp-admin-bar-my-account', 'wp-admin-bar-user-actions', 'wp-admin-bar-user-info',
			'wp-admin-bar-edit-profile', 'wp-admin-bar-logout', 'wp-admin-bar-my-sites', 'wp-admin-bar-search',
			'wp-admin-bar-site-name', 'wp-admin-bar-updates', 'wp-admin-bar-wp-logo',
			'wp-admin-bar-uxstudio-admin-bar-search', 'wp-admin-bar-uxstudio-plus-menu', 'wp-admin-bar-uxstudio-site-link',
			'wp-admin-bar-uxstudio-server-clock', 'wp-admin-bar-menu-toggle',
		);
		$keep_ids  = apply_filters( 'uxstudio/admin_customiser/plus_keep_dom_ids', $keep_ids );
		$keep_json = wp_json_encode( array_values( $keep_ids ) );

		?>
<script id="uxstudio-admin-bar-reorganize-js">
(function () {
	var KEEP = <?php echo $keep_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	var KEEP_PREFIXES = ['wp-admin-bar-uxstudio-plus-'];

	function shouldKeep(id) {
		if (!id) return true;
		if (KEEP.indexOf(id) !== -1) return true;
		for (var i = 0; i < KEEP_PREFIXES.length; i++) {
			if (id.indexOf(KEEP_PREFIXES[i]) === 0) return true;
		}
		return false;
	}

	function getTarget() {
		var plus = document.getElementById('wp-admin-bar-uxstudio-plus-menu');
		if (!plus) return null;
		var wrap = plus.querySelector(':scope > .ab-sub-wrapper');
		if (!wrap) return null;
		return wrap.querySelector(':scope > #wp-admin-bar-uxstudio-plus-menu-default, :scope > .ab-submenu');
	}

	function humanize(id) {
		if (!id) return '';
		var bare = id.replace(/^wp-admin-bar-/, '').replace(/-(menu|node|admin-bar|admin-node|root)$/i, '');
		var s = bare.replace(/[-_]+/g, ' ').trim();
		if (!s) return '';
		return s.charAt(0).toUpperCase() + s.slice(1);
	}

	function extractText(link, li) {
		var labels = link.querySelectorAll('.ab-label');
		for (var i = 0; i < labels.length; i++) {
			var t = (labels[i].textContent || '').replace(/\s+/g, ' ').trim();
			if (t && !/^\d+$/.test(t)) return t;
		}
		var clone = link.cloneNode(true);
		var sub = clone.querySelector('.ab-sub-wrapper');
		if (sub) sub.parentNode.removeChild(sub);
		var rawText = (clone.textContent || '').replace(/\s+/g, ' ').trim().replace(/^\d+\s+/, '').replace(/\s+\d+$/, '').trim();
		if (rawText && !/^\d+$/.test(rawText)) return rawText;
		var attr = link.getAttribute('title') || link.getAttribute('aria-label') || li.getAttribute('title') || '';
		attr = attr.replace(/\s+/g, ' ').trim();
		if (attr && !/^\d+$/.test(attr)) return attr;
		return humanize(li.id) || '';
	}

	function extractBadge(link) {
		var labels = link.querySelectorAll('.ab-label');
		for (var i = 0; i < labels.length; i++) {
			var t = (labels[i].textContent || '').replace(/\s+/g, ' ').trim();
			if (/^\d+$/.test(t)) return t;
		}
		return '';
	}

	function normalizeItem(li) {
		var link = li.querySelector(':scope > .ab-item');
		if (!link) return;

		var text = extractText(link, li);
		var badge = extractBadge(link);
		var href = link.getAttribute('href') || '';

		if (!text || !text.replace(/\s+/g, '').length) {
			li.classList.add('uxstudio-plus-empty');
			return;
		}
		li.classList.remove('uxstudio-plus-empty');

		while (link.firstChild) link.removeChild(link.firstChild);

		var arrow = document.createElement('span');
		arrow.className = 'uxstudio-plus-arrow';
		arrow.setAttribute('aria-hidden', 'true');
		arrow.textContent = '‹';
		link.appendChild(arrow);

		var label = document.createElement('span');
		label.className = 'uxstudio-plus-text';
		label.textContent = text || ' ';
		link.appendChild(label);

		if (badge) {
			var pill = document.createElement('span');
			pill.className = 'uxstudio-plus-badge';
			pill.textContent = badge;
			link.appendChild(pill);
		}

		if (href) link.setAttribute('href', href);
	}

	function moveItem(li, target) {
		var heading = target.querySelector('#wp-admin-bar-uxstudio-plus-quick-heading');
		li.classList.add('uxstudio-plus-plugin-item');
		normalizeItem(li);
		li.dataset.uxstudioNormalized = '1';
		if (heading) {
			target.insertBefore(li, heading);
		} else {
			target.appendChild(li);
		}
	}

	function consolidate() {
		var bar = document.getElementById('wpadminbar');
		if (!bar) return;
		var target = getTarget();
		if (!target) return;

		var toolbar = document.getElementById('wp-toolbar');
		var lists = [];
		if (toolbar) {
			var uls = toolbar.querySelectorAll(':scope > ul');
			for (var i = 0; i < uls.length; i++) lists.push(uls[i]);
		}
		var rd = document.getElementById('wp-admin-bar-root-default');
		var ts = document.getElementById('wp-admin-bar-top-secondary');
		if (rd && lists.indexOf(rd) === -1) lists.push(rd);
		if (ts && lists.indexOf(ts) === -1) lists.push(ts);

		lists.forEach(function (cont) {
			if (!cont) return;
			var items = Array.prototype.slice.call(cont.children);
			items.forEach(function (li) {
				if (li.nodeType !== 1 || li.tagName !== 'LI') return;
				if (shouldKeep(li.id)) return;
				if (target.contains(li)) return;
				moveItem(li, target);
			});
		});

		var existing = target.querySelectorAll(':scope > li.uxstudio-plus-plugin-item');
		for (var k = 0; k < existing.length; k++) {
			var ex = existing[k];
			if (ex.dataset.uxstudioNormalized) continue;
			normalizeItem(ex);
			ex.dataset.uxstudioNormalized = '1';
		}
	}

	function topSecondaryPriority(id) {
		if (!id) return 100;
		var exact = {
			'wp-admin-bar-uxstudio-admin-bar-search': 10,
			'wp-admin-bar-updates': 30,
			'wp-admin-bar-uxstudio-plus-menu': 40,
			'wp-admin-bar-uxstudio-site-link': 50,
			'wp-admin-bar-my-account': 60
		};
		if (exact.hasOwnProperty(id)) return exact[id];
		if (/notif|bell|inbox/i.test(id)) return 20;
		return 35;
	}

	function reorderTopSecondary() {
		var ts = document.getElementById('wp-admin-bar-top-secondary');
		if (!ts) return;
		var items = [];
		for (var c = ts.firstElementChild; c; c = c.nextElementSibling) {
			if (c.tagName === 'LI') items.push(c);
		}
		if (items.length < 2) return;

		var withPrio = items.map(function (li, idx) {
			return { li: li, prio: topSecondaryPriority(li.id), idx: idx };
		});
		withPrio.sort(function (a, b) {
			return a.prio === b.prio ? a.idx - b.idx : a.prio - b.prio;
		});

		var alreadySorted = true;
		for (var k = 0; k < withPrio.length; k++) {
			if (items[k] !== withPrio[k].li) { alreadySorted = false; break; }
		}
		if (alreadySorted) return;

		var frag = document.createDocumentFragment();
		withPrio.forEach(function (e) { frag.appendChild(e.li); });
		ts.appendChild(frag);
	}

	function runAll() {
		consolidate();
		reorderTopSecondary();
	}

	function init() {
		runAll();
		setTimeout(runAll, 200);
		setTimeout(runAll, 800);
		setTimeout(runAll, 2000);

		var bar = document.getElementById('wpadminbar');
		if (bar && 'MutationObserver' in window) {
			var scheduled = false;
			var mo = new MutationObserver(function (mutations) {
				var dirty = false;
				for (var i = 0; i < mutations.length; i++) {
					if (mutations[i].addedNodes && mutations[i].addedNodes.length) { dirty = true; break; }
				}
				if (dirty && !scheduled) {
					scheduled = true;
					requestAnimationFrame(function () { scheduled = false; runAll(); });
				}
			});
			mo.observe(bar, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
</script>
		<?php
	}
}
