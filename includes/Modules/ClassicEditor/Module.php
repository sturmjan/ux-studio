<?php
/**
 * Classic Editor module - disable the block editor per post type (ported from legacy module), optionally with a
 * choice of editor for every user.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ClassicEditor;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Disables the block editor (Gutenberg) either for all post types or only for the post types selected in the module
 * settings. With "Users can choose their editor" every user has a default editor in the profile, the list of posts
 * offers "Edit (classic editor)" / "Edit (block editor)" and the editor a post was last opened in is remembered (the
 * behaviour of the Classic Editor plugin, with the same stored values).
 */
final class Module extends BaseModule {

	/**
	 * Post types that never get the classic editor treatment.
	 *
	 * @var string[]
	 */
	private array $excluded_post_types = array( 'attachment', 'elementor_library' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		if ( $this->users_may_choose() ) {
			$this->boot_user_choice();
			return;
		}

		if ( version_compare( get_bloginfo( 'version' ), '5.0', '<' ) ) {
			add_filter( 'gutenberg_can_edit_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		} else {
			add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		}
	}

	/**
	 * Whether every user may pick the editor.
	 */
	public function users_may_choose(): bool {
		return (bool) $this->settings->get( 'allow_users', false );
	}

	/**
	 * Decide whether the block editor should be used for a post type.
	 *
	 * @param bool   $use_block_editor Whether to use the block editor.
	 * @param string $post_type        Post type being checked.
	 */
	public function disable_gutenberg( $use_block_editor, $post_type ): bool {
		if ( in_array( $post_type, $this->get_excluded_post_types(), true ) ) {
			return (bool) $use_block_editor;
		}

		$disable = $this->site_default_is_classic( (string) $post_type );

		/**
		 * Filter whether the block editor is disabled for a post type.
		 *
		 * @param bool   $disable   True to force the classic editor.
		 * @param string $post_type Post type being checked.
		 */
		$disable = (bool) apply_filters( 'ux_studio/classic_editor/disable_gutenberg', $disable, $post_type );

		return $disable ? false : (bool) $use_block_editor;
	}

	/**
	 * The editor the site uses for a post type when nobody chose: the classic editor for the selected types (none
	 * selected = all), unless the site default is the block editor.
	 *
	 * @param string $post_type Post type.
	 */
	public function site_default_is_classic( string $post_type ): bool {
		if ( 'block' === $this->settings->get( 'default_editor', 'classic' ) ) {
			return false;
		}
		$post_types = (array) $this->settings->get( 'post_types', array() );

		// Empty selection = classic editor everywhere.
		return array() === $post_types || in_array( $post_type, $post_types, true );
	}

	/**
	 * Post types excluded from the classic editor.
	 *
	 * @return string[]
	 */
	private function get_excluded_post_types(): array {
		/**
		 * Filter the post types the classic editor never applies to.
		 *
		 * @param string[] $excluded Excluded post type names.
		 */
		return (array) apply_filters( 'ux_studio/classic_editor/excluded_post_types', $this->excluded_post_types );
	}

	// ------------------------------------------------------------------
	// Users choose their editor.
	// ------------------------------------------------------------------

	/**
	 * Hooks of the mode in which every user has an editor of their own.
	 */
	private function boot_user_choice(): void {
		// The profile.
		add_action( 'show_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );

		// Which editor opens a post. Late, like the plugin, so the choice wins over other filters.
		add_filter( 'use_block_editor_for_post', array( $this, 'choose_editor' ), 100, 2 );

		// Keep the choice through saving, revisions and links.
		add_filter( 'get_edit_post_link', array( $this, 'filter_edit_link' ) );
		add_filter( 'redirect_post_location', array( $this, 'keep_classic_on_redirect' ) );
		add_action( 'edit_form_top', array( $this, 'classic_form_marker' ) );
		add_action( 'edit_form_top', array( $this, 'remember_classic' ) );
		add_filter( 'block_editor_settings_all', array( $this, 'remember_block' ), 10, 2 );

		// Switching: list of posts, state labels, boxes in the editors.
		add_filter( 'post_row_actions', array( $this, 'add_edit_links' ), 15, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_edit_links' ), 15, 2 );
		add_filter( 'display_post_states', array( $this, 'add_post_state' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_switch_box' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_switch' ) );
		add_action( 'admin_head-edit.php', array( $this, 'state_style' ) );
	}

	/**
	 * The editor the current user (or the given one) gets by default.
	 *
	 * @param string $post_type Post type.
	 * @param int    $user_id   User id, 0 = current.
	 */
	private function default_is_classic( string $post_type, int $user_id = 0 ): bool {
		$choice = (string) get_user_option( EditorChoice::USER_OPTION, $user_id );

		return EditorChoice::default_is_classic( $this->site_default_is_classic( $post_type ), $choice );
	}

	/**
	 * Both editors can be used for the post (the block editor is not available for every type).
	 *
	 * @param \WP_Post $post Post.
	 * @return array{classic:bool,block:bool}
	 */
	private function editors_for( \WP_Post $post ): array {
		if ( in_array( $post->post_type, $this->get_excluded_post_types(), true ) ) {
			return array(
				'classic' => false,
				'block'   => false,
			);
		}

		return array(
			'classic' => post_type_supports( $post->post_type, 'editor' ),
			'block'   => function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post->post_type ),
		);
	}

	/**
	 * Filter `use_block_editor_for_post`: true = block editor.
	 *
	 * @param bool          $use_block_editor Block editor (as far as WordPress and other code decided).
	 * @param \WP_Post|null $post             Post.
	 */
	public function choose_editor( $use_block_editor, $post ): bool {
		if ( ! $post instanceof \WP_Post || ! $use_block_editor ) {
			return (bool) $use_block_editor;
		}
		$editors = $this->editors_for( $post );
		if ( ! $editors['classic'] || ! $editors['block'] ) {
			return (bool) $use_block_editor;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- choosing a screen, nothing is changed.
		$classic = EditorChoice::is_classic(
			array(
				'default_classic' => $this->default_is_classic( $post->post_type ),
				'new_post'        => empty( $post->ID ) || 'auto-draft' === $post->post_status,
				'forget'          => isset( $_GET['classic-editor__forget'] ),
				'qs_classic'      => isset( $_GET['classic-editor'] ),
				'remembered'      => (string) get_post_meta( (int) $post->ID, EditorChoice::REMEMBER_META, true ),
				'has_blocks'      => has_blocks( $post->post_content ),
			)
		);
		// phpcs:enable

		return ! $classic;
	}

	/**
	 * Keep `?classic-editor` on links to the edit screen while the classic editor is the one in use.
	 *
	 * @param string $url Edit link.
	 */
	public function filter_edit_link( $url ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['classic-editor'] ) || $this->default_is_classic( 'post' ) ) {
			$url = add_query_arg( 'classic-editor', '', (string) $url );
		}

		return $url;
	}

	/**
	 * Keep `classic-editor` in the address after saving.
	 *
	 * @param string $location Redirect location.
	 */
	public function keep_classic_on_redirect( $location ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$referer = isset( $_POST['_wp_http_referer'] ) ? (string) wp_unslash( $_POST['_wp_http_referer'] ) : '';
		if ( isset( $_REQUEST['classic-editor'] ) || false !== strpos( $referer, '&classic-editor' ) ) {
			$location = add_query_arg( 'classic-editor', '', (string) $location );
		}
		// phpcs:enable

		return $location;
	}

	/**
	 * A marker in the classic edit form: posted back on save, it tells the redirect to stay in the classic editor.
	 */
	public function classic_form_marker(): void {
		echo '<input type="hidden" name="classic-editor" value="" />';
	}

	/**
	 * The classic editor was used for the post: remember it.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function remember_classic( $post ): void {
		if ( $post instanceof \WP_Post && post_type_supports( $post->post_type, 'editor' ) ) {
			$this->remember( (int) $post->ID, 'classic-editor' );
		}
	}

	/**
	 * The block editor was used for the post: remember it.
	 *
	 * @param array<string, mixed> $settings Editor settings.
	 * @param mixed                $context  Editor context.
	 * @return array<string, mixed>
	 */
	public function remember_block( $settings, $context ) {
		$post = $context instanceof \WP_Post ? $context : ( is_object( $context ) && ! empty( $context->post ) ? $context->post : null );
		if ( $post instanceof \WP_Post && function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post->post_type ) ) {
			$this->remember( (int) $post->ID, 'block-editor' );
		}

		return $settings;
	}

