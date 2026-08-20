<?php
/**
 * REST endpoints for the Blog Pilot tab: generator CRUD, manual "run now",
 * pause/resume, generated-post listing/bulk actions, dashboard stats.
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (rest/BlogPilotController.php).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Modules\AiAssistant\BlogPilot\ArticleTypes;
use UxStudio\Modules\AiAssistant\BlogPilot\GeneratorManager;
use UxStudio\Modules\AiAssistant\BlogPilot\Scheduler;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * All routes require manage_options (via Controller::route()):
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/generators
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/generators
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/generators/{id}
 *   PUT    uxstudio/v1/ai-assistant/blog-pilot/generators/{id}
 *   DELETE uxstudio/v1/ai-assistant/blog-pilot/generators/{id}
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/generators/{id}/run
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/generators/{id}/toggle
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/posts
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/posts/bulk-action
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/stats
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/article-types
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/providers
 */
final class BlogPilotRestController extends Controller {

	private const BASE = '/ai-assistant/blog-pilot';

	public function register_routes(): void {
		$this->route( self::BASE . '/generators', 'GET', array( $this, 'list_generators' ) );
		$this->route( self::BASE . '/generators', 'POST', array( $this, 'create_generator' ) );

		$this->route(
			self::BASE . '/generators/(?P<id>\d+)',
			'GET',
			array( $this, 'get_generator' ),
			array( 'id' => array( 'required' => true, 'type' => 'integer' ) )
		);
		$this->route(
			self::BASE . '/generators/(?P<id>\d+)',
			'PUT',
			array( $this, 'update_generator' ),
			array( 'id' => array( 'required' => true, 'type' => 'integer' ) )
		);
		$this->route(
			self::BASE . '/generators/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_generator' ),
			array( 'id' => array( 'required' => true, 'type' => 'integer' ) )
		);

		$this->route(
			self::BASE . '/generators/(?P<id>\d+)/run',
			'POST',
			array( $this, 'run_generator' ),
			array( 'id' => array( 'required' => true, 'type' => 'integer' ) )
		);

		$this->route(
			self::BASE . '/generators/(?P<id>\d+)/toggle',
			'POST',
			array( $this, 'toggle_generator' ),
			array( 'id' => array( 'required' => true, 'type' => 'integer' ) )
		);

		$this->route( self::BASE . '/posts', 'GET', array( $this, 'list_posts' ) );
		$this->route( self::BASE . '/posts/bulk-action', 'POST', array( $this, 'bulk_action' ) );

