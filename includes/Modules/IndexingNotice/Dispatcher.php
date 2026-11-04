<?php
/**
 * Runs the queue: social preview refresh and IndexNow (PLAN.md 39.5).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\IndexingNotice;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\CentralApp\Gateway;
use UxStudio\Core\CentralApp\SiteIdentity;
use UxStudio\Modules\MaintenanceMode\Module as MaintenanceMode;
use UxStudio\Plugin;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * One WP-Cron single event (`CRON_HOOK`) 120 seconds after the first save in a burst takes everything that is due:
 * a save puts only the address of the saved post in the queue (SocialQueue, deduplicated), the run checks the facts
 * again (a post that became noindex in the meantime is left out, Eligibility), asks Facebook (through the Central
 * App or with own keys) and IndexNow, writes the outcome to the Activity Log and to the post meta, and puts a step
 * that failed for a reason that passes (rate limit, network) back for a later try.
 *
 * The manual path (row action, meta box) uses the same code through refresh_now(), at once and without the queue.
 */
final class Dispatcher {

	/** Single event that runs the queue. */
	public const CRON_HOOK = 'uxstudio_indexing_dispatch';

	/** Post meta: how the last refresh of this post went. */
	public const META_KEY = '_uxstudio_sharing_last';

	/** The queue never holds more than this many addresses (a runaway import must not fill the option). */
	private const QUEUE_LIMIT = 500;

	/** Entries of one run get their own Activity Log row up to this many, beyond that one summary row. */
	private const LOG_ROWS = 10;

	private Module $module;

	/**
	 * @param Module $module Owning module.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/* ── Queue storage ── */

	/**
	 * @return array<string, mixed>
	 */
	private static function load(): array {
		return SocialQueue::normalize( get_option( SocialQueue::OPTION, array() ) );
	}

	/**
	 * @param array<string, mixed> $queue State.
	 */
	private static function save( array $queue ): void {
		if ( ! $queue['items'] && ! $queue['sent'] ) {
			delete_option( SocialQueue::OPTION );
			return;
		}
		update_option( SocialQueue::OPTION, $queue, false );
	}

	/**
	 * Steps that are switched on in the settings.
	 *
	 * @return string[]
	 */
	public function steps(): array {
		$steps = array();
		if ( $this->module->setting( 'social_refresh_enabled', false ) ) {
			$steps[] = SocialQueue::STEP_SOCIAL;
		}
		if ( $this->module->setting( 'indexnow_enabled', true ) ) {
			$steps[] = SocialQueue::STEP_INDEXNOW;
		}
		return $steps;
	}

	/**
	 * Put the address of a saved post in the queue.
	 *
	 * @param WP_Post $post Saved post.
	 */
	public function enqueue( WP_Post $post ): void {
		$steps = $this->steps();
		if ( ! $steps ) {
			return;
		}
		// noindex is read from the post meta, which the block editor saves AFTER this hook, so it is judged at the run.
		if ( '' !== Eligibility::reason( self::facts_for( $post, false ) ) ) {
			return;
		}
		$url = (string) get_permalink( $post );
		if ( '' === $url ) {
			return;
		}
		$queue = self::load();
		if ( ! isset( $queue['items'][ $url ] ) && count( $queue['items'] ) >= self::QUEUE_LIMIT ) {
			return;
		}
		self::save( SocialQueue::add( $queue, $url, (int) $post->ID, time(), $steps ) );
		$this->schedule();
	}

