<?php
/**
 * Generic wrapper that registers an existing WP REST route (wp/v2, wc/v3 -
 * never uxstudio/v1) as an MCP "ability" via wp_register_ability(). Purely
 * declarative: derives its JSON schema from the wrapped route's registered
 * args and forwards execution through rest_do_request() - no bespoke CRUD
 * logic lives here or in any subclass.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Core\Mcp\Auth;
use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

abstract class RestEndpointTool {

	protected const CATEGORY = 'ai-assistant';

	/**
	 * @return RestOperationConfig[]
	 */
	abstract protected function get_operations(): array;

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->get_operations() as $config ) {
			$this->register_operation( $config );
		}
	}

	protected function register_operation( RestOperationConfig $config ): void {
		$input_schema = $this->build_input_schema( $config );
		if ( null === $input_schema ) {
			// The wrapped route (or its method) is not registered, e.g. WooCommerce is inactive: without
			// its argument list the schema could not refuse unknown input, so there is no tool.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
				error_log( sprintf( 'UX Studio MCP: tool %s not registered, route %s %s not found.', $config->operation_id, $config->method, '/' . $config->namespace . $config->route ) );
			}
			return;
		}

		$is_destructive = in_array( $config->method, array( 'DELETE' ), true );
		$is_readonly    = in_array( $config->method, array( 'GET' ), true );

		wp_register_ability(
			'ai-assistant/' . $config->operation_id,
			array(
				'label'               => $config->label,
				'description'         => $config->description,
				'category'            => self::CATEGORY,
				'execute_callback'    => function ( array $input ) use ( $config ) {
					return $this->execute_rest_request( $config, $input );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => $input_schema,
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => $config->type,
					),
					'annotations'  => array(
						'title'           => $config->label,
						'readonly'        => $is_readonly,
						'destructiveHint' => $is_destructive,
					),
				),
			)
		);
	}

	/**
	 * JSON schema of a tool from the args of the wrapped route.
	 *
	 * @param RestOperationConfig $config Operation.
	 * @return array<string, mixed>|null Null when the route and method are not registered.
	 */
	protected function build_input_schema( RestOperationConfig $config ): ?array {
		$server = rest_get_server();
		$routes = $server->get_routes( $config->namespace );

		$schema = array(
			'type'       => 'object',
			'properties' => array(),
		);

		$route_pattern = '/' . $config->namespace . $config->route;
		$matched       = false;

		foreach ( $routes as $pattern => $handlers ) {
			if ( ! $this->route_matches( $route_pattern, $pattern ) ) {
				continue;
			}

			preg_match_all( '/\(\?P<(\w+)>[^)]+\)/', $pattern, $url_params );
			foreach ( $url_params[1] as $param_name ) {
				$schema['properties'][ $param_name ] = array(
					'type'        => 'string',
					/* translators: %s: URL parameter name. */
					'description' => sprintf( __( 'URL parameter: %s', 'ux-studio' ), $param_name ),
				);
			}

			foreach ( $handlers as $handler ) {
				if ( ! isset( $handler['methods'] ) || ! $this->method_matches( $config->method, $handler['methods'] ) ) {
					continue;
				}
				$matched = true;

				if ( ! empty( $handler['args'] ) ) {
					foreach ( $handler['args'] as $arg_name => $arg_def ) {
						if ( isset( $arg_def['readonly'] ) && $arg_def['readonly'] ) {
							continue;
						}
						$prop = $this->arg_to_schema_property( $arg_name, $arg_def );
						if ( $prop ) {
							$schema['properties'][ $arg_name ] = $prop;
						}
					}
				}

				break;
			}
			break;
		}

		if ( ! $matched ) {
			return null;
		}

		foreach ( $config->input_schema_modifications as $key => $modification ) {
			if ( null === $modification ) {
				unset( $schema['properties'][ $key ] );
			} else {
				$schema['properties'][ $key ] = $modification;
			}
		}

		// URL parameters: required, and a string one must match the route regex (the real check is
		// in build_route(), this tells the client up front).
		$required = array();
		foreach ( self::url_params( $config->route ) as $param_name => $regex ) {
			$required[] = $param_name;
			if ( ! isset( $schema['properties'][ $param_name ] ) ) {
				$schema['properties'][ $param_name ] = array( 'type' => 'string' );
			}
			if ( 'string' === ( $schema['properties'][ $param_name ]['type'] ?? '' ) ) {
				$schema['properties'][ $param_name ]['pattern'] = '^(?:' . $regex . ')$';
			}
		}
		if ( $required ) {
			$schema['required'] = $required;
		}
		// The arguments of the wrapped route are known: nothing else may be sent.
		$schema['additionalProperties'] = false;

		return $schema;
	}

	/**
	 * Named URL parameters of a route and their regex, e.g. `/posts/(?P<id>[\d]+)` => id => `[\d]+`.
	 *
	 * @param string $route Route pattern.
	 * @return array<string, string>
	 */
	public static function url_params( string $route ): array {
		preg_match_all( '/\(\?P<(\w+)>([^)]+)\)/', $route, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $match ) {
			$out[ $match[1] ] = $match[2];
		}
		return $out;
	}

	/**
	 * Put the URL parameters of `$input` into the route. Every value must be a plain string or
	 * integer that matches the regex of its named group AND `^[A-Za-z0-9_-]+$`, so `batch`,
	 * `1/application-passwords`, `..`, `?` or `#` can never reach another route than the one
	 * the tool wraps.
	 *
	 * @param string               $route Route pattern (`/products/(?P<id>[\d]+)`).
	 * @param array<string, mixed> $input Tool input.
	 * @return array{route:string,input:array<string, mixed>}|\WP_Error Route and the remaining input.
	 */
	public static function build_route( string $route, array $input ) {
		$params = self::url_params( $route );
		foreach ( $params as $name => $regex ) {
			$value = $input[ $name ] ?? null;
			if ( is_int( $value ) ) {
				$value = (string) $value;
			}
			if ( ! is_string( $value ) || '' === $value ) {
				/* translators: %s: URL parameter name. */
				return new \WP_Error( 'invalid_input', sprintf( __( 'The URL parameter %s is required.', 'ux-studio' ), $name ), array( 'status' => 400 ) );
			}
			$own = '~^(?:' . str_replace( '~', '\~', $regex ) . ')$~D';
			if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) || ! preg_match( $own, $value ) ) {
				/* translators: %s: URL parameter name. */
				return new \WP_Error( 'invalid_input', sprintf( __( 'The URL parameter %s has an invalid value.', 'ux-studio' ), $name ), array( 'status' => 400 ) );
			}
			$params[ $name ] = $value;
			unset( $input[ $name ] );
		}
		$built = preg_replace_callback(
			'/\(\?P<(\w+)>[^)]+\)/',
			static fn( array $m ): string => $params[ $m[1] ],
			$route
		);
		return array(
			'route' => (string) $built,
			'input' => $input,
		);
	}

	/**
	 * Protected (`_`-prefixed) post meta a token without `admin` may still write in a wp/v2 `meta`
	 * object: plain-text SEO fields (Yoast, UX Studio SEO panel). Their values must be strings and
	 * go through sanitize_text_field(), so no markup reaches them either.
	 *
	 * @var string[]
	 */
	public const TEXT_META_ALLOWLIST = array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'_uxstudio_ai_seo_title',
		'_uxstudio_ai_seo_description',
		'_uxstudio_ai_seo_focus_keyword',
	);

	/**
	 * Registered meta that core already kses-filters for a user without `unfiltered_html`
	 * (`footnotes` is JSON: running wp_kses_post() over the raw string would break it).
	 *
	 * @var string[]
	 */
	public const CORE_FILTERED_META = array( 'footnotes' );

	/**
	 * Meta object type of a wrapped route, for is_protected_meta(): wp/v2 users, comments and
	 * taxonomy routes have their own meta; WooCommerce orders are `order` (with HPOS their meta
	 * lives in wc_orders_meta, not in postmeta) and customers `user`; everything else (posts,
	 * pages, media, custom post types, WooCommerce products and coupons) is post meta.
	 *
	 * @param string $namespace REST namespace.
	 * @param string $route     Route within the namespace.
	 */
	public static function meta_type( string $namespace, string $route ): string {
		$base = explode( '/', trim( $route, '/' ) )[0] ?? '';
		if ( 0 === strpos( $namespace, 'wc/' ) || 'wc' === $namespace ) {
			if ( 'orders' === $base ) {
				return 'order';
			}
			return 'customers' === $base ? 'user' : 'post';
		}
		if ( 'wp/v2' !== $namespace ) {
			return 'post';
		}
		if ( 'users' === $base ) {
			return 'user';
		}
		if ( 'comments' === $base ) {
			return 'comment';
		}
		if ( in_array( $base, array( 'categories', 'tags' ), true ) ) {
			return 'term';
		}
		if ( function_exists( 'get_taxonomies' ) ) {
			foreach ( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ) as $taxonomy ) {
				if ( $base === ( $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name ) ) {
					return 'term';
				}
			}
		}
		return 'post';
	}

	/**
	 * A restricted MCP token (no admin scope, so no `unfiltered_html`, see Auth::restricted()) may
	 * only send limited meta through a tool, because registered meta is stored as it comes and
	 * builders/themes print it:
	 *
	 * - wp/v2 `meta` (posts, pages, terms, users, comments): no protected key (e.g. Elementor's
	 *   `_elementor_data` / `_elementor_edit_mode`, which have no sanitize callback and would put a
	 *   <script> on the frontend) except the plain-text TEXT_META_ALLOWLIST; string values of the
	 *   other keys are run through wp_kses_post() (CORE_FILTERED_META is left to core).
	 * - WooCommerce `meta_data`: no protected key, values through wp_kses_post(). An item with an
	 *   `id` updates that meta row and renames it to `key`, so the row's current key may not be
	 *   protected either; on order routes (HPOS meta table) an `id` is refused.
	 * - Every key must be plain ASCII (plain_meta_key()).
	 *
	 * A protected key sent with the value already stored (a client sending back what GET returned)
	 * is dropped silently, it would write nothing; a changed one refuses the whole request and the
	 * error lists the keys.
	 *
	 * Anybody else (an admin-scope token, a logged-in user) is left to core and the route, as before
	 * these checks existed, also without `unfiltered_html` (multisite, DISALLOW_UNFILTERED_HTML):
	 * the guard closes the gap between a write token and its owner's capabilities, it adds no
	 * refusals to callers that act with their full capabilities anyway.
	 *
	 * @param array<string, mixed> $input     Body parameters.
	 * @param string               $meta_type Meta object type (post, term, user, comment).
	 * @param int                  $object_id Object the request updates (0 when it creates one).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function guard_meta( array $input, string $meta_type = 'post', int $object_id = 0 ) {
		if ( ( ! isset( $input['meta'] ) && ! isset( $input['meta_data'] ) ) || ! Auth::restricted() ) {
			return $input;
		}
		$refused = array();

		if ( isset( $input['meta'] ) ) {
			$meta = is_object( $input['meta'] ) ? get_object_vars( $input['meta'] ) : $input['meta'];
			if ( ! is_array( $meta ) ) {
				return new \WP_Error( 'invalid_input', __( 'meta must be an object of key => value.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			foreach ( $meta as $key => $value ) {
				$key = (string) $key;
				if ( ! self::plain_meta_key( $key ) ) {
					$refused[] = '' === $key ? '""' : $key;
					continue;
				}
				if ( 'post' === $meta_type && in_array( $key, self::TEXT_META_ALLOWLIST, true ) ) {
					if ( null !== $value && ! is_string( $value ) ) {
						$refused[] = $key;
						continue;
					}
					$meta[ $key ] = null === $value ? null : sanitize_text_field( $value );
					continue;
				}
				if ( '' === $key || is_protected_meta( $key, $meta_type ) ) {
					if ( '' !== $key && $object_id > 0 && self::same_meta( $value, self::stored_meta( $meta_type, $object_id, $key ) ) ) {
						unset( $meta[ $key ] );
					} else {
						$refused[] = '' === $key ? '""' : $key;
					}
					continue;
				}
				if ( ! in_array( $key, self::CORE_FILTERED_META, true ) ) {
					$meta[ $key ] = self::kses_deep( $value );
				}
			}
			$input['meta'] = $meta;
		}

		if ( isset( $input['meta_data'] ) ) {
			if ( ! is_array( $input['meta_data'] ) ) {
				return new \WP_Error( 'invalid_input', __( 'meta_data must be a list of key/value objects.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$list = array();
			foreach ( $input['meta_data'] as $meta ) {
				$meta = is_object( $meta ) ? get_object_vars( $meta ) : $meta;
				$key  = is_array( $meta ) && is_scalar( $meta['key'] ?? null ) ? (string) $meta['key'] : '';
				if ( '' === $key || ! self::plain_meta_key( $key ) ) {
					$refused[] = '' === $key ? '""' : $key;
					continue;
				}
				$row = null;
				if ( isset( $meta['id'] ) && '' !== $meta['id'] && 0 !== $meta['id'] ) {
					// A row id is checked in the WordPress meta table of the type. Order meta may live
					// in wc_orders_meta (HPOS), where get_metadata_by_mid() does not look: refused.
					if ( ! is_numeric( $meta['id'] ) || ! in_array( $meta_type, array( 'post', 'user', 'term', 'comment' ), true ) ) {
						$refused[] = $key;
						continue;
					}
					$found = get_metadata_by_mid( $meta_type, (int) $meta['id'] );
					$row   = is_object( $found ) ? $found : null;
					if ( $row && (string) $row->meta_key !== $key && is_protected_meta( (string) $row->meta_key, $meta_type ) ) {
						// Would rename (and overwrite) a protected row such as _price or _downloadable_files.
						$refused[] = (string) $row->meta_key;
						continue;
					}
				}
				if ( is_protected_meta( $key, $meta_type ) ) {
					$value = $meta['value'] ?? null;
					if ( $row ) {
						$owner = (int) ( $row->{$meta_type . '_id'} ?? 0 );
						$same  = $object_id > 0 && $owner === $object_id && (string) $row->meta_key === $key
							&& self::same_meta( $value, array( $row->meta_value ) );
					} else {
						$same = $object_id > 0 && self::same_meta( $value, self::stored_meta( $meta_type, $object_id, $key ) );
					}
					if ( ! $same ) {
						$refused[] = $key;
					}
					continue;
				}
				if ( array_key_exists( 'value', $meta ) ) {
					$meta['value'] = self::kses_deep( $meta['value'] );
				}
				$list[] = $meta;
			}
			$input['meta_data'] = $list;
		}

		if ( $refused ) {
			$refused = array_values( array_unique( $refused ) );
			return new \WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: comma separated meta keys. */
					__( 'Protected meta keys cannot be changed with this token: %s. Leave them out or send the value that is already stored.', 'ux-studio' ),
					implode( ', ', $refused )
				),
				array(
					'status' => 403,
					'keys'   => $refused,
				)
			);
		}
		return $input;
	}

	/**
	 * Stored values of a meta key (every row, unserialized).
	 *
	 * @param string $meta_type Meta object type.
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 * @return array<int, mixed>
	 */
	private static function stored_meta( string $meta_type, int $object_id, string $key ): array {
		if ( 'order' === $meta_type ) {
			// HPOS-aware: through the order object, not postmeta.
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $object_id ) : null;
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
				return array();
			}
			$rows = array();
			foreach ( (array) $order->get_meta( $key, false, 'edit' ) as $row ) {
				$rows[] = is_object( $row ) && isset( $row->value ) ? $row->value : $row;
			}
			return $rows;
		}
		$rows = get_metadata( $meta_type, $object_id, $key, false );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Is a meta key plain ASCII (`A-Z a-z 0-9 _ - : .`)? is_protected_meta() drops characters it
	 * does not know before it looks at the first one, so a fullwidth `＿price` would not count as
	 * protected, yet a unicode_ci collation compares it equal to `_price` in SQL.
	 *
	 * @param string $key Meta key.
	 */
	public static function plain_meta_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_\-:.]+$/D', $key );
	}

	/**
	 * Is a submitted meta value the one already stored? Compared the way REST returns it: one row
	 * as the value itself, several rows as a list, no row as null / '' / [] / false; numbers and
	 * booleans as the strings the database holds.
	 *
	 * @param mixed             $value Submitted value.
	 * @param array<int, mixed> $rows  Stored rows.
	 */
	public static function same_meta( $value, array $rows ): bool {
		if ( ! $rows ) {
			return null === $value || '' === $value || array() === $value || false === $value;
		}
		if ( null === $value ) {
			return false;
		}
		if ( is_bool( $value ) && 1 === count( $rows ) && is_scalar( $rows[0] ) ) {
			$stored = strtolower( trim( (string) $rows[0] ) );
			return ! in_array( $stored, array( '', '0', 'false', 'no', 'off' ), true ) === $value;
		}
		$norm = self::normalize_meta( $value );
		if ( 1 === count( $rows ) && self::normalize_meta( $rows[0] ) === $norm ) {
			return true;
		}
		return is_array( $value ) && array_is_list( $value ) && self::normalize_meta( $rows ) === $norm;
	}

	/**
	 * Comparable form of a meta value: objects as arrays, keyed arrays sorted, scalars as strings.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function normalize_meta( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			$value = array_map( array( self::class, 'normalize_meta' ), $value );
			if ( ! array_is_list( $value ) ) {
				ksort( $value, SORT_STRING );
			}
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( null === $value ) {
			return '';
		}
		return is_scalar( $value ) ? (string) $value : $value;
	}

	/**
	 * WooCommerce `downloads` of a token without the admin scope. The token acts as an
	 * administrator, and for one WooCommerce approves (and enables) the directory of any new
	 * download file, so `downloads[].file` could otherwise publish /etc/passwd, wp-config.php or a
	 * backup as a free download. Every file must therefore be:
	 *
	 * - an absolute http(s) URL, never a local path, file://, //host or a `[shortcode]`, without
	 *   whitespace, control characters, backslashes, brackets, user info or a `.` / `..` path
	 *   segment (also percent-encoded);
	 * - canonical: what WooCommerce stores (WC_Product_Download::set_file() runs esc_url_raw(),
	 *   which strips e.g. ^ { } " < > `, so `/.^./` would be saved as `/../`) must be the very
	 *   string that was checked. The scheme and host are lower-cased (an IDN host converted to
	 *   punycode) and the canonical URL is what gets sent on;
	 * - inside an approved and enabled download directory already, with the Approved Download
	 *   Directories system enabled (in its disabled mode WooCommerce checks nothing);
	 * - when it is a URL of this site (WooCommerce serves those from the disk), inside the uploads
	 *   directory, and the site URL may not appear again later in it (WooCommerce str_replace()s it).
	 *
	 * The value that reaches set_file() after the woocommerce_file_download_path filter is checked
	 * once more while the request runs (download_path_filter()).
	 *
	 * @param array<string, mixed> $input       Body parameters.
	 * @param callable|null        $is_approved fn( string $url ): bool, defaults to WooCommerce.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function guard_downloads( array $input, ?callable $is_approved = null ) {
		if ( ! array_key_exists( 'downloads', $input ) || ! Auth::restricted() ) {
			return $input;
		}
		$list = is_object( $input['downloads'] ) ? get_object_vars( $input['downloads'] ) : $input['downloads'];
		if ( ! is_array( $list ) ) {
			return new \WP_Error( 'invalid_input', __( 'downloads must be a list of files.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$is_approved = $is_approved ?? array( self::class, 'woo_approved_download' );
		$out         = array();
		foreach ( $list as $key => $item ) {
			$item = is_object( $item ) ? get_object_vars( $item ) : $item;
			if ( ! is_array( $item ) ) {
				return new \WP_Error( 'invalid_input', __( 'downloads must be a list of files.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$file = $item['file'] ?? '';
			if ( empty( $file ) ) {
				$out[ $key ] = $item;
				continue; // WooCommerce skips an item without a file.
			}
			$canonical = is_string( $file ) ? self::download_file_allowed( $file, $is_approved ) : null;
			if ( null === $canonical ) {
				return self::download_refused( $file );
			}
			$item['file'] = $canonical;
			$out[ $key ]  = $item;
		}
		$input['downloads'] = $out;
		return $input;
	}

	/**
	 * Canonical form of an allowed download file (shape and approved directory), null if refused.
	 *
	 * @param string        $file        Download file.
	 * @param callable|null $is_approved fn( string $url ): bool, defaults to WooCommerce.
	 */
	public static function download_file_allowed( string $file, ?callable $is_approved = null ): ?string {
		$canonical = self::canonical_download_url( $file );
		if ( null === $canonical || ! self::download_url_allowed( $canonical ) ) {
			return null;
		}
		$is_approved = $is_approved ?? array( self::class, 'woo_approved_download' );
		return $is_approved( $canonical ) ? $canonical : null;
	}

	/**
	 * The 403 of a refused download file.
	 *
	 * @param mixed $file Download file.
	 */
	private static function download_refused( $file ): \WP_Error {
		return new \WP_Error(
			'invalid_input',
			sprintf(
				/* translators: %s: download file. */
				__( 'The download file %s is refused for this token: it must be an http(s) URL inside an already approved download directory (WooCommerce > Settings > Products > Approved download directories). Local paths need the admin scope.', 'ux-studio' ),
				is_string( $file ) ? $file : gettype( $file )
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * `woocommerce_file_download_path`, last, while a restricted token runs a wrapped route: the
	 * value WooCommerce is about to store must pass the same checks (another filter may have
	 * changed what guard_downloads() saw). Refused: the save is aborted.
	 *
	 * @param mixed $path Download file.
	 * @return mixed
	 * @throws \Exception When the file is refused (WC_Data_Exception if WooCommerce is loaded).
	 */
	public static function download_path_filter( $path ) {
		if ( ! Auth::restricted() || ( is_string( $path ) && '' === $path ) ) {
			return $path;
		}
		$canonical = is_string( $path ) ? self::download_file_allowed( $path ) : null;
		if ( null !== $canonical && $canonical === $path ) {
			return $path;
		}
		$message = self::download_refused( $path )->get_error_message();
		if ( class_exists( 'WC_Data_Exception' ) ) {
			throw new \WC_Data_Exception( 'uxstudio_download_refused', $message, 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- REST error message.
		}
		throw new \Exception( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- REST error message.
	}

	/**
	 * The canonical form of a download URL, or null when it is not an absolute http(s) URL or
	 * WooCommerce would store something else than it (esc_url_raw() in set_file()).
	 *
	 * @param string $file Download file.
	 */
	public static function canonical_download_url( string $file ): ?string {
		if ( strlen( $file ) > 2048 || preg_match( '~[\x00-\x20\x7f\\\\]~', $file ) ) {
			return null;
		}
		if ( ! preg_match( '~^([A-Za-z][A-Za-z0-9+.-]*)://([^/?#]*)(/.*)$~sD', $file, $m ) ) {
			return null;
		}
		$scheme = strtolower( $m[1] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		if ( ! preg_match( '~^([^:]+)(?::(\d{1,5}))?$~D', $m[2], $authority ) ) {
			return null;
		}
		$host = $authority[1];
		if ( preg_match( '~[^\x21-\x7e]~', $host ) ) {
			// IDN host: punycode, the form the approved directories and the HTTP client use.
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				return null;
			}
			$host = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( ! is_string( $host ) ) {
				return null;
			}
		}
		$host = strtolower( $host );
		if ( ! preg_match( '~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~D', $host ) || false !== strpos( $host, '..' ) ) {
			return null;
		}
		$port = isset( $authority[2] ) && '' !== $authority[2] ? (int) $authority[2] : null;
		if ( null !== $port && ( $port < 1 || $port > 65535 ) ) {
			return null;
		}
		$canonical = $scheme . '://' . $host . ( null !== $port ? ':' . $port : '' ) . $m[3];
		if ( ! function_exists( 'esc_url_raw' ) || esc_url_raw( $canonical ) !== $canonical ) {
			return null;
		}
		return $canonical;
	}

	/**
	 * The shape checks of guard_downloads() on a canonical URL (no database).
	 *
	 * @param string $file Download file (canonical_download_url()).
	 */
	public static function download_url_allowed( string $file ): bool {
		$bad = '[\x00-\x20\x7f\\\\\[\]@]';
		if ( strlen( $file ) > 2048 || ! preg_match( '~^https?://[a-z0-9.-]+(?::\d+)?/~', $file ) || preg_match( '~' . $bad . '~', $file ) ) {
			return false;
		}
		$decoded = $file;
		for ( $i = 0; $i < 5; $i++ ) {
			$next = rawurldecode( $decoded );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}
		// Decoded (WooCommerce and the web server decode it): no dot segment (a segment of dots and
		// spaces only, as Windows trims `.. ` and `...` to `..`), backslash or control character.
		// `file..zip` is a file name and passes.
		if ( preg_match( '~(?:^|/)(?=[. ]*\.)[. ]+(?:$|[/?#;])~', $decoded ) || preg_match( '~[\x00-\x1f\x7f\\\\]~', $decoded ) ) {
			return false;
		}

		$uploads = function_exists( 'wp_upload_dir' ) ? (string) ( wp_upload_dir( null, false )['baseurl'] ?? '' ) : '';
		$own     = array( $uploads );
		foreach ( array( 'site_url', 'network_site_url', 'home_url' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				$own[] = (string) $fn( '/' );
			}
		}
		$is_local = false;
		foreach ( $own as $url ) {
			$bare = preg_replace( '~^https?:~i', '', untrailingslashit( $url ) );
			if ( '' === $bare || null === $bare ) {
				continue;
			}
			foreach ( array( 'https:', 'http:' ) as $scheme ) {
				// WooCommerce replaces the site URL anywhere in the string, a second copy (e.g. in
				// the query) would turn a remote URL into a path on the disk.
				if ( false !== stripos( $file, $scheme . $bare, 1 ) || false !== stripos( $decoded, $scheme . $bare, 1 ) ) {
					return false;
				}
			}
			$is_local = $is_local || 0 === stripos( (string) preg_replace( '~^https?:~', '', $file ), $bare );
		}
		if ( $is_local ) {
			// A URL of this site is read from the disk: only below the uploads directory.
			$base = preg_replace( '~^https?:~i', '', untrailingslashit( $uploads ) );
			return '' !== (string) $base && 0 === strpos( (string) preg_replace( '~^https?:~', '', $file ), $base . '/' );
		}
		return true;
	}

	/**
	 * Is the URL inside an approved and enabled WooCommerce download directory? False when the
	 * feature is not available (fail closed).
	 *
	 * @param string $file Download file.
	 */
	public static function woo_approved_download( string $file ): bool {
		$class = 'Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register';
		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $class ) ) {
			return false;
		}
		try {
			$register = wc_get_container()->get( $class );
			// Only the enabled system refuses files outside the list (in the disabled mode an
			// administrator - which the token acts as - may store any file).
			if ( $class::MODE_ENABLED !== $register->get_mode() ) {
				return false;
			}
			return (bool) $register->is_valid_path( $file );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * `query` filter while a restricted token runs a wrapped route: WooCommerce may not add, enable
	 * or change approved download directories on its behalf (it does so for any administrator).
	 *
	 * @param mixed $query SQL.
	 * @return mixed
	 */
	public static function block_download_directory_writes( $query ) {
		if ( is_string( $query ) && preg_match( '~^\s*(?:INSERT|UPDATE|REPLACE|DELETE)\b~i', $query ) && false !== stripos( $query, 'wc_product_download_directories' ) ) {
			return '';
		}
		return $query;
	}

	/**
	 * wp_kses_post() on every string of a value.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function kses_deep( $value ) {
		if ( is_string( $value ) ) {
			return wp_kses_post( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'kses_deep' ), $value );
		}
		return $value;
	}

	/**
	 * Forward the call to the wrapped route.
	 *
	 * @param RestOperationConfig  $config Operation.
	 * @param array<string, mixed> $input  Tool input.
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	protected function execute_rest_request( RestOperationConfig $config, array $input ) {
		$built = self::build_route( $config->route, $input );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		// build_route() checked the id against the route regex and `^[A-Za-z0-9_-]+$`.
		$object_id = array_key_exists( 'id', self::url_params( $config->route ) ) && ctype_digit( (string) $input['id'] ) ? (int) $input['id'] : 0;
		$input     = self::guard_meta( $built['input'], self::meta_type( $config->namespace, $config->route ), $object_id );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$input = self::guard_downloads( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$url = '/' . $config->namespace . $built['route'];

		$request = new WP_REST_Request( $config->method, $url );

		if ( in_array( $config->method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$request->set_body_params( $input );
		} else {
			$request->set_query_params( $input );
		}

		$restricted = Auth::restricted();
		if ( $restricted ) {
			add_filter( 'query', array( self::class, 'block_download_directory_writes' ), PHP_INT_MAX );
			add_filter( 'woocommerce_file_download_path', array( self::class, 'download_path_filter' ), PHP_INT_MAX );
		}
		try {
			$response = rest_do_request( $request );
		} finally {
			if ( $restricted ) {
				remove_filter( 'query', array( self::class, 'block_download_directory_writes' ), PHP_INT_MAX );
				remove_filter( 'woocommerce_file_download_path', array( self::class, 'download_path_filter' ), PHP_INT_MAX );
			}
		}
		$data = $response->get_data();

		return array(
			array(
				'text' => is_array( $data ) ? wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : (string) $data,
			),
		);
	}

	private function route_matches( string $target, string $pattern ): bool {
		$target_clean  = preg_replace( '/\(\?P<\w+>[^)]+\)/', '___PARAM___', $target );
		$pattern_clean = preg_replace( '/\(\?P<\w+>[^)]+\)/', '___PARAM___', $pattern );
		return $target_clean === $pattern_clean;
	}

	private function method_matches( string $method, $handler_methods ): bool {
		if ( is_string( $handler_methods ) ) {
			return strtoupper( $method ) === strtoupper( $handler_methods );
		}
		if ( is_array( $handler_methods ) ) {
			return isset( $handler_methods[ strtoupper( $method ) ] );
		}
		return false;
	}

	private function arg_to_schema_property( string $name, array $def ): ?array {
		$type = $def['type'] ?? 'string';

		if ( in_array( $type, array( 'object', 'array' ), true ) ) {
			if ( ! isset( $def['items'] ) && ! isset( $def['properties'] ) ) {
				return null;
			}
		}

		$prop = array( 'type' => $type );

		if ( isset( $def['description'] ) ) {
			$prop['description'] = $def['description'];
		}
		if ( isset( $def['enum'] ) ) {
			$prop['enum'] = $def['enum'];
		}
		if ( isset( $def['default'] ) ) {
			$prop['default'] = $def['default'];
		}
		if ( isset( $def['required'] ) && $def['required'] ) {
			$prop['required'] = true;
		}

		return $prop;
	}
}
