<?php
/**
 * JSON-RPC / MCP protocol logic (transport independent).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Handles one JSON-RPC message of an authenticated caller: initialize, ping, tools/*,
 * resources/*, prompts/*. Stateless (no sessions), so any request can hit any PHP worker.
 */
final class Protocol {

	public const VERSIONS = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	public const ERR_PARSE     = -32700;
	public const ERR_REQUEST   = -32600;
	public const ERR_METHOD    = -32601;
	public const ERR_PARAMS    = -32602;
	public const ERR_INTERNAL  = -32603;
	public const ERR_SCOPE     = -32003;

	/** @var array<string, mixed> */
	private array $caller;

	/** @var callable */
	private $logger;

	/**
	 * @param array<string, mixed> $caller id, label, scopes, ip.
	 * @param callable|null        $logger fn( string $action, array $meta ): void.
	 */
	public function __construct( array $caller, ?callable $logger = null ) {
		$this->caller = $caller;
		$this->logger = $logger ?? static function ( string $action, array $meta ): void {
			ActivityLog::log( 'mcp', $action, 'ability', 0, $meta );
		};
	}

	/**
	 * Handle one message.
	 *
	 * @param mixed $msg Decoded JSON message.
	 * @return array{response:?array<string, mixed>,status:int}
	 */
	public function handle( $msg ): array {
		if ( ! is_array( $msg ) || '2.0' !== ( $msg['jsonrpc'] ?? '' ) || ! isset( $msg['method'] ) || ! is_string( $msg['method'] ) ) {
			// A response to a request of ours (we never send any) or garbage.
			if ( is_array( $msg ) && ( array_key_exists( 'result', $msg ) || array_key_exists( 'error', $msg ) ) ) {
				return array( 'response' => null, 'status' => 202 );
			}
			return array( 'response' => self::error( null, self::ERR_REQUEST, 'Invalid JSON-RPC request.' ), 'status' => 400 );
		}

		$has_id = array_key_exists( 'id', $msg );
		$id     = $has_id ? $msg['id'] : null;
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		if ( ! $has_id ) {
			// Notification (notifications/initialized, notifications/cancelled, ...): no answer.
			return array( 'response' => null, 'status' => 202 );
		}

		switch ( $msg['method'] ) {
			case 'initialize':
				return $this->ok( $id, $this->initialize( $params ) );
			case 'ping':
				return $this->ok( $id, new \stdClass() );
			case 'tools/list':
				return $this->ok( $id, array( 'tools' => $this->tools_list() ) );
			case 'tools/call':
				return $this->tools_call( $id, $params );
			case 'resources/list':
				return $this->ok( $id, array( 'resources' => $this->resources_list() ) );
			case 'resources/templates/list':
				return $this->ok( $id, array( 'resourceTemplates' => $this->resource_templates() ) );
			case 'resources/read':
				return $this->resources_read( $id, $params );
			case 'prompts/list':
				return $this->ok( $id, array( 'prompts' => Prompts::list() ) );
			case 'prompts/get':
				$prompt = Prompts::get( (string) ( $params['name'] ?? '' ), (array) ( $params['arguments'] ?? array() ) );
				if ( null === $prompt ) {
					return $this->fail( $id, self::ERR_PARAMS, 'Unknown prompt.' );
				}
				return $this->ok( $id, $prompt );
		}
		return $this->fail( $id, self::ERR_METHOD, 'Method not found: ' . $msg['method'] );
	}

