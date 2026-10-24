<?php
/**
 * Redirects REST controller (admin SPA).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

use UxStudio\Core\ActivityLog;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/redirects/rules             - paged list
 * POST   uxstudio/v1/redirects/rules             - bulk upsert {items, dry_run, on_conflict}
 * POST   uxstudio/v1/redirects/rules/(?P<id>\d+) - partial update of one rule
 * POST   uxstudio/v1/redirects/rules/reset-hits  - zero the hit counters {ids} or {all: true}
 * DELETE uxstudio/v1/redirects/rules             - delete {ids}
 * POST   uxstudio/v1/redirects/test              - resolve URLs {urls, live}
 * POST   uxstudio/v1/redirects/audit             - audit {live, fix_chains}
 * GET    uxstudio/v1/redirects/404               - aggregated 404 log
 * DELETE uxstudio/v1/redirects/404               - delete {ids} or {all}
 * POST   uxstudio/v1/redirects/import/csv        - {csv, dry_run, on_conflict}
 * POST   uxstudio/v1/redirects/import/redirection - {dry_run, on_conflict}
 * GET    uxstudio/v1/redirects/export            - CSV text
 * GET    uxstudio/v1/redirects/site-urls         - current site inventory
 * GET    uxstudio/v1/redirects/find-target       - candidates for an old URL
 *
 * The same operations are exposed to AI agents as abilities (Abilities.php).
 */
