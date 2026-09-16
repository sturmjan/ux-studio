<?php
/**
 * Base REST controller: uniform permissions, responses and rate limiting.
 *
 * @package UxStudio
 */

namespace UxStudio\Rest;

use UxStudio\Core\Security;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * All UX Studio REST controllers extend this. Routes live under uxstudio/v1.
 */
abstract class Controller {

	public const NS = 'uxstudio/v1';

	/**
	 * Register routes. Implementations call $this->route().
	 */
	abstract public function register_routes(): void;

	/**
	 * Register one route with uniform permission handling.
	 *
	 * @param string $path       Route path (e.g. '/modules').
	 * @param string $methods    HTTP methods.
	 * @param callable $callback Handler.
	 * @param array  $args       Declared args with sanitize/validate callbacks.
	 * @param string $capability Required capability.
	 * @param bool   $counts_as_write Whether a non-GET call should spend the
	 *                                per-user write budget. Set false ONLY for
	 *                                routes that are POST purely because they
	 *                                take a large payload but write nothing —
	 *                                see route_readonly().
	 */
	protected function route( string $path, string $methods, callable $callback, array $args = array(), string $capability = 'manage_options', bool $counts_as_write = true ): void {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => function ( WP_REST_Request $request ) use ( $callback, $methods, $counts_as_write ) {
					if ( $counts_as_write && 'GET' !== $methods && ! Security::check_write_rate_limit() ) {
						return new WP_Error( 'uxstudio_rate_limited', __( 'Too many requests, slow down.', 'ux-studio' ), array( 'status' => 429 ) );
					}
					return $callback( $request );
				},
				'permission_callback' => static fn (): bool => current_user_can( $capability ),
				'args'                => $args,
			)
		);
	}

	/**
	 * POST routa, která nic nezapisuje — jen počítá/analyzuje nad velkým
	 * payloadem, na který se GET nehodí (celý obsah článku v query stringu).
	 *
	 * Vlastní `Security::check_write_rate_limit()` je sdílený rozpočet 60
	 * zápisů/min NA UŽIVATELE. Živý panel v editoru, který se přepočítává při
	 * psaní, ho dokáže vyčerpat sám — a pak přestanou procházet i SKUTEČNÉ
	 * zápisy uživatele, včetně uložení článku. Proto tyhle routy rozpočet
	 * neutrácejí; přístup jim pořád hlídá `permission_callback`.
	 *
	 * NEPOUŽÍVAT na nic, co zapisuje nebo stojí peníze (AI tokeny) — tam
	 * limit dává smysl a musí zůstat.
	 *
	 * @param array $args Declared args with sanitize/validate callbacks.
	 */
	protected function route_readonly( string $path, callable $callback, array $args = array(), string $capability = 'manage_options' ): void {
		$this->route( $path, 'POST', $callback, $args, $capability, false );
	}

	/**
	 * Uniform success envelope.
	 *
	 * @param mixed $data Payload.
	 * @param array $meta Optional metadata (pagination etc.).
	 */
	protected function ok( $data, array $meta = array() ): WP_REST_Response {
		return new WP_REST_Response( array( 'data' => $data, 'meta' => $meta ) );
	}
}
