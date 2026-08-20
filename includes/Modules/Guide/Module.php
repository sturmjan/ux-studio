<?php
/**
 * Guide module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Guide;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * A getting-started guide/checklist. Ported from the legacy module as data +
 * REST only: the step content is exposed through GET /guide/steps and the
 * completion state is stored in the uxstudio_guide_progress option and updated
 * through POST /guide/progress. The admin surface is provided later by the SPA.
 */
final class Module extends BaseModule {

	/** Option storing completed step ids. */
	private const PROGRESS_OPTION = 'uxstudio_guide_progress';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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
	 * The guide steps, each flagged with its completion state.
	 *
	 * @return array<int, array{id:string,title:string,description:string,completed:bool}>
	 */
	public function steps_with_progress(): array {
		$completed = $this->completed_ids();

		$steps = array();
		foreach ( $this->steps() as $step ) {
			$step['completed'] = in_array( $step['id'], $completed, true );
			$steps[]           = $step;
		}
		return $steps;
	}

	/**
	 * Persist the completed step ids (only known step ids are stored).
	 *
	 * @param string[] $completed Completed step ids.
	 * @return string[] Stored ids.
	 */
	public function save_progress( array $completed ): array {
		$valid = array_map(
			static fn ( array $step ): string => $step['id'],
			$this->steps()
		);

		$clean = array_values(
			array_intersect(
				array_map( 'sanitize_key', array_map( 'strval', $completed ) ),
				$valid
			)
		);

		update_option( self::PROGRESS_OPTION, $clean );

		return $clean;
	}

	/**
	 * Completed step ids currently stored.
	 *
	 * @return string[]
	 */
	private function completed_ids(): array {
		$stored = get_option( self::PROGRESS_OPTION, array() );
		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * The guide step definitions (the ported data content).
	 *
	 * @return array<int, array{id:string,title:string,description:string}>
	 */
	private function steps(): array {
		$steps = array(
			array(
				'id'          => 'site_identity',
				'title'       => __( 'Set your site identity', 'ux-studio' ),
				'description' => __( 'Add your site title, tagline and logo under Settings and Customizer.', 'ux-studio' ),
			),
			array(
				'id'          => 'permalinks',
				'title'       => __( 'Configure permalinks', 'ux-studio' ),
				'description' => __( 'Choose an SEO-friendly permalink structure under Settings → Permalinks.', 'ux-studio' ),
			),
			array(
				'id'          => 'homepage',
				'title'       => __( 'Choose your homepage', 'ux-studio' ),
				'description' => __( 'Decide between a static page or your latest posts under Settings → Reading.', 'ux-studio' ),
			),
			array(
				'id'          => 'menus',
				'title'       => __( 'Build your navigation', 'ux-studio' ),
				'description' => __( 'Create the primary menu and assign it to a theme location.', 'ux-studio' ),
			),
			array(
				'id'          => 'modules',
				'title'       => __( 'Enable the modules you need', 'ux-studio' ),
				'description' => __( 'Turn on the UX Studio modules that fit your workflow.', 'ux-studio' ),
			),
		);

		/**
		 * Filter the guide steps.
		 *
		 * @param array $steps Step definitions.
		 */
		return (array) apply_filters( 'ux_studio/guide/steps', $steps );
	}
}