	/**
	 * `initialize` result.
	 *
	 * @param array<string, mixed> $params Params.
	 * @return array<string, mixed>
	 */
	private function initialize( array $params ): array {
		$asked   = (string) ( $params['protocolVersion'] ?? '' );
		$version = in_array( $asked, self::VERSIONS, true ) ? $asked : self::VERSIONS[0];
		return array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array( 'subscribe' => false, 'listChanged' => false ),
				'prompts'   => array( 'listChanged' => false ),
			),
			'serverInfo'      => array(
				'name'    => 'UX Studio',
				'title'   => 'UX Studio for WordPress',
				'version' => defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '0',
			),
			'instructions'    => 'UX Studio manages modules, settings and migrations from other plugins on this WordPress site. Start with uxstudio-site-overview. Tools you can call depend on the scopes of your token. Destructive tools and the migration switch need confirm=true, which you set only after the user agreed; the prompt migrate-from-plugins describes the whole migration flow. Secrets are never returned.',
		);
	}

	/**
	 * Tools this caller may call.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function tools_list(): array {
		$tools = array();
		foreach ( Registry::visible( (array) $this->caller['scopes'] ) as $def ) {
			$ann = (array) $def['annotations'];
			$tools[] = array(
				'name'        => $def['tool'],
				'title'       => $def['label'],
				'description' => $def['description'] . ' [scope: ' . $def['scope'] . ']',
				'inputSchema' => $def['input_schema'],
				'annotations' => array(
					'title'           => $def['label'],
					'readOnlyHint'    => ! empty( $ann['readonly'] ),
					'destructiveHint' => ! empty( $ann['destructive'] ),
					'idempotentHint'  => ! empty( $ann['idempotent'] ),
					'openWorldHint'   => false,
				),
			);
		}
		return $tools;
	}

	/**
	 * `tools/call`.
	 *
	 * @param mixed                $id     Request id.
	 * @param array<string, mixed> $params Params.
	 * @return array{response:?array<string, mixed>,status:int}
	 */
	private function tools_call( $id, array $params ): array {
		$name = (string) ( $params['name'] ?? '' );
		$args = $params['arguments'] ?? array();
		if ( ! is_array( $args ) ) {
			return $this->fail( $id, self::ERR_PARAMS, 'arguments must be an object.' );
		}
		$def = Registry::find_tool( $name );
		if ( ! $def ) {
			return $this->fail( $id, self::ERR_PARAMS, 'Unknown tool: ' . $name );
		}

		if ( ! Scopes::allows( (array) $this->caller['scopes'], (string) $def['scope'] ) ) {
			$this->log( 'tool_denied', array( 'tool' => $name, 'required_scope' => $def['scope'] ) );
			$out = self::error(
				$id,
				self::ERR_SCOPE,
				sprintf( 'This token has no "%s" scope, which "%s" needs.', $def['scope'], $name ),
				array( 'required_scope' => $def['scope'], 'tool' => $name )
			);
			return array( 'response' => $out, 'status' => 403 );
		}

		$meta = array( 'tool' => $name, 'args' => self::describe_args( $args ) );
		try {
			$result = Registry::invoke( $def, $args );
			$this->log( 'tool_call', $meta + array( 'ok' => true ) );
			return $this->ok( $id, self::content( Secrets::scrub( $result ), false ) );
		} catch ( McpError $e ) {
			$this->log( 'tool_call', $meta + array( 'ok' => false, 'error' => $e->error_code() ) );
			return $this->ok( $id, self::content( Secrets::scrub( $e->to_array() ), true ) );
		} catch ( \Throwable $e ) {
			$this->log( 'tool_call', $meta + array( 'ok' => false, 'error' => 'exception', 'exception' => get_class( $e ) ) );
			$error = new McpError( 'internal_error', 'The tool failed unexpectedly. See the PHP error log of the site.' );
			return $this->ok( $id, self::content( $error->to_array(), true ) );
		}
	}

	/**
	 * Resources this caller can read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function resources_list(): array {
		$list = array(
			array(
				'uri'         => 'uxstudio://modules',
				'name'        => 'modules',
				'title'       => 'UX Studio modules',
				'description' => 'All modules with their enabled state.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'uxstudio://overlaps',
				'name'        => 'overlaps',
				'title'       => 'Plugin overlaps',
				'description' => 'Plugins that do the same job as an enabled module.',
				'mimeType'    => 'application/json',
			),
		);
		foreach ( Bridge::ai_resources() as $res ) {
			$list[] = array(
				'uri'         => $res['uri'],
				'name'        => basename( $res['uri'] ),
				'title'       => $res['name'],
				'description' => $res['description'],
				'mimeType'    => 'application/json',
			);
		}
		return $list;
	}

	/**
	 * Resource templates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function resource_templates(): array {
		return array(
			array(
				'uriTemplate' => 'uxstudio://migration/run/{id}',
				'name'        => 'migration-run',
				'title'       => 'Migration run',
				'description' => 'One migration run: result, state, whether it can be rolled back or was switched on.',
				'mimeType'    => 'application/json',
			),
		);
	}

	/**
	 * `resources/read`.
	 *
	 * @param mixed                $id     Request id.
	 * @param array<string, mixed> $params Params.
	 * @return array{response:?array<string, mixed>,status:int}
	 */
	private function resources_read( $id, array $params ): array {
		if ( ! Scopes::allows( (array) $this->caller['scopes'], Scopes::READ ) ) {
			return array(
				'response' => self::error( $id, self::ERR_SCOPE, 'This token has no "read" scope.', array( 'required_scope' => Scopes::READ ) ),
				'status'   => 403,
			);
		}
		$uri = (string) ( $params['uri'] ?? '' );
		try {
			if ( 'uxstudio://modules' === $uri ) {
				$data = CoreAbilities::modules_list( array() );
			} elseif ( 'uxstudio://overlaps' === $uri ) {
				$data = CoreAbilities::overlaps_list();
			} elseif ( 1 === preg_match( '#^uxstudio://migration/run/([A-Za-z0-9_-]{3,64})$#', $uri, $m ) ) {
				$data = $this->migration_run( $m[1] );
			} else {
				$data = $this->ai_resource( $uri );
			}
		} catch ( McpError $e ) {
			return $this->fail( $id, self::ERR_PARAMS, $e->getMessage(), array( 'code' => $e->error_code() ) );
		} catch ( \Throwable $e ) {
			return $this->fail( $id, self::ERR_INTERNAL, 'The resource could not be read.' );
		}
		$this->log( 'resource_read', array( 'uri' => $uri ) );
		return $this->ok(
			$id,
			array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => 'application/json',
						'text'     => (string) wp_json_encode( Secrets::scrub( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					),
				),
			)
		);
	}

	/**
	 * One migration run for the resource.
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>
	 * @throws McpError When the run does not exist.
	 */
	private function migration_run( string $run_id ): array {
		$runs = (array) ( (array) Internal::data( 'GET', '/migration/runs' ) )['runs'];
		foreach ( $runs as $run ) {
			if ( (string) ( $run['id'] ?? '' ) === $run_id ) {
				$status = (array) Internal::data( 'GET', '/migration/status' );
				$current = isset( $status['run']['id'] ) && $status['run']['id'] === $run_id;
				return array(
					'run'      => $run,
					'state'    => $current ? $status['run'] : null,
					'staging'  => $current ? ( $status['staging'] ?? null ) : null,
				);
			}
		}
		throw new McpError( 'not_found', 'No migration run with this id.' );
	}

	/**
	 * Read an AI Assistant resource by uri.
	 *
	 * @param string $uri Resource uri.
	 * @return mixed
	 * @throws McpError When unknown.
	 */
	private function ai_resource( string $uri ) {
		foreach ( Bridge::ai_resources() as $res ) {
			if ( $res['uri'] === $uri ) {
				return Bridge::run_wp_ability( $res['ability'] );
			}
		}
		throw new McpError( 'not_found', 'Unknown resource.' );
	}

	/**
	 * Tool result envelope.
	 *
	 * @param mixed $data     Result.
	 * @param bool  $is_error Tool level error.
	 * @return array<string, mixed>
	 */
	public static function content( $data, bool $is_error ): array {
		$items = array();
		if ( is_array( $data ) && $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			$texts = array_column( array_filter( $data, 'is_array' ), 'text' );
			if ( count( $texts ) === count( $data ) ) {
				foreach ( $texts as $text ) {
					$items[] = array( 'type' => 'text', 'text' => (string) $text );
				}
			}
		}
		if ( ! $items ) {
			$items[] = array(
				'type' => 'text',
				'text' => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ),
			);
		}
		$out = array( 'content' => $items );
		if ( $is_error ) {
			$out['isError'] = true;
		}
		return $out;
	}

	/**
	 * What to write to the log about the arguments: names only, plus the harmless flags.
	 * Values (they may be content or credentials) are never logged.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public static function describe_args( array $args ): array {
		$out = array( 'keys' => array_slice( array_map( 'strval', array_keys( $args ) ), 0, 30 ) );
		foreach ( array( 'dry_run', 'confirm', 'override', 'ack_login', 'allow_secrets', 'continue' ) as $flag ) {
			if ( array_key_exists( $flag, $args ) ) {
				$out[ $flag ] = (bool) $args[ $flag ];
			}
		}
		foreach ( array( 'module', 'run_id', 'mode', 'check' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_string( $args[ $key ] ) ) {
				$out[ $key ] = substr( preg_replace( '/[^A-Za-z0-9_:\-]/', '', $args[ $key ] ), 0, 64 );
			}
		}
		if ( isset( $args['sources'] ) && is_array( $args['sources'] ) ) {
			$out['sources'] = array_slice( array_map( static fn( $s ): string => substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $s ), 0, 48 ), $args['sources'] ), 0, 30 );
		}
		return $out;
	}

	/**
	 * Write to the Activity Log with the caller's token id.
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $meta   Meta.
	 */
	private function log( string $action, array $meta ): void {
		( $this->logger )(
			$action,
			$meta + array(
				'token_id' => (string) $this->caller['id'],
				'label' => (string) $this->caller['label'],
				'ip'    => (string) ( $this->caller['ip'] ?? '' ),
			)
		);
	}

	/**
	 * Success response.
	 *
	 * @param mixed $id     Id.
	 * @param mixed $result Result.
	 * @return array{response:array<string, mixed>,status:int}
	 */
	private function ok( $id, $result ): array {
		return array(
			'response' => array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ),
			'status'   => 200,
		);
	}

	/**
	 * Protocol error response (HTTP 200).
	 *
	 * @param mixed                     $id      Id.
	 * @param int                       $code    JSON-RPC code.
	 * @param string                    $message Message.
	 * @param array<string, mixed>|null $data    Data.
	 * @return array{response:array<string, mixed>,status:int}
	 */
	private function fail( $id, int $code, string $message, ?array $data = null ): array {
		return array( 'response' => self::error( $id, $code, $message, $data ), 'status' => 200 );
	}

	/**
	 * Build an error object.
	 *
	 * @param mixed                     $id      Id.
	 * @param int                       $code    Code.
	 * @param string                    $message Message.
	 * @param array<string, mixed>|null $data    Data.
	 * @return array<string, mixed>
	 */
	public static function error( $id, int $code, string $message, ?array $data = null ): array {
		$error = array( 'code' => $code, 'message' => $message );
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => $error );
	}
}
