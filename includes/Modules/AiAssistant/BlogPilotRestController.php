<?php
/**
 * REST endpoints for the Blog Pilot tab: generator CRUD, manual "run now", pause/resume, AI topic suggestions, the
 * generated-post review (preview, approve, schedule, regenerate, reject), the calendar and dashboard stats.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Modules\AiAssistant\BlogPilot\ArticleTypes;
use UxStudio\Modules\AiAssistant\BlogPilot\CalendarService;
use UxStudio\Modules\AiAssistant\BlogPilot\GeneratorManager;
use UxStudio\Modules\AiAssistant\BlogPilot\PostCreator;
use UxStudio\Modules\AiAssistant\BlogPilot\PostWorkflow;
use UxStudio\Modules\AiAssistant\BlogPilot\PublishSlots;
use UxStudio\Modules\AiAssistant\BlogPilot\RateLimit;
use UxStudio\Modules\AiAssistant\BlogPilot\Scheduler;
use UxStudio\Modules\AiAssistant\BlogPilot\TopicSuggester;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Generator routes need manage_options, post routes edit_posts (plus the capability for the post itself in the handler):
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/generators
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/generators
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/generators/{id}
 *   PUT    uxstudio/v1/ai-assistant/blog-pilot/generators/{id}
 *   DELETE uxstudio/v1/ai-assistant/blog-pilot/generators/{id}
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/generators/{id}/run          (AI; optional topic/article_type/keyword/summary)
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/generators/{id}/toggle
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/topics/defaults
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/topics/suggest               (AI)
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/topics/regenerate-one        (AI)
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/posts                        (edit_posts; ?status=review|all|draft|pending|future|publish)
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/posts/bulk-action            (edit_posts)
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/posts/bulk-approve           (edit_posts)
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/posts/{id}/preview           (edit_posts)
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/posts/{id}/schedule          (edit_posts)
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/posts/{id}/approve           (edit_posts)
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/posts/{id}/regenerate        (edit_posts; AI)
 *   POST   uxstudio/v1/ai-assistant/blog-pilot/posts/{id}/reject            (edit_posts)
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/calendar?year=&month=        (edit_posts)
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/stats
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/article-types
 *   GET    uxstudio/v1/ai-assistant/blog-pilot/providers
 */
final class BlogPilotRestController extends Controller {

	private const BASE = '/ai-assistant/blog-pilot';

	/** Generated posts can only be handled in bulk by status name. */
	private const LIST_STATUSES = array(
		'review'  => array( 'draft', 'pending' ),
		'draft'   => array( 'draft' ),
		'pending' => array( 'pending' ),
		'future'  => array( 'future' ),
		'publish' => array( 'publish' ),
	);