		$this->route( self::BASE . '/stats', 'GET', array( $this, 'get_stats' ) );
		$this->route( self::BASE . '/article-types', 'GET', array( $this, 'get_article_types' ) );
		$this->route( self::BASE . '/providers', 'GET', array( $this, 'get_providers' ) );
	}

	// ─── Generators ──────────────────────────────────────────────────

	public function list_generators( WP_REST_Request $request ): WP_REST_Response {
		$manager  = new GeneratorManager();
		$page     = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$per_page = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ) ?: 20 ) );

		$filters = array();
		if ( $request->get_param( 'status' ) ) {
			$filters['status'] = sanitize_text_field( (string) $request->get_param( 'status' ) );
		}
		if ( $request->get_param( 'search' ) ) {
			$filters['search'] = sanitize_text_field( (string) $request->get_param( 'search' ) );
		}

		$result = $manager->get_all( $filters, $page, $per_page );

		$scheduler = new Scheduler();
		foreach ( $result['items'] as $item ) {
			$next_run        = $scheduler->is_scheduled( (int) $item->id );
			$item->next_run  = $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null;
		}

		return $this->ok( $result );
	}

	public function create_generator( WP_REST_Request $request ) {
		$manager = new GeneratorManager();
		$data    = (array) $request->get_json_params();

		if ( empty( $data['title'] ) ) {
			return new WP_Error( 'uxstudio_missing_title', __( 'Title is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( empty( $data['topics'] ) ) {
			return new WP_Error( 'uxstudio_missing_topics', __( 'Add at least one topic.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$id = $manager->create( $data );
		if ( $id <= 0 ) {
			return new WP_Error( 'uxstudio_create_failed', __( 'Could not create the generator.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		( new Scheduler() )->schedule_generator( $id );

		return $this->ok(
			array(
				'id'      => $id,
				'message' => __( 'Generator created and scheduled.', 'ux-studio' ),
			)
		);
	}

	public function get_generator( WP_REST_Request $request ) {
		$manager   = new GeneratorManager();
		$generator = $manager->get( absint( $request->get_param( 'id' ) ) );

		if ( ! $generator ) {
			return new WP_Error( 'uxstudio_generator_not_found', __( 'Generator not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$next_run          = ( new Scheduler() )->is_scheduled( (int) $generator->id );
		$generator->next_run = $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null;

		return $this->ok( $generator );
	}

	public function update_generator( WP_REST_Request $request ) {
		$manager = new GeneratorManager();
		$id      = absint( $request->get_param( 'id' ) );

		if ( ! $manager->get( $id ) ) {
			return new WP_Error( 'uxstudio_generator_not_found', __( 'Generator not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$manager->update( $id, (array) $request->get_json_params() );
		( new Scheduler() )->schedule_generator( $id );

		return $this->ok( array( 'message' => __( 'Generator updated.', 'ux-studio' ) ) );
	}

	public function delete_generator( WP_REST_Request $request ) {
		$manager = new GeneratorManager();
		$id      = absint( $request->get_param( 'id' ) );

		if ( ! $manager->get( $id ) ) {
			return new WP_Error( 'uxstudio_generator_not_found', __( 'Generator not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		( new Scheduler() )->unschedule_generator( $id );
		$manager->delete( $id );

		return $this->ok( array( 'message' => __( 'Generator deleted.', 'ux-studio' ) ) );
	}

	public function run_generator( WP_REST_Request $request ) {
		$result = ( new Scheduler() )->run_manual( absint( $request->get_param( 'id' ) ) );

		if ( ! $result['success'] ) {
			return new WP_Error( 'uxstudio_run_failed', $result['error'] ?? __( 'Generation failed.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		return $this->ok( $result );
	}

	public function toggle_generator( WP_REST_Request $request ) {
		$manager   = new GeneratorManager();
		$id        = absint( $request->get_param( 'id' ) );
		$generator = $manager->get( $id );

		if ( ! $generator ) {
			return new WP_Error( 'uxstudio_generator_not_found', __( 'Generator not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$new_status = 'active' === $generator->status ? 'paused' : 'active';
		$manager->update( $id, array( 'status' => $new_status ) );

		$scheduler = new Scheduler();
		if ( 'active' === $new_status ) {
			$scheduler->schedule_generator( $id );
		} else {
			$scheduler->unschedule_generator( $id );
		}

		return $this->ok(
			array(
				'status'  => $new_status,
				'message' => 'active' === $new_status ? __( 'Generator activated.', 'ux-studio' ) : __( 'Generator paused.', 'ux-studio' ),
			)
		);
	}

	// ─── Generated posts ─────────────────────────────────────────────

	public function list_posts( WP_REST_Request $request ): WP_REST_Response {
		$manager      = new GeneratorManager();
		$page         = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$per_page     = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ) ?: 20 ) );
		$generator_id = absint( $request->get_param( 'generator_id' ) );

		return $this->ok( $manager->get_generated_posts( $generator_id, $page, $per_page ) );
	}

	public function bulk_action( WP_REST_Request $request ) {
		$params   = (array) $request->get_json_params();
		$action   = sanitize_text_field( (string) ( $params['action'] ?? '' ) );
		$post_ids = array_map( 'absint', (array) ( $params['post_ids'] ?? array() ) );

		if ( empty( $post_ids ) || '' === $action ) {
			return new WP_Error( 'uxstudio_missing_params', __( 'Missing action or posts.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$count = 0;
		foreach ( $post_ids as $post_id ) {
			switch ( $action ) {
				case 'trash':
					if ( wp_trash_post( $post_id ) ) {
						++$count;
					}
					break;
				case 'draft':
					if ( ! is_wp_error( wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true ) ) ) {
						++$count;
					}
					break;
				case 'publish':
					if ( ! is_wp_error( wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true ) ) ) {
						++$count;
					}
					break;
			}
		}

		return $this->ok(
			array(
				'affected' => $count,
				'message'  => sprintf(
					/* translators: 1: action name, 2: number of affected posts */
					__( "Action '%1\$s' applied to %2\$d post(s).", 'ux-studio' ),
					$action,
					$count
				),
			)
		);
	}

	// ─── Misc ────────────────────────────────────────────────────────

	public function get_stats(): WP_REST_Response {
		return $this->ok( ( new GeneratorManager() )->get_stats() );
	}

	public function get_article_types(): WP_REST_Response {
		return $this->ok( ArticleTypes::get_all() );
	}

	public function get_providers(): WP_REST_Response {
		return $this->ok( ProviderFactory::get_all_providers() );
	}
}
