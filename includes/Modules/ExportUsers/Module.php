<?php
/**
 * Export Users module - export user data to CSV / JSON / TXT.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ExportUsers;

use UxStudio\Modules\BaseModule;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Download as ..." row actions, "Export to ..." bulk actions and profile
 * export buttons to the Users screens and streams the export through an
 * admin-post handler with a nonce. The SPA can also request a download URL
 * through the module REST endpoint. Ported from the legacy module (free + pro
 * merged), including the meta-field export from the pro add-on.
 */
final class Module extends BaseModule {

	/**
	 * admin-post action + nonce action.
	 */
	private const ACTION = 'uxstudio_export_users';

	/**
	 * Valid export types.
	 */
	private const VALID_TYPES = array( 'csv', 'json', 'txt' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_download' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		add_filter( 'user_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-users', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk_export' ), 10, 3 );
		add_action( 'personal_options', array( $this, 'render_profile_buttons' ) );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Enabled export types from settings, restricted to valid ones.
	 *
	 * @return string[]
	 */
	private function enabled_types(): array {
		$types = (array) $this->settings->get( 'export_types', array( 'csv' ) );
		return array_values( array_intersect( $types, self::VALID_TYPES ) );
	}

	/**
	 * Whether a type is a valid export type.
	 *
	 * @param string $type Export type.
	 */
	public function is_valid_type( string $type ): bool {
		return in_array( $type, self::VALID_TYPES, true );
	}

	/**
	 * Whether the current user may export the given user's data.
	 *
	 * @param int $user_id Target user ID.
	 */
	public function can_export_user( int $user_id ): bool {
		if ( get_current_user_id() === $user_id ) {
			return true;
		}
		return current_user_can( 'list_users' );
	}

	/**
	 * Build a signed admin-post download URL.
	 *
	 * @param string $type Export type.
	 * @param int[]  $ids  User IDs.
	 */
	public function build_download_url( string $type, array $ids ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::ACTION,
					'export_type' => $type,
					'ids'         => implode( ',', array_map( 'absint', $ids ) ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	/**
	 * Add "Download as ..." row actions.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_User $user    Current user.
	 * @return array
	 */
	public function add_row_actions( array $actions, WP_User $user ): array {
		if ( ! $this->can_export_user( $user->ID ) ) {
			return $actions;
		}

		foreach ( $this->enabled_types() as $type ) {
			$actions[ self::ACTION . '_' . $type ] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->build_download_url( $type, array( $user->ID ) ) ),
				esc_html( sprintf( /* translators: %s: export format */ __( 'Download as %s', 'ux-studio' ), strtoupper( $type ) ) )
			);
		}

		return $actions;
	}

	/**
	 * Add "Export to ..." bulk actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_actions( array $actions ): array {
		foreach ( $this->enabled_types() as $type ) {
			$actions[ self::ACTION . '_' . $type ] = sprintf(
				/* translators: %s: export format */
				__( 'Export to %s', 'ux-studio' ),
				strtoupper( $type )
			);
		}

		return $actions;
	}

	/**
	 * Render export buttons in the profile "Personal Options" area.
	 *
	 * @param WP_User $user Profile user.
	 */
	public function render_profile_buttons( WP_User $user ): void {
		if ( ! $this->can_export_user( $user->ID ) ) {
			return;
		}

		$types = $this->enabled_types();
		if ( array() === $types ) {
			return;
		}
		?>
		<tr class="user-export-wrap">
			<th scope="row"><?php esc_html_e( 'Export User Data', 'ux-studio' ); ?></th>
			<td>
				<?php foreach ( $types as $type ) : ?>
					<a href="<?php echo esc_url( $this->build_download_url( $type, array( $user->ID ) ) ); ?>" class="button button-secondary" style="margin-right:8px">
						<?php echo esc_html( sprintf( /* translators: %s: export format */ __( 'Export as %s', 'ux-studio' ), strtoupper( $type ) ) ); ?>
					</a>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Export user data in different formats.', 'ux-studio' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Handle a bulk export by redirecting to the signed download URL.
	 *
	 * @param string $sendback Redirect URL.
	 * @param string $doaction Selected bulk action.
	 * @param array  $ids      Selected user IDs.
	 * @return string
	 */
	public function handle_bulk_export( string $sendback, string $doaction, array $ids ): string {
		if ( ! preg_match( '/^' . self::ACTION . '_(csv|json|txt)$/', $doaction, $matches ) ) {
			return $sendback;
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return $sendback;
		}

		return $this->build_download_url( $matches[1], $ids );
	}

	/**
	 * Stream the export (admin-post.php handler).
	 */
	public function handle_download(): void {
		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), self::ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}

		$type = sanitize_key( $_GET['export_type'] ?? '' );
		if ( ! $this->is_valid_type( $type ) ) {
			wp_die( esc_html__( 'Invalid export type.', 'ux-studio' ), '', array( 'response' => 400 ) );
		}

		$raw_ids = sanitize_text_field( wp_unslash( $_GET['ids'] ?? '' ) );
		$ids     = array_values( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) );

		// Per-user capability check.
		$ids = array_values(
			array_filter(
				$ids,
				fn ( int $id ): bool => $this->can_export_user( $id )
			)
		);

		if ( array() === $ids ) {
			wp_die( esc_html__( 'No valid user IDs provided.', 'ux-studio' ), '', array( 'response' => 400 ) );
		}

		$prepared = $this->prepare_export_data( $ids, $type );
		if ( is_wp_error( $prepared ) ) {
			wp_die( esc_html( $prepared->get_error_message() ), '', array( 'response' => 500 ) );
		}

		( new Exporter() )->stream( $prepared['data'], $type, $prepared['filename'], $prepared['keys'] );
	}

	/**
	 * Build the export dataset for the given users.
	 *
	 * @param int[]  $user_ids User IDs.
	 * @param string $type     Export type.
	 * @return array{data:array,filename:string,keys:array}|WP_Error
	 */
	public function prepare_export_data( array $user_ids, string $type ) {
		$default_fields = (array) $this->settings->get( 'default_fields', $this->default_field_defaults() );
		$meta_fields    = (array) $this->settings->get( 'meta_fields', array() );

		if ( array() === $default_fields && array() === $meta_fields ) {
			return new WP_Error( 'no_fields', __( 'No fields selected for export.', 'ux-studio' ) );
		}

		$labels = $this->get_default_fields();
		$fields = array();

		foreach ( $default_fields as $field ) {
			$fields[ $field ] = $labels[ $field ] ?? $field;
		}
		foreach ( $meta_fields as $field ) {
			$fields[ $field ] = $field;
		}

		$headers = array_values( $fields );
		$keys    = array_keys( $fields );
		$body    = array();

		foreach ( $user_ids as $user_id ) {
			$user = get_user_by( 'ID', $user_id );
			if ( ! $user ) {
				continue;
			}

			$row = array();
			foreach ( $fields as $field => $label ) {
				$row[] = $this->get_field_value( $user, (string) $field );
			}
			$body[] = $row;
		}

		$filename = sprintf(
			'%s-users-export-%s.%s',
			sanitize_file_name( get_bloginfo( 'name' ) ),
			gmdate( 'Y-m-d' ),
			$type
		);

		return array(
			'data'     => array(
				'headers' => $headers,
				'body'    => $body,
			),
			'filename' => $filename,
			'keys'     => $keys,
		);
	}

	/**
	 * Resolve a single field value for a user.
	 *
	 * @param WP_User $user  User object.
	 * @param string  $field Field key (column, standard meta, or custom meta).
	 * @return string
	 */
	private function get_field_value( WP_User $user, string $field ): string {
		switch ( $field ) {
			case 'roles':
				return implode( ', ', (array) $user->roles );

			case 'capabilities':
				return implode( ', ', array_keys( (array) $user->allcaps ) );

			case 'avatar':
				return (string) get_avatar_url( $user->ID );
		}

		// WP_User::__get transparently resolves both columns and user meta.
		$value = $user->$field ?? '';
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * Default field defaults (pre-selected keys).
	 *
	 * @return string[]
	 */
	private function default_field_defaults(): array {
		return array(
			'ID',
			'user_login',
			'user_nicename',
			'user_email',
			'user_url',
			'user_registered',
			'display_name',
		);
	}

	/**
	 * Selectable default fields: key => label.
	 *
	 * @return array<string, string>
	 */
	private function get_default_fields(): array {
		return array(
			'ID'              => __( 'User ID', 'ux-studio' ),
			'user_login'      => __( 'Username', 'ux-studio' ),
			'user_nicename'   => __( 'Nicename', 'ux-studio' ),
			'first_name'      => __( 'First Name', 'ux-studio' ),
			'last_name'       => __( 'Last Name', 'ux-studio' ),
			'nickname'        => __( 'Nickname', 'ux-studio' ),
			'user_email'      => __( 'Email Address', 'ux-studio' ),
			'user_url'        => __( 'Website URL', 'ux-studio' ),
			'description'     => __( 'Description', 'ux-studio' ),
			'avatar'          => __( 'Avatar', 'ux-studio' ),
			'user_registered' => __( 'Registration Date', 'ux-studio' ),
			'display_name'    => __( 'Display Name', 'ux-studio' ),
			'roles'           => __( 'Roles', 'ux-studio' ),
			'capabilities'    => __( 'Capabilities', 'ux-studio' ),
		);
	}

	/**
	 * Distinct user meta keys for the meta-field selector (excluding internal keys).
	 *
	 * @return array<string, string>
	 */
	private function get_meta_fields(): array {
		global $wpdb;

		$excluded = array(
			'session_tokens',
			'wp_capabilities',
			'wp_user_level',
			'wp_user-settings',
			'wp_user-settings-time',
			'use_ssl',
			'admin_color',
			'closedpostboxes_dashboard',
			'closedpostboxes_page',
			'closedpostboxes_post',
			'meta-box-order_dashboard',
			'meta-box-order_page',
			'meta-box-order_post',
			'metaboxhidden_dashboard',
			'metaboxhidden_nav-menus',
			'metaboxhidden_page',
			'metaboxhidden_post',
			'screen_layout_page',
			'screen_layout_post',
			'managenav-menuscolumnshidden',
			'show_admin_bar_front',
			'wp_media_library_mode',
			'wp_persisted_preferences',
			'community-events-location',
			'dismissed_wp_pointers',
			'nav_menu_recently_edited',
			'wp_dashboard_quick_press_last_post_id',
			'first_name',
			'last_name',
			'nickname',
			'description',
		);

		$keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} ORDER BY meta_key" );
		$keys = array_diff( (array) $keys, $excluded );

		$fields = array();
		foreach ( $keys as $key ) {
			$fields[ $key ] = $key;
		}

		return $fields;
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'export_types',
				'type'    => 'multiselect',
				'label'   => __( 'Export types', 'ux-studio' ),
				'help'    => __( 'Formats offered in the row, bulk and profile export actions.', 'ux-studio' ),
				'options' => array(
					'csv'  => __( 'CSV', 'ux-studio' ),
					'json' => __( 'JSON', 'ux-studio' ),
					'txt'  => __( 'TXT', 'ux-studio' ),
				),
				'default' => array( 'csv' ),
			),
			array(
				'key'     => 'default_fields',
				'type'    => 'multiselect',
				'label'   => __( 'Fields', 'ux-studio' ),
				'help'    => __( 'User fields included in the export.', 'ux-studio' ),
				'options' => $this->get_default_fields(),
				'default' => $this->default_field_defaults(),
			),
			array(
				'key'     => 'meta_fields',
				'type'    => 'multiselect',
				'label'   => __( 'Meta fields', 'ux-studio' ),
				'help'    => __( 'Custom fields (user meta) to include in the export.', 'ux-studio' ),
				'options' => $this->get_meta_fields(),
				'default' => array(),
			),
		);
	}
}
