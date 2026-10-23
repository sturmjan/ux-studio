<?php
/**
 * Dashboard widgets hidden per role, and a custom welcome panel.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

use UxStudio\Core\RoleRules;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings (all in the module's own settings row, so the generic form edits them and the migration stages them):
 *  - `hidden_widgets_<role>`: multi-select per role, widgets hidden for that role (the global `hidden_widgets` stays and applies
 *    to everybody on top of this);
 *  - `welcome_panel_enabled`, `welcome_panel_title`, `welcome_panel_content` (rich text, `wp_kses_post`), `welcome_panel_roles`
 *    (roles that see it, none = everybody).
 *
 * The welcome panel is a normal dashboard meta box (top of the first column), not WordPress' own "Welcome" panel: that one
 * is shown only to users who can edit theme options and each user can dismiss it for good.
 */
final class RoleWidgets {

	public const KEY_PREFIX = 'hidden_widgets_';

	public const WELCOME_ID = 'uxstudio_welcome_panel';

	/**
	 * Settings key of a role.
	 *
	 * @param string $role Role id.
	 */
	public static function key( string $role ): string {
		return self::KEY_PREFIX . $role;
	}

	/**
	 * Schema fields: one multi-select per role, the welcome panel.
	 *
	 * @param array<string, string> $widgets Widget id => title (the options).
	 * @param array<string, string> $roles   Role id => name.
	 * @return array<int, array<string, mixed>>
	 */
	public static function schema( array $widgets, array $roles ): array {
		$fields = array();
		foreach ( $roles as $role => $name ) {
			$fields[] = array(
				'key'     => self::key( (string) $role ),
				'section' => __( 'Hidden widgets', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => sprintf(
					/* translators: %s: role name. */
					__( 'Hide dashboard widgets for the role: %s', 'ux-studio' ),
					$name
				),
				'help'    => __( 'On top of the widgets hidden for everybody above. A user with several roles has a widget hidden only when every role hides it.', 'ux-studio' ),
				'options' => $widgets,
				'default' => array(),
			);
		}
		$fields[] = array(
			'key'     => 'welcome_panel_enabled',
			'section' => __( 'Welcome panel', 'ux-studio' ),
			'type'    => 'toggle',
			'label'   => __( 'Show a custom welcome panel on the dashboard', 'ux-studio' ),
			'help'    => __( 'A box at the top of the dashboard with your own text, for example instructions for editors.', 'ux-studio' ),
			'default' => false,
		);
		$fields[] = array(
			'key'     => 'welcome_panel_title',
			'section' => __( 'Welcome panel', 'ux-studio' ),
			'type'    => 'text',
			'label'   => __( 'Welcome panel title', 'ux-studio' ),
			'default' => '',
		);
		$fields[] = array(
			'key'     => 'welcome_panel_content',
			'section' => __( 'Welcome panel', 'ux-studio' ),
			'type'    => 'richtext',
			'label'   => __( 'Welcome panel content', 'ux-studio' ),
			'help'    => __( 'Text with basic HTML (links, lists, images, headings). Scripts and other unsafe markup are removed.', 'ux-studio' ),
			'default' => '',
		);
		$fields[] = array(
			'key'     => 'welcome_panel_roles',
			'section' => __( 'Welcome panel', 'ux-studio' ),
			'type'    => 'multiselect',
			'label'   => __( 'Show the welcome panel to these roles', 'ux-studio' ),
			'help'    => __( 'Leave empty to show it to everybody who sees the dashboard.', 'ux-studio' ),
			'options' => $roles,
			'default' => array(),
		);
		return $fields;
	}

	/**
	 * Role => hidden widget ids from the module settings.
	 *
	 * @param Settings              $settings Module settings.
	 * @param array<string, string> $roles    Role id => name.
	 * @return array<string, string[]>
	 */
	public static function rules( Settings $settings, array $roles ): array {
		$out = array();
		foreach ( array_keys( $roles ) as $role ) {
			$list = $settings->get( self::key( (string) $role ), array() );
			if ( is_array( $list ) && $list ) {
				$out[ (string) $role ] = array_map( 'strval', $list );
			}
		}
		return $out;
	}

	/**
	 * Widget ids hidden for a user by the per-role lists.
	 *
	 * @param array<string, string[]> $by_role Role => ids.
	 * @param string[]                $roles   Roles of the user.
	 * @return string[]
	 */
	public static function hidden_for( array $by_role, array $roles ): array {
		return RoleRules::hidden_for( $by_role, $roles );
	}

	/**
	 * Is there anything to show: text, or at least an image / embedded media (works without WordPress loaded).
	 *
	 * @param string $html HTML of the panel.
	 */
	private static function has_content( string $html ): bool {
		return '' !== trim( html_entity_decode( strip_tags( $html ), ENT_QUOTES ) ) || (bool) preg_match( '/<(img|iframe|video|audio)\b/i', $html );
	}

	/**
	 * Is the welcome panel shown to a user: switched on, has content, and the user has one of its roles (none = everybody).
	 *
	 * @param array<string, mixed> $values Settings values (welcome_panel_*).
	 * @param string[]             $roles  Roles of the user.
	 */
	public static function welcome_visible( array $values, array $roles ): bool {
		return ! empty( $values['welcome_panel_enabled'] )
			&& self::has_content( (string) ( $values['welcome_panel_content'] ?? '' ) )
			&& RoleRules::applies_to( (array) ( $values['welcome_panel_roles'] ?? array() ), $roles );
	}

	/**
	 * Add the welcome panel to the dashboard for users it is meant for. Hooked on `wp_dashboard_setup` after the hiding.
	 *
	 * @param Settings $settings Module settings.
	 */
	public static function register_welcome( Settings $settings ): void {
		$user   = wp_get_current_user();
		$values = array(
			'welcome_panel_enabled' => $settings->get( 'welcome_panel_enabled', false ),
			'welcome_panel_content' => $settings->get( 'welcome_panel_content', '' ),
			'welcome_panel_roles'   => $settings->get( 'welcome_panel_roles', array() ),
		);
		if ( ! self::welcome_visible( $values, array_map( 'strval', (array) $user->roles ) ) ) {
			return;
		}
		$title = trim( (string) $settings->get( 'welcome_panel_title', '' ) );
		add_meta_box(
			self::WELCOME_ID,
			'' !== $title ? esc_html( $title ) : esc_html__( 'Welcome', 'ux-studio' ),
			array( self::class, 'render_welcome' ),
			'dashboard',
			'normal',
			'high'
		);
	}

	/**
	 * Output of the welcome panel (stored value, passed through `wp_kses_post` again on the way out).
	 */
	public static function render_welcome(): void {
		$content = (string) ( new Settings( 'uxstudio_dashboard_widgets' ) )->get( 'welcome_panel_content', '' );
		echo '<div class="uxstudio-welcome-panel">' . wp_kses_post( wpautop( $content ) ) . '</div>';
	}
}
