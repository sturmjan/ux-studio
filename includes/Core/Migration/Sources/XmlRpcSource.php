<?php
/**
 * Source: Disable XML-RPC, Disable XML-RPC-API.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Both plugins switch XML-RPC off while they are active; that is the module key
 * `disable_xmlrpc`. Disable XML-RPC-API (option `dsxmlrpc-settings`) has a few extras
 * that map onto `disable_emojis` and `disable_rss_feeds`.
 */
final class XmlRpcSource extends AbstractSource {

	private const MODULE = 'security-optimization';

	protected function option_names(): array {
		return 'disable-xml-rpc-api' === $this->slug ? array( 'dsxmlrpc-settings' ) : array();
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'dsxmlrpc-settings' );
		$o = is_array( $o ) && 'disable-xml-rpc-api' === $this->slug ? $o : array();

		if ( $active ) {
			$m->set( self::MODULE, 'disable_xmlrpc', true, __( 'Disable XML-RPC', 'ux-studio' ), $this->slug );
		} else {
			$m->note( __( 'The plugin is not active, so XML-RPC is not blocked by it right now and the module setting is left as it is.', 'ux-studio' ) );
		}
		if ( ! empty( $o['remove-emojis'] ) ) {
			$m->set( self::MODULE, 'disable_emojis', true, __( 'Disable emojis', 'ux-studio' ), 'dsxmlrpc-settings:remove-emojis' );
		}
		if ( ! empty( $o['remove-rss'] ) ) {
			$m->set( self::MODULE, 'disable_rss_feeds', true, __( 'Disable RSS feeds', 'ux-studio' ), 'dsxmlrpc-settings:remove-rss' );
		}
		if ( ! empty( $o['dsxmlrpc-switcher'] ) ) {
			$m->lossy( __( 'The plugin leaves XML-RPC open for a renamed address or an allow list. The module only switches XML-RPC off completely.', 'ux-studio' ) );
		}
		if ( ! empty( $o['disabled-methods'] ) && is_array( $o['disabled-methods'] ) ) {
			$m->lossy( __( 'Disabling single XML-RPC methods is not available, the module turns off XML-RPC as a whole.', 'ux-studio' ) );
		}
		if ( ! empty( $o['htaccess protection'] ) || ! empty( $o['White-list-IPs'] ) || ! empty( $o['Black-list-IPs'] ) ) {
			$m->lossy( __( 'The .htaccess protection and the IP lists of the plugin are not transferred.', 'ux-studio' ) );
		}
		return $m;
	}
}