	/**
	 * @param int    $post_id Post id.
	 * @param string $editor  `classic-editor` | `block-editor`.
	 */
	private function remember( int $post_id, string $editor ): void {
		if ( $post_id > 0 && get_post_meta( $post_id, EditorChoice::REMEMBER_META, true ) !== $editor ) {
			update_post_meta( $post_id, EditorChoice::REMEMBER_META, $editor );
		}
	}

	/**
	 * Row actions: edit in the block editor / in the classic editor instead of the single "Edit".
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public function add_edit_links( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof \WP_Post || ! array_key_exists( 'edit', $actions ) ) {
			return $actions;
		}
		$edit_url = get_edit_post_link( $post->ID, 'raw' );
		$editors  = $this->editors_for( $post );
		if ( ! $edit_url || ! $editors['classic'] || ! $editors['block'] ) {
			return $actions;
		}

		// "Forget" what the post remembers: the user names the editor.
		$edit_url = add_query_arg( 'classic-editor__forget', '', $edit_url );
		$title    = _draft_or_post_title( $post->ID );

		$block   = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( remove_query_arg( 'classic-editor', $edit_url ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Edit "%s" in the block editor', 'ux-studio' ), $title ) ),
			esc_html__( 'Edit (block editor)', 'ux-studio' )
		);
		$classic = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( add_query_arg( 'classic-editor', '', $edit_url ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Edit "%s" in the classic editor', 'ux-studio' ), $title ) ),
			esc_html__( 'Edit (classic editor)', 'ux-studio' )
		);

		// Put the two links where "Edit" was. (array_splice would renumber the keys WordPress turns into CSS classes.)
		$result = array();
		foreach ( $actions as $key => $link ) {
			if ( 'edit' === $key ) {
				$result['uxstudio-editor-block']   = $block;
				$result['uxstudio-editor-classic'] = $classic;
				continue;
			}
			$result[ $key ] = $link;
		}

		return $result;
	}

	/**
	 * The editor a post will open in, as a state next to its title in the list.
	 *
	 * @param array<string, string> $states States.
	 * @param \WP_Post              $post   Post.
	 * @return array<string, string>
	 */
	public function add_post_state( $states, $post ) {
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return $states;
		}
		$editors = $this->editors_for( $post );
		if ( ! $editors['classic'] && ! $editors['block'] ) {
			return $states;
		}