final class RestController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/redirects/rules', 'GET', array( $this, 'list_rules' ) );
		$this->route( '/redirects/rules', 'POST', array( $this, 'upsert' ) );
		$this->route( '/redirects/rules/(?P<id>\d+)', 'POST', array( $this, 'update_one' ) );
		$this->route( '/redirects/rules/reset-hits', 'POST', array( $this, 'reset_hits' ) );
		$this->route( '/redirects/rules', 'DELETE', array( $this, 'delete_rules' ) );
		$this->route_readonly( '/redirects/test', array( $this, 'test' ) );
		$this->route( '/redirects/audit', 'POST', array( $this, 'audit' ) );
		$this->route( '/redirects/404', 'GET', array( $this, 'list_404' ) );
		$this->route( '/redirects/404', 'DELETE', array( $this, 'delete_404' ) );
		$this->route( '/redirects/import/csv', 'POST', array( $this, 'import_csv' ) );
		$this->route( '/redirects/import/redirection', 'POST', array( $this, 'import_redirection' ) );
		$this->route( '/redirects/import/redirection', 'GET', array( $this, 'redirection_status' ) );
		$this->route( '/redirects/export', 'GET', array( $this, 'export' ) );
		$this->route( '/redirects/site-urls', 'GET', array( $this, 'site_urls' ) );
		$this->route( '/redirects/find-target', 'GET', array( $this, 'find_target' ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_rules( WP_REST_Request $request ) {
		$result = Repository::list( $request->get_params() );
		return $this->ok( $result['items'], array( 'total' => $result['total'] ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function upsert( WP_REST_Request $request ) {
		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) || ! $items ) {
			return new WP_Error( 'uxstudio_redirects_items', __( 'items must be a non-empty array.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$dry    = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );
		$result = Repository::bulk( $items, $this->conflict( $request ), $dry, 'manual' );
		if ( ! $dry ) {
			ActivityLog::log( 'redirects', 'upsert', '', 0, $result['summary'] );
		}
		return $this->ok( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function update_one( WP_REST_Request $request ) {
		$input       = (array) $request->get_json_params();
		$input['id'] = (int) $request['id'];
		$result      = Repository::save( $input, 'error' );
		if ( 'error' === $result['status'] ) {
			return new WP_Error( 'uxstudio_redirects_invalid', (string) $result['errors'][0]['message'], array( 'status' => 400, 'result' => $result ) );
		}
		return $this->ok( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_rules( WP_REST_Request $request ) {
		$deleted = Repository::delete( (array) $request->get_param( 'ids' ) );
		ActivityLog::log( 'redirects', 'delete', '', 0, array( 'deleted' => $deleted ) );
		return $this->ok( array( 'deleted' => $deleted ) );
	}

	/**
	 * Zero the hit counters of some rules ({ids}) or of all of them ({all: true}).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reset_hits( WP_REST_Request $request ) {
		$all = rest_sanitize_boolean( $request->get_param( 'all' ) ?? false );
		$ids = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) );
		if ( ! $all && ! $ids ) {
			return new WP_Error( 'uxstudio_redirects_ids', __( 'Pass ids, or all=true to reset every counter.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$reset = Repository::reset_hits( $all ? null : $ids );
		ActivityLog::log( 'redirects', 'reset_hits', '', 0, array( 'reset' => $reset, 'all' => $all ) );
		return $this->ok( array( 'reset' => $reset ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function test( WP_REST_Request $request ) {
		$live = rest_sanitize_boolean( $request->get_param( 'live' ) ?? false );
		$urls = array_slice( array_filter( array_map( 'strval', (array) $request->get_param( 'urls' ) ) ), 0, 50 );
		$visitor = $request->get_param( 'visitor' );
		$visitor = is_array( $visitor ) ? $visitor : null;
		return $this->ok( array_map( static fn ( string $url ): array => Tester::test( $url, $live, $visitor ), $urls ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function audit( WP_REST_Request $request ) {
		return $this->ok(
			Tester::audit(
				rest_sanitize_boolean( $request->get_param( 'live' ) ?? false ),
				max( 1, min( 500, (int) ( $request->get_param( 'live_limit' ) ?? 50 ) ) ),
				rest_sanitize_boolean( $request->get_param( 'fix_chains' ) ?? false )
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_404( WP_REST_Request $request ) {
		$result = NotFoundLog::list( $request->get_params() );
		return $this->ok( $result['items'], array( 'total' => $result['total'] ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_404( WP_REST_Request $request ) {
		return $this->ok(
			array( 'deleted' => NotFoundLog::delete( (array) $request->get_param( 'ids' ), rest_sanitize_boolean( $request->get_param( 'all' ) ?? false ) ) )
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function import_csv( WP_REST_Request $request ) {
		$csv = (string) $request->get_param( 'csv' );
		if ( '' === trim( $csv ) ) {
			return new WP_Error( 'uxstudio_redirects_csv', __( 'CSV is empty.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$dry    = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );
		$result = Importer::csv( $csv, $this->conflict( $request ), $dry );
		if ( ! $dry ) {
			ActivityLog::log( 'redirects', 'import_csv', '', 0, $result['summary'] );
		}
		return $this->ok( $result );
	}

	/**
	 * Whether Redirection data exists to import.
	 */
	public function redirection_status() {
		return $this->ok( array( 'available' => Importer::redirection_available() ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function import_redirection( WP_REST_Request $request ) {
		$dry    = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );
		$result = Importer::redirection( $this->conflict( $request ), $dry );
		if ( ! $dry ) {
			ActivityLog::log( 'redirects', 'import_redirection', '', 0, $result['summary'] );
		}
		return $this->ok( $result );
	}

	/**
	 * CSV export (as text inside the JSON envelope; the SPA saves it as a file).
	 */
	public function export() {
		return $this->ok( array( 'csv' => Importer::export_csv() ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function site_urls( WP_REST_Request $request ) {
		$result = SiteIndex::urls( $request->get_params() );
		return $this->ok( $result['items'], array( 'total' => $result['total'] ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function find_target( WP_REST_Request $request ) {
		return $this->ok(
			SiteIndex::candidates(
				(string) $request->get_param( 'url' ),
				sanitize_text_field( (string) $request->get_param( 'title' ) ),
				(int) ( $request->get_param( 'limit' ) ?? 5 )
			)
		);
	}

	/**
	 * on_conflict param with a safe default.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function conflict( WP_REST_Request $request ): string {
		$value = (string) $request->get_param( 'on_conflict' );
		return in_array( $value, array( 'update', 'skip', 'error' ), true ) ? $value : 'update';
	}
}
