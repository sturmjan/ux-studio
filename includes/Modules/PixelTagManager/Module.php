<?php
/**
 * Pixel & Tag Manager module - inject tracking scripts into wp_head.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PixelTagManager;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs Google Analytics, Facebook Pixel and Pinterest tracking snippets.
 * Every ID is strictly validated against a format regex before being echoed
 * into the page head (stored-XSS protection). Ported from the legacy module.
 */
final class Module extends BaseModule {

	/**
	 * ID format validation rules per provider.
	 *
	 * @var array<string, string>
	 */
	private const VALIDATION_RULES = array(
		'google_analytics' => '/^(G|UA|GT|AW)-[A-Za-z0-9-]+$/',
		'facebook_pixel'   => '/^[0-9]{5,20}$/',
		'pinterest_tag'    => '/^[0-9]{5,20}$/',
	);

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_head', array( $this, 'render_tracking_scripts' ) );
	}

	/**
	 * Whether a tracking ID matches the provider's format.
	 *
	 * @param string $id   Raw ID.
	 * @param string $type Provider key.
	 */
	private function is_valid_tracking_id( string $id, string $type ): bool {
		if ( '' === $id ) {
			return false;
		}
		return isset( self::VALIDATION_RULES[ $type ] ) && 1 === preg_match( self::VALIDATION_RULES[ $type ], $id );
	}

	/**
	 * Output all configured, valid tracking scripts.
	 */
	public function render_tracking_scripts(): void {
		$this->render_google_analytics( (string) $this->settings->get( 'google_analytics', '' ) );
		$this->render_facebook_pixel( (string) $this->settings->get( 'facebook_pixel', '' ) );
		$this->render_pinterest_tag( (string) $this->settings->get( 'pinterest_tag', '' ) );
	}

	/**
	 * Render the Google Analytics (gtag) snippet.
	 *
	 * @param string $tracking_id Tracking ID.
	 */
	private function render_google_analytics( string $tracking_id ): void {
		if ( ! $this->is_valid_tracking_id( $tracking_id, 'google_analytics' ) ) {
			return;
		}
		?>
		<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $tracking_id ); ?>"></script>
		<script>
			window.dataLayer = window.dataLayer || [];
			function gtag() { dataLayer.push( arguments ); }
			gtag( "js", new Date() );
			gtag( "config", <?php echo wp_json_encode( $tracking_id ); ?> );
		</script>
		<?php
	}

	/**
	 * Render the Facebook Pixel snippet.
	 *
	 * @param string $pixel_id Pixel ID.
	 */
	private function render_facebook_pixel( string $pixel_id ): void {
		if ( ! $this->is_valid_tracking_id( $pixel_id, 'facebook_pixel' ) ) {
			return;
		}
		?>
		<script>
			! function ( f, b, e, v, n, t, s ) {
				if ( f.fbq ) return;
				n = f.fbq = function () {
					n.callMethod ? n.callMethod.apply( n, arguments ) : n.queue.push( arguments )
				};
				if ( ! f._fbq ) f._fbq = n;
				n.push = n;
				n.loaded = ! 0;
				n.version = '2.0';
				n.queue = [];
				t = b.createElement( e );
				t.async = ! 0;
				t.src = v;
				s = b.getElementsByTagName( e )[ 0 ];
				s.parentNode.insertBefore( t, s )
			}( window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js' );
			fbq( 'init', <?php echo wp_json_encode( $pixel_id ); ?> );
			fbq( 'track', 'PageView' );
		</script>
		<noscript>
			<img height="1" width="1" style="display:none" alt=""
				src="https://www.facebook.com/tr?id=<?php echo esc_attr( $pixel_id ); ?>&ev=PageView&noscript=1" />
		</noscript>
		<?php
	}

	/**
	 * Render the Pinterest Tag snippet.
	 *
	 * @param string $tag_id Tag ID.
	 */
	private function render_pinterest_tag( string $tag_id ): void {
		if ( ! $this->is_valid_tracking_id( $tag_id, 'pinterest_tag' ) ) {
			return;
		}
		?>
		<script>
			! function ( e ) {
				if ( ! window.pintrk ) {
					window.pintrk = function () {
						window.pintrk.queue.push( Array.prototype.slice.call( arguments ) )
					};
					var n = window.pintrk;
					n.queue = [];
					n.version = "3.0";
					var t = document.createElement( "script" );
					t.async = true;
					t.src = e;
					var r = document.getElementsByTagName( "script" )[ 0 ];
					r.parentNode.insertBefore( t, r );
				}
			}( "https://s.pinimg.com/ct/core.js" );
			pintrk( 'load', <?php echo wp_json_encode( $tag_id ); ?> );
			pintrk( 'page' );
		</script>
		<noscript>
			<img height="1" width="1" style="display:none;" alt=""
				src="https://ct.pinterest.com/v3/?tid=<?php echo esc_attr( $tag_id ); ?>&noscript=1" />
		</noscript>
		<?php
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'google_analytics',
				'type'    => 'text',
				'label'   => __( 'Google Analytics', 'ux-studio' ),
				'help'    => __( 'Enter a Google tracking ID, e.g. G-XXXXXXXXXX.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'facebook_pixel',
				'type'    => 'text',
				'label'   => __( 'Facebook Pixel', 'ux-studio' ),
				'help'    => __( 'Enter the numeric Facebook Pixel ID.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'pinterest_tag',
				'type'    => 'text',
				'label'   => __( 'Pinterest Tag', 'ux-studio' ),
				'help'    => __( 'Enter the numeric Pinterest Tag ID.', 'ux-studio' ),
				'default' => '',
			),
		);
	}
}