		if ( $editors['classic'] && $editors['block'] ) {
			$remembered = (string) get_post_meta( $post->ID, EditorChoice::REMEMBER_META, true );
			if ( '' !== $remembered ) {
				$classic = 'classic-editor' === $remembered;
			} elseif ( '' !== $post->post_content ) {
				$classic = ! has_blocks( $post->post_content );
			} else {
				$classic = $this->default_is_classic( $post->post_type );
			}
			$label = $classic ? __( 'Classic editor', 'ux-studio' ) : __( 'Block editor', 'ux-studio' );
		} else {
			$label = $editors['classic'] ? __( 'classic editor', 'ux-studio' ) : __( 'block editor', 'ux-studio' );
		}

		$states                         = (array) $states;
		$states['uxstudio-editor-used'] = '<span class="uxstudio-editor-state">' . esc_html( $label ) . '</span>';

		return $states;
	}

	/**
	 * Style of the state label.
	 */
	public function state_style(): void {
		echo '<style>.uxstudio-editor-state{font-style:italic;font-weight:400;color:#72777c;font-size:small}</style>';
	}

	/**
	 * Address of the edit screen in the other editor (the post forgets what it remembered).
	 *
	 * @param int  $post_id Post id.
	 * @param bool $classic Open in the classic editor.
	 */
	private function switch_url( int $post_id, bool $classic ): string {
		$url = (string) get_edit_post_link( $post_id, 'raw' );
		$url = remove_query_arg( 'classic-editor', $url );
		$url = add_query_arg( 'classic-editor__forget', '', $url );

		return $classic ? add_query_arg( 'classic-editor', '', $url ) : $url;
	}

	/**
	 * "Switch to block editor" in the sidebar of the classic edit screen.
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post      Post.
	 */
	public function add_switch_box( $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$editors = $this->editors_for( $post );
		if ( ! $editors['classic'] || ! $editors['block'] ) {
			return;
		}

		add_meta_box(
			'uxstudio-editor-switch',
			__( 'Editor', 'ux-studio' ),
			function ( \WP_Post $current ): void {
				printf(
					'<p style="margin:1em 0"><a href="%s">%s</a></p>',
					esc_url( $this->switch_url( (int) $current->ID, false ) ),
					esc_html__( 'Switch to block editor', 'ux-studio' )
				);
			},
			null,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * "Switch to classic editor" in the options menu of the block editor.
	 */
	public function enqueue_block_switch(): void {
		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! $this->editors_for( $post )['classic'] ) {
			return;
		}

		wp_enqueue_script(
			'uxstudio-editor-switch',
			UXSTUDIO_URL . 'includes/Modules/ClassicEditor/assets/block-editor-switch.js',
			array( 'wp-element', 'wp-plugins', 'wp-edit-post' ),
			defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false,
			true
		);
		wp_localize_script(
			'uxstudio-editor-switch',
			'uxStudioEditorSwitch',
			array(
				'url'  => $this->switch_url( (int) $post->ID, true ),
				'text' => __( 'Switch to classic editor', 'ux-studio' ),
			)
		);
	}

	/**
	 * The "Default editor" row in the profile.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public function render_profile_field( $user ): void {
		if ( ! $user instanceof \WP_User || ! user_can( $user, 'edit_posts' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$choice = EditorChoice::clean_choice( (string) get_user_option( EditorChoice::USER_OPTION, $user->ID ) );
		wp_nonce_field( 'uxstudio_editor_choice', 'uxstudio_editor_choice_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr class="uxstudio-editor-choice">
				<th scope="row"><?php esc_html_e( 'Default editor', 'ux-studio' ); ?></th>
				<td>
					<fieldset>
						<p><label><input type="radio" name="uxstudio_editor_choice" value="" <?php checked( '', $choice ); ?> /> <?php esc_html_e( 'As set for the site', 'ux-studio' ); ?></label></p>
						<p><label><input type="radio" name="uxstudio_editor_choice" value="classic" <?php checked( 'classic', $choice ); ?> /> <?php esc_html_e( 'Classic editor', 'ux-studio' ); ?></label></p>
						<p><label><input type="radio" name="uxstudio_editor_choice" value="block" <?php checked( 'block', $choice ); ?> /> <?php esc_html_e( 'Block editor', 'ux-studio' ); ?></label></p>
					</fieldset>
				</td>
			</tr>
		</table>
		<script>document.querySelectorAll('tr.user-rich-editing-wrap').forEach(function(r){var t=document.querySelector('tr.uxstudio-editor-choice');if(t){r.parentNode.insertBefore(t,r);}});</script>
		<?php
	}

	/**
	 * Save the choice from the profile.
	 *
	 * @param int $user_id User id.
	 */
	public function save_profile_field( $user_id ): void {
		$user_id = (int) $user_id;
		if (
			! isset( $_POST['uxstudio_editor_choice_nonce'], $_POST['uxstudio_editor_choice'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uxstudio_editor_choice_nonce'] ) ), 'uxstudio_editor_choice' )
			|| ! current_user_can( 'edit_user', $user_id )
		) {
			return;
		}

		$choice = EditorChoice::clean_choice( sanitize_text_field( wp_unslash( $_POST['uxstudio_editor_choice'] ) ) );
		if ( '' === $choice ) {
			delete_user_option( $user_id, EditorChoice::USER_OPTION );
		} else {
			update_user_option( $user_id, EditorChoice::USER_OPTION, $choice );
		}
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Post types', 'ux-studio' ),
				'help'    => __( 'Select the post types to enable the classic editor for. Leave empty to enable it for all post types.', 'ux-studio' ),
				'options' => $this->get_post_type_options(),
				'default' => array(),
			),
			array(
				'key'     => 'allow_users',
				'type'    => 'toggle',
				'label'   => __( 'Users can choose their editor', 'ux-studio' ),
				'help'    => __( 'Every user gets a Default editor setting in the profile, the list of posts offers "Edit (classic editor)" and "Edit (block editor)", and the editor a post was last opened in is remembered. The post types above are the site default for users who have not chosen.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'default_editor',
				'type'    => 'select',
				'label'   => __( 'Site default editor', 'ux-studio' ),
				'help'    => __( 'The editor users get when they have not chosen one. "Block editor" only makes sense together with the switch above.', 'ux-studio' ),
				'options' => array(
					'classic' => __( 'Classic editor', 'ux-studio' ),
					'block'   => __( 'Block editor', 'ux-studio' ),
				),
				'default' => 'classic',
			),
		);
	}

	/**
	 * Selectable public post types.
	 *
	 * @return array<string, string>
	 */
	private function get_post_type_options(): array {
		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		$options = array();

		foreach ( $post_types as $type ) {
			if ( in_array( $type->name, $this->get_excluded_post_types(), true ) ) {
				continue;
			}
			$options[ $type->name ] = $type->labels->name;
		}

		return $options;
	}
}