	/**
	 * Make sure one event is waiting for the earliest due address.
	 */
	public function schedule(): void {
		$next = SocialQueue::next_due( self::load() );
		if ( null === $next ) {
			return;
		}
		$next      = max( $next, time() + 5 );
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $scheduled && $scheduled <= $next ) {
			return;
		}
		if ( false !== $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}
		wp_schedule_single_event( $next, self::CRON_HOOK );
	}

	/**
	 * Forget the queue and the event (module switched off).
	 */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( SocialQueue::OPTION );
	}

	/* ── Facts ── */

	/**
	 * The facts Eligibility decides on.
	 *
	 * @param WP_Post $post      Post.
	 * @param bool    $with_meta Read the noindex post meta too.
	 * @return array<string, mixed>
	 */
	public static function facts_for( WP_Post $post, bool $with_meta ): array {
		$noindex = false;
		if ( $with_meta ) {
			$noindex = Eligibility::meta_says_noindex(
				get_post_meta( $post->ID, 'rank_math_robots', true ),
				get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true )
			);
			/**
			 * Say that a post must not be sent because it is noindex by a rule this plugin cannot see
			 * (an SEO setting per post type, a custom field).
			 *
			 * @param bool    $noindex Whether the post is noindex.
			 * @param WP_Post $post    Post.
			 */
			$noindex = (bool) apply_filters( 'uxstudio_indexing_post_noindex', $noindex, $post );
		}
		return array(
			'blog_public' => (int) get_option( 'blog_public', 1 ),
			'status'      => (string) $post->post_status,
			'viewable'    => function_exists( 'is_post_type_viewable' ) && is_post_type_viewable( $post->post_type ),
			'password'    => '' !== (string) $post->post_password,
			'noindex'     => $noindex,
			'maintenance' => self::maintenance_on(),
			'paused'      => SiteIdentity::is_paused(),
		);
	}

	/**
	 * Is the maintenance or coming soon page shown to visitors?
	 */
	private static function maintenance_on(): bool {
		$modules = Plugin::instance()->modules ?? null;
		if ( null === $modules || ! in_array( 'maintenance-mode', $modules->enabled_ids(), true ) ) {
			return false;
		}
		$module = $modules->instance( 'maintenance-mode' );
		return $module instanceof MaintenanceMode && $module->is_serving();
	}

	/* ── Run ── */

	/**
	 * WP-Cron callback: everything that is due.
	 */
	public function run(): void {
		$limit = 'own' === (string) $this->module->setting( 'social_key_source', 'ca' ) ? SocialQueue::BATCH_OWN : SocialQueue::BATCH_CA;
		$due   = SocialQueue::due( self::load(), time(), $limit );

		$targets = array();
		$skipped = array();
		foreach ( $due as $url => $item ) {
			$post = $item['post_id'] > 0 ? get_post( $item['post_id'] ) : null;
			if ( ! $post instanceof WP_Post ) {
				$skipped[ $url ] = 'gone';
				continue;
			}
			$reason = Eligibility::reason( self::facts_for( $post, true ) );
			if ( '' !== $reason ) {
				$skipped[ $url ] = $reason;
				continue;
			}
			$targets[ $url ] = array(
				'post'  => $post,
				'steps' => $item['steps'],
				'url'   => (string) get_permalink( $post ) ?: $url,
			);
		}

		$done = $this->execute( $targets );

		$queue = self::load();
		$now   = time();
		foreach ( $skipped as $url => $reason ) {
			$queue = SocialQueue::settle( $queue, $url, array( SocialQueue::STEP_SOCIAL => 'drop', SocialQueue::STEP_INDEXNOW => 'drop' ), $now );
		}
		foreach ( $done as $url => $row ) {
			$queue = SocialQueue::settle( $queue, $url, $row['outcomes'], $now );
		}
		self::save( $queue );
		$this->schedule();

		if ( $skipped ) {
			ActivityLog::log( 'indexing-notice', 'sharing_skipped', '', 0, array( 'count' => count( $skipped ), 'reasons' => array_values( array_unique( $skipped ) ) ) );
		}
	}

	/**
	 * Refresh one post at once (row action, meta box): same steps, no queue, no waiting.
	 *
	 * @param WP_Post $post Post.
	 * @return array{ok:bool,reason:string,report:array<string,mixed>}
	 */
	public function refresh_now( WP_Post $post ): array {
		$reason = Eligibility::reason( self::facts_for( $post, true ) );
		if ( '' !== $reason ) {
			return array(
				'ok'     => false,
				'reason' => $reason,
				'report' => array(),
			);
		}
		$url  = (string) get_permalink( $post );
		$done = $this->execute(
			array(
				$url => array(
					'post'  => $post,
					'steps' => $this->steps(),
					'url'   => $url,
				),
			)
		);
		return array(
			'ok'     => true,
			'reason' => '',
			'report' => $done[ $url ]['report'] ?? array(),
		);
	}

	/**
	 * Do the steps for a set of addresses.
	 *
	 * @param array<string, array{post:WP_Post,steps:string[],url:string}> $targets Queue key => target.
	 * @return array<string, array{outcomes:array<string,string|int>,report:array<string,mixed>}>
	 */
	private function execute( array $targets ): array {
		if ( ! $targets ) {
			return array();
		}
		$social_urls = array();
		$index_urls  = array();
		foreach ( $targets as $key => $target ) {
			if ( in_array( SocialQueue::STEP_SOCIAL, $target['steps'], true ) && $this->module->setting( 'social_refresh_enabled', false ) ) {
				$social_urls[ $key ] = $target['url'];
			}
			if ( in_array( SocialQueue::STEP_INDEXNOW, $target['steps'], true ) && $this->indexnow_on() ) {
				$index_urls[ $key ] = $target['url'];
			}
		}
		$social = $social_urls ? $this->refresh_social( $social_urls ) : array();
		$index  = $index_urls ? $this->send_indexnow( $index_urls ) : array();

		$out = array();
		foreach ( $targets as $key => $target ) {
			$outcomes = array();
			$report   = array(
				'time'     => time(),
				'facebook' => array( 'status' => 'off' ),
				'indexnow' => array( 'status' => 'off' ),
			);
			if ( isset( $social[ $key ] ) ) {
				$row                              = $social[ $key ];
				$outcomes[ SocialQueue::STEP_SOCIAL ] = $row['outcome'];
				$report['facebook']               = $row['report'];
			} else {
				$outcomes[ SocialQueue::STEP_SOCIAL ] = 'drop';
			}
			if ( isset( $index[ $key ] ) ) {
				$row                                = $index[ $key ];
				$outcomes[ SocialQueue::STEP_INDEXNOW ] = $row['outcome'];
				$report['indexnow']                 = $row['report'];
			} else {
				$outcomes[ SocialQueue::STEP_INDEXNOW ] = 'drop';
			}
			$out[ $key ] = array(
				'outcomes' => $outcomes,
				'report'   => $report,
			);
			update_post_meta( $target['post']->ID, self::META_KEY, $report );
		}
		$this->log( $targets, $out );
		return $out;
	}

	/**
	 * Is IndexNow switched on and not already done by another plugin?
	 */
	private function indexnow_on(): bool {
		return (bool) $this->module->setting( 'indexnow_enabled', true ) && ! IndexNow::foreign_senders();
	}

	/**
	 * Facebook, through the Central App or with own keys.
	 *
	 * @param array<string, string> $urls Queue key => address.
	 * @return array<string, array{outcome:string|int,report:array<string,mixed>}>
	 */
	private function refresh_social( array $urls ): array {
		return 'own' === (string) $this->module->setting( 'social_key_source', 'ca' )
			? $this->social_own( $urls )
			: $this->social_ca( $urls );
	}

	/**
	 * Mode `ca`: one batch to the Central App.
	 *
	 * @param array<string, string> $urls Queue key => address.
	 * @return array<string, array{outcome:string|int,report:array<string,mixed>}>
	 */
	private function social_ca( array $urls ): array {
		$all = static function ( string $outcome, array $report ) use ( $urls ): array {
			return array_map(
				static fn( string $url ): array => array(
					'outcome' => $outcome,
					'report'  => $report,
				),
				$urls
			);
		};
		if ( ! Gateway::is_paired() ) {
			return $all( 'drop', array( 'status' => 'failed', 'error' => __( 'The site is not connected to the central app.', 'ux-studio' ) ) );
		}
		$result = Gateway::post(
			'social_refresh',
			array(
				'site_url'  => home_url( '/' ),
				'urls'      => array_values( $urls ),
				'timestamp' => time(),
			)
		);
		if ( $result instanceof WP_Error ) {
			if ( 'uxstudio_ca_paused' === $result->get_error_code() ) {
				return $all( 'drop', array( 'status' => 'failed', 'error' => $result->get_error_message() ) );
			}
			return $all( 300, array( 'status' => 'retry', 'error' => $result->get_error_message() ) );
		}
		$code = $result['code'];
		$body = $result['body'];
		if ( 429 === $code ) {
			return $all( max( 60, (int) ( $body['retry_after'] ?? 600 ) ), array( 'status' => 'retry', 'error' => __( 'The central app asked to wait (too many requests).', 'ux-studio' ) ) );
		}
		if ( 409 === $code ) {
			// The address was reported to the Central App (Gateway::inspect); try again shortly.
			return $all( 300, array( 'status' => 'retry', 'error' => __( 'The central app knows this site under another address and is being told about the move.', 'ux-studio' ) ) );
		}
		if ( 410 === $code || 423 === $code ) {
			return $all( 'drop', array( 'status' => 'failed', 'error' => __( 'This site is a copy or was moved, the connection is paused.', 'ux-studio' ) ) );
		}
		if ( 200 !== $code || ! isset( $body['results'] ) || ! is_array( $body['results'] ) ) {
			$permanent = $code >= 400 && $code < 500;
			/* translators: %d: HTTP status code */
			$error = sprintf( __( 'Central app returned HTTP %d.', 'ux-studio' ), $code );
			return $all( $permanent ? 'drop' : 600, array( 'status' => $permanent ? 'failed' : 'retry', 'error' => $error ) );
		}

		$by_url = array();
		foreach ( $body['results'] as $row ) {
			if ( is_array( $row ) && isset( $row['url'] ) ) {
				$by_url[ (string) $row['url'] ] = $row;
			}
		}
		$out = array();
		foreach ( $urls as $key => $url ) {
			$row = $by_url[ $url ] ?? null;
			if ( null === $row ) {
				$out[ $key ] = array(
					'outcome' => 600,
					'report'  => array( 'status' => 'retry', 'error' => __( 'The central app sent no result for this address.', 'ux-studio' ) ),
				);
			} elseif ( ! empty( $row['ok'] ) ) {
				$out[ $key ] = array(
					'outcome' => 'done',
					'report'  => self::ok_report( (string) ( $row['og_title'] ?? '' ), (string) ( $row['og_image'] ?? '' ) ),
				);
			} else {
				$out[ $key ] = array(
					'outcome' => 600,
					'report'  => array( 'status' => 'retry', 'error' => sanitize_text_field( (string) ( $row['error'] ?? '' ) ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Mode `own`: one Graph API call per address, own keys.
	 *
	 * @param array<string, string> $urls Queue key => address.
	 * @return array<string, array{outcome:string|int,report:array<string,mixed>}>
	 */
	private function social_own( array $urls ): array {
		$app_id = (string) $this->module->setting( 'social_fb_app_id', '' );
		$secret = $this->module->fb_secret();
		$out    = array();
		if ( ! FacebookScraper::is_app_id( $app_id ) || '' === $secret ) {
			foreach ( $urls as $key => $url ) {
				$out[ $key ] = array(
					'outcome' => 'drop',
					'report'  => array( 'status' => 'failed', 'error' => __( 'The Facebook app ID or app secret is missing.', 'ux-studio' ) ),
				);
			}
			return $out;
		}
		$wait = false;
		foreach ( $urls as $key => $url ) {
			if ( $wait ) {
				// A rate limit answer applies to the whole app: the rest waits as well.
				$out[ $key ] = array(
					'outcome' => 900,
					'report'  => array( 'status' => 'retry', 'error' => __( 'Facebook asked to wait (too many calls).', 'ux-studio' ) ),
				);
				continue;
			}
			$row = FacebookScraper::scrape( $url, $app_id, $secret );
			if ( $row['ok'] ) {
				$out[ $key ] = array(
					'outcome' => 'done',
					'report'  => self::ok_report( (string) ( $row['og_title'] ?? '' ), (string) ( $row['og_image'] ?? '' ) ),
				);
				continue;
			}
			$wait        = $row['rate'];
			$out[ $key ] = array(
				'outcome' => $row['rate'] ? 900 : 600,
				'report'  => array( 'status' => 'retry', 'error' => (string) ( $row['error'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * A successful Facebook step as the report shows it.
	 *
	 * @param string $title Title Facebook sees.
	 * @param string $image Image Facebook sees.
	 * @return array<string, string>
	 */
	private static function ok_report( string $title, string $image ): array {
		return array(
			'status' => 'ok',
			'title'  => sanitize_text_field( $title ),
			'image'  => esc_url_raw( $image ),
		);
	}

	/**
	 * IndexNow for all addresses in one request.
	 *
	 * @param array<string, string> $urls Queue key => address.
	 * @return array<string, array{outcome:string|int,report:array<string,mixed>}>
	 */
	private function send_indexnow( array $urls ): array {
		$result = IndexNow::submit( array_values( $urls ) );
		if ( $result instanceof WP_Error ) {
			$outcome = 'uxstudio_indexnow_empty' === $result->get_error_code() ? 'drop' : 600;
			$report  = array( 'status' => 'drop' === $outcome ? 'failed' : 'retry', 'error' => $result->get_error_message() );
		} else {
			$report  = array( 'status' => 'ok' === $result['status'] ? 'ok' : ( 'rate' === $result['status'] || 'error' === $result['status'] ? 'retry' : 'failed' ), 'http' => $result['http'] );
			$outcome = 'ok' === $result['status'] ? 'done' : ( 'rate' === $result['status'] ? 900 : ( 'error' === $result['status'] ? 600 : 'drop' ) );
		}
		$out = array();
		foreach ( $urls as $key => $url ) {
			$out[ $key ] = array(
				'outcome' => $outcome,
				'report'  => $report,
			);
		}
		return $out;
	}

	/**
	 * Activity Log: one row per address for a small run, a summary for a big one.
	 *
	 * @param array<string, array{post:WP_Post,steps:string[],url:string}>                         $targets Targets.
	 * @param array<string, array{outcomes:array<string,string|int>,report:array<string,mixed>}> $done    Results.
	 */
	private function log( array $targets, array $done ): void {
		$source = 'own' === (string) $this->module->setting( 'social_key_source', 'ca' ) ? 'own' : 'ca';
		if ( count( $done ) > self::LOG_ROWS ) {
			$counts = array();
			foreach ( $done as $row ) {
				$status            = (string) ( $row['report']['facebook']['status'] ?? 'off' );
				$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
			}
			ActivityLog::log( 'indexing-notice', 'sharing_refresh', '', 0, array( 'addresses' => count( $done ), 'facebook' => $counts, 'source' => $source ) );
			return;
		}
		foreach ( $done as $key => $row ) {
			ActivityLog::log(
				'indexing-notice',
				'sharing_refresh',
				'post',
				(int) $targets[ $key ]['post']->ID,
				array(
					'url'      => $targets[ $key ]['url'],
					'source'   => $source,
					'facebook' => $row['report']['facebook'],
					'indexnow' => $row['report']['indexnow'],
				)
			);
		}
	}
}