	public function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'required' => true,
				'type'     => 'integer',
				'minimum'  => 1,
			),
		);

		$this->route( self::BASE . '/generators', 'GET', array( $this, 'list_generators' ) );
		$this->route( self::BASE . '/generators', 'POST', array( $this, 'create_generator' ) );
		$this->route( self::BASE . '/generators/(?P<id>\d+)', 'GET', array( $this, 'get_generator' ), $id_arg );
		$this->route( self::BASE . '/generators/(?P<id>\d+)', 'PUT', array( $this, 'update_generator' ), $id_arg );
		$this->route( self::BASE . '/generators/(?P<id>\d+)', 'DELETE', array( $this, 'delete_generator' ), $id_arg );
		$this->route(
			self::BASE . '/generators/(?P<id>\d+)/run',
			'POST',
			array( $this, 'run_generator' ),
			$id_arg + array(
				'topic'        => array( 'type' => 'string', 'maxLength' => 500 ),
				'article_type' => array( 'type' => 'string', 'maxLength' => 50 ),
				'keyword'      => array( 'type' => 'string', 'maxLength' => 100 ),
				'summary'      => array( 'type' => 'string', 'maxLength' => 500 ),
			)
		);
		$this->route( self::BASE . '/generators/(?P<id>\d+)/toggle', 'POST', array( $this, 'toggle_generator' ), $id_arg );

		$this->route( self::BASE . '/topics/defaults', 'GET', array( $this, 'topic_defaults' ) );
		$topic_args = array(
			'brief'           => array( 'type' => 'string', 'maxLength' => 2000 ),
			'count'           => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => TopicSuggester::MAX_COUNT ),
			'language'        => array( 'type' => 'string', 'maxLength' => 10 ),
			'tone'            => array( 'type' => 'string', 'maxLength' => 100 ),
			'audience'        => array( 'type' => 'string', 'maxLength' => 300 ),
			'types'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 40 ),
			'existing_topics' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 300 ),
			'provider'        => array( 'type' => 'string', 'maxLength' => 50 ),
			'model'           => array( 'type' => 'string', 'maxLength' => 100 ),
		);
		$this->route( self::BASE . '/topics/suggest', 'POST', array( $this, 'suggest_topics' ), $topic_args );
		$this->route(
			self::BASE . '/topics/regenerate-one',
			'POST',
			array( $this, 'regenerate_topic' ),
			$topic_args + array(
				'rejected' => array( 'type' => 'object', 'required' => true ),
				'current'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'maxItems' => 50 ),
			)
		);

		$this->route(
			self::BASE . '/posts',
			'GET',
			array( $this, 'list_posts' ),
			array( 'status' => array( 'type' => 'string', 'enum' => array_merge( array_keys( self::LIST_STATUSES ), array( 'all' ) ) ) ),
			'edit_posts'
		);
		$this->route( self::BASE . '/posts/bulk-action', 'POST', array( $this, 'bulk_action' ), array(), 'edit_posts' );
		$this->route(
			self::BASE . '/posts/bulk-approve',
			'POST',
			array( $this, 'bulk_approve' ),
			array(
				'post_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'required' => true, 'maxItems' => 100 ),
				'plan'     => array( 'type' => 'object' ),
			),
			'edit_posts'
		);
		$this->route( self::BASE . '/posts/(?P<id>\d+)/preview', 'GET', array( $this, 'preview_post' ), $id_arg, 'edit_posts' );
		$this->route(
			self::BASE . '/posts/(?P<id>\d+)/schedule',
			'POST',
			array( $this, 'schedule_post' ),
			$id_arg + array( 'datetime' => array( 'type' => 'string', 'required' => true, 'maxLength' => 25 ) ),
			'edit_posts'
		);
		$this->route(
			self::BASE . '/posts/(?P<id>\d+)/approve',
			'POST',
			array( $this, 'approve_post' ),
			$id_arg + array(
				'mode'     => array( 'type' => 'string', 'required' => true, 'enum' => array( 'now', 'schedule', 'plan' ) ),
				'datetime' => array( 'type' => 'string', 'maxLength' => 25 ),
				'plan'     => array( 'type' => 'object' ),
			),
			'edit_posts'
		);
		$this->route( self::BASE . '/posts/(?P<id>\d+)/regenerate', 'POST', array( $this, 'regenerate_post' ), $id_arg, 'edit_posts' );
		$this->route( self::BASE . '/posts/(?P<id>\d+)/reject', 'POST', array( $this, 'reject_post' ), $id_arg, 'edit_posts' );

		$this->route(
			self::BASE . '/calendar',
			'GET',
			array( $this, 'get_calendar' ),
			array(
				'year'  => array( 'type' => 'integer', 'minimum' => 2000, 'maximum' => 2100 ),
				'month' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ),
			),
			'edit_posts'
		);

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
			$next_run       = $scheduler->is_scheduled( (int) $item->id );
			$item->next_run = $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null;
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
		$plan_error = $this->plan_error( $data );
		if ( null !== $plan_error ) {
			return new WP_Error( 'uxstudio_invalid_plan', $plan_error, array( 'status' => 422 ) );
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

		$next_run            = ( new Scheduler() )->is_scheduled( (int) $generator->id );
		$generator->next_run = $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null;

		return $this->ok( $generator );
	}

	public function update_generator( WP_REST_Request $request ) {
		$manager = new GeneratorManager();
		$id      = absint( $request->get_param( 'id' ) );

		if ( ! $manager->get( $id ) ) {
			return new WP_Error( 'uxstudio_generator_not_found', __( 'Generator not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$data       = (array) $request->get_json_params();
		$plan_error = $this->plan_error( $data );
		if ( null !== $plan_error ) {
			return new WP_Error( 'uxstudio_invalid_plan', $plan_error, array( 'status' => 422 ) );
		}

		$manager->update( $id, $data );
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
		$guard = $this->ai_guard();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$override = array();
		foreach ( array( 'topic', 'article_type', 'keyword', 'summary' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$override[ $key ] = sanitize_text_field( $value );
			}
		}

		$result = ( new Scheduler() )->run_manual( absint( $request->get_param( 'id' ) ), $override ? $override : null );

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

	// ─── Topic suggestions ───────────────────────────────────────────

	public function topic_defaults(): WP_REST_Response {
		return $this->ok( ( new TopicSuggester() )->defaults() );
	}

	public function suggest_topics( WP_REST_Request $request ) {
		$guard = $this->ai_guard();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		try {
			return $this->ok( ( new TopicSuggester() )->suggest( $this->topic_input( $request ) ) );
		} catch ( \Throwable $e ) {
			return $this->ai_error( 'blog_pilot_topics', $e );
		}
	}

	public function regenerate_topic( WP_REST_Request $request ) {
		$params   = (array) $request->get_json_params();
		$rejected = $params['rejected'] ?? null;
		if ( ! is_array( $rejected ) || '' === trim( (string) ( $rejected['title'] ?? '' ) ) ) {
			return new WP_Error( 'uxstudio_missing_params', __( 'Missing the proposal to replace.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$current = array_values( array_filter( (array) ( $params['current'] ?? array() ), 'is_array' ) );

		$guard = $this->ai_guard();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		try {
			return $this->ok( ( new TopicSuggester() )->regenerate_one( $this->topic_input( $request ), $rejected, array_slice( $current, 0, 50 ) ) );
		} catch ( \Throwable $e ) {
			return $this->ai_error( 'blog_pilot_topics', $e );
		}
	}

	// ─── Generated posts ─────────────────────────────────────────────

	public function list_posts( WP_REST_Request $request ): WP_REST_Response {
		$manager      = new GeneratorManager();
		$page         = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$per_page     = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ) ?: 20 ) );
		$generator_id = absint( $request->get_param( 'generator_id' ) );
		$status       = (string) $request->get_param( 'status' );
		$statuses     = self::LIST_STATUSES[ $status ] ?? array();

		$result = $manager->get_generated_posts( $generator_id, $page, $per_page, $statuses );

		// A user only sees the posts they may edit.
		$result['items'] = array_values(
			array_filter(
				$result['items'],
				static fn( $row ): bool => current_user_can( 'edit_post', (int) $row->post_id )
			)
		);
		foreach ( $result['items'] as $row ) {
			$row->edit_url = (string) get_edit_post_link( (int) $row->post_id, 'raw' );
		}

		return $this->ok( $result );
	}

	public function bulk_action( WP_REST_Request $request ) {
		$params   = (array) $request->get_json_params();
		$action   = sanitize_text_field( (string) ( $params['action'] ?? '' ) );
		$post_ids = array_map( 'absint', (array) ( $params['post_ids'] ?? array() ) );

		if ( empty( $post_ids ) || ! in_array( $action, array( 'trash', 'draft', 'publish' ), true ) ) {
			return new WP_Error( 'uxstudio_missing_params', __( 'Missing action or posts.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$capability = array(
			'trash'   => 'delete_post',
			'draft'   => 'edit_post',
			'publish' => 'publish_post',
		);

		$count = 0;
		foreach ( array_slice( $post_ids, 0, 100 ) as $post_id ) {
			// Only posts Blog Pilot wrote, and only those this user may handle.
			if ( $post_id <= 0 || '1' !== (string) get_post_meta( $post_id, CalendarService::POST_META, true ) || ! current_user_can( $capability[ $action ], $post_id ) ) {
				continue;
			}
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

	public function preview_post( WP_REST_Request $request ) {
		return $this->respond( ( new PostWorkflow() )->preview( absint( $request->get_param( 'id' ) ) ) );
	}

	public function schedule_post( WP_REST_Request $request ) {
		return $this->respond( ( new PostWorkflow() )->schedule( absint( $request->get_param( 'id' ) ), (string) $request->get_param( 'datetime' ) ) );
	}

	public function approve_post( WP_REST_Request $request ) {
		$plan = $request->get_param( 'plan' );
		if ( null !== $plan ) {
			$error = PublishSlots::validate( $plan );
			if ( null !== $error ) {
				return new WP_Error( 'uxstudio_invalid_plan', $error, array( 'status' => 422 ) );
			}
		}

		return $this->respond(
			( new PostWorkflow() )->approve(
				absint( $request->get_param( 'id' ) ),
				(string) $request->get_param( 'mode' ),
				(string) $request->get_param( 'datetime' ),
				is_array( $plan ) ? $plan : null
			)
		);
	}

	public function bulk_approve( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$ids    = array_values( array_filter( array_map( 'absint', (array) ( $params['post_ids'] ?? array() ) ) ) );
		if ( ! $ids ) {
			return new WP_Error( 'uxstudio_missing_params', __( 'Missing action or posts.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$plan = $params['plan'] ?? null;
		if ( null !== $plan ) {
			$error = PublishSlots::validate( $plan );
			if ( null !== $error ) {
				return new WP_Error( 'uxstudio_invalid_plan', $error, array( 'status' => 422 ) );
			}
		}

		return $this->respond( ( new PostWorkflow() )->bulk_approve( array_slice( $ids, 0, 100 ), is_array( $plan ) ? $plan : null ) );
	}

	public function reject_post( WP_REST_Request $request ) {
		return $this->respond( ( new PostWorkflow() )->reject( absint( $request->get_param( 'id' ) ) ) );
	}

	public function regenerate_post( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'uxstudio_post_not_found', __( 'Post not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to do this with this post.', 'ux-studio' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $post->post_status, PostWorkflow::REVIEWABLE, true ) ) {
			return new WP_Error( 'uxstudio_post_status', __( 'Only a draft or pending post can be written again.', 'ux-studio' ), array( 'status' => 409 ) );
		}

		$manager = new GeneratorManager();
		$row     = $manager->get_generated_row( $post_id );
		if ( ! $row ) {
			return new WP_Error( 'uxstudio_not_generated', __( 'This post was not written by Blog Pilot.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$guard = $this->ai_guard();
		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		// The generator may have been deleted since: write with the default provider and settings then.
		$generator = $manager->get( (int) $row->generator_id );
		if ( ! $generator ) {
			$generator = (object) array(
				'provider' => '',
				'model'    => '',
				'config'   => array(),
			);
		}

		try {
			$result = ( new PostCreator() )->regenerate(
				$post_id,
				$generator,
				(string) $row->topic,
				(string) $row->article_type,
				array(
					'keyword' => (string) ( $row->keyword ?? '' ),
					'summary' => (string) ( $row->summary ?? '' ),
				)
			);
		} catch ( \Throwable $e ) {
			return $this->ai_error( 'blog_pilot_regenerate', $e );
		}

		$manager->add_tokens( $post_id, (int) $result['usage']['input_tokens'] + (int) $result['usage']['output_tokens'] );

		$preview = ( new PostWorkflow() )->preview( $post_id );
		if ( $preview instanceof WP_Error ) {
			return $preview;
		}
		$preview['warnings'] = $result['warnings'];

		return $this->ok( $preview );
	}

	// ─── Calendar ────────────────────────────────────────────────────

	public function get_calendar( WP_REST_Request $request ) {
		$year  = absint( $request->get_param( 'year' ) ) ?: (int) wp_date( 'Y' );
		$month = absint( $request->get_param( 'month' ) ) ?: (int) wp_date( 'n' );

		return $this->respond( ( new CalendarService() )->month( $year, $month ) );
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

	// ─── Helpers ─────────────────────────────────────────────────────

	/**
	 * @param mixed $result Array payload or WP_Error from a service.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $result ) {
		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Budget and usage-limit check in front of every route that calls the AI.
	 */
	private function ai_guard(): ?WP_Error {
		if ( ! RateLimit::allow( 'ai' ) ) {
			return new WP_Error( 'uxstudio_rate_limited', __( 'Too many AI requests, try again in a few minutes.', 'ux-studio' ), array( 'status' => 429 ) );
		}
		$limit_error = UsageLimiter::check();
		if ( null !== $limit_error ) {
			return new WP_Error( 'uxstudio_usage_limit', $limit_error, array( 'status' => 429 ) );
		}
		return null;
	}

	private function ai_error( string $scope, \Throwable $e ): WP_Error {
		$detail = ErrorLogger::parse_exception_detail( $e->getMessage() );
		ErrorLogger::log(
			array(
				'error_type'    => ErrorLogger::TYPE_PROVIDER,
				'error_message' => $e->getMessage(),
				'http_status'   => $detail['http_status'],
				'context'       => array(
					'scope'           => $scope,
					'exception_class' => get_class( $e ),
				),
			)
		);
		return new WP_Error( 'uxstudio_ai_failed', $e->getMessage(), array( 'status' => 502 ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function topic_input( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		return array(
			'brief'           => sanitize_textarea_field( (string) ( $params['brief'] ?? '' ) ),
			'count'           => (int) ( $params['count'] ?? 5 ),
			'language'        => sanitize_text_field( (string) ( $params['language'] ?? '' ) ),
			'tone'            => sanitize_text_field( (string) ( $params['tone'] ?? '' ) ),
			'audience'        => sanitize_text_field( (string) ( $params['audience'] ?? '' ) ),
			'types'           => array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $params['types'] ?? array() ) ) ),
			'existing_topics' => array_map( 'sanitize_text_field', array_map( 'strval', array_slice( (array) ( $params['existing_topics'] ?? array() ), 0, 300 ) ) ),
			'provider'        => sanitize_text_field( (string) ( $params['provider'] ?? '' ) ),
			'model'           => sanitize_text_field( (string) ( $params['model'] ?? '' ) ),
		);
	}

	/**
	 * Strict check of the publish plan inside a generator's config.
	 *
	 * @param array<string, mixed> $data
	 */
	private function plan_error( array $data ): ?string {
		$plan = ( (array) ( $data['config'] ?? array() ) )['publish_plan'] ?? null;
		return null === $plan ? null : PublishSlots::validate( $plan );
	}
}
