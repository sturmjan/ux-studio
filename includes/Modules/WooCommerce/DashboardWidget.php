<?php
/**
 * WordPress dashboard widgets for recent WooCommerce orders and top products.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\WooCommerce;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered, exactly the BotThrottle\DashboardWidget pattern
 * (PLAN.md 20.10): static register()/add()/render(), no React - these
 * widgets live on the native wp-admin dashboard, not inside the SPA.
 *
 * Ported from legacy renderRecentOrdersWidget/renderTopProductsWidget.
 * IMPORTANT: renderRecentOrdersWidget was fixed in legacy for a real
 * production fatal - `wc_get_orders()` needs 'type' => 'shop_order' or it
 * can return non-order objects (e.g. refunds), and the loop must guard with
 * `instanceof \WC_Order`. Both fixes are carried over here.
 */
final class DashboardWidget {

	private const STATUS_COLORS = array(
		'completed'  => '#16a34a',
		'processing' => '#2271b1',
		'on-hold'    => '#dba617',
		'pending'    => '#646970',
		'cancelled'  => '#d63638',
		'refunded'   => '#8c8f94',
		'failed'     => '#d63638',
	);

	/**
	 * Register both widgets on the dashboard.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'add' ) );
	}

	/**
	 * Add the widgets (admins only), each gated on its own settings toggle
	 * and on WooCommerce actually being active - this callback can run even
	 * if the module itself was booted before WooCommerce loaded.
	 */
	public static function add(): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$settings = new Settings( 'uxstudio_woo_commerce' );

		if ( (bool) $settings->get( 'widget_recent_orders', false ) ) {
			wp_add_dashboard_widget(
				'uxstudio_woocommerce_recent_orders',
				__( 'WooCommerce - recent orders', 'ux-studio' ),
				array( self::class, 'render_recent_orders' )
			);
		}

		if ( (bool) $settings->get( 'widget_top_products', false ) ) {
			wp_add_dashboard_widget(
				'uxstudio_woocommerce_top_products',
				__( 'WooCommerce - top products', 'ux-studio' ),
				array( self::class, 'render_top_products' )
			);
		}
	}

	/**
	 * Recent orders widget body.
	 */
	public static function render_recent_orders(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'WooCommerce is not available.', 'ux-studio' ) . '</p>';
			return;
		}

		$orders = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'limit'   => 10,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		if ( empty( $orders ) ) {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'No orders yet.', 'ux-studio' ) . '</p>';
			return;
		}
		?>
		<table style="width:100%;border-collapse:collapse;font-size:12px;">
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<?php
					if ( ! $order instanceof \WC_Order ) {
						continue;
					}
					$status = $order->get_status();
					$color  = self::STATUS_COLORS[ $status ] ?? '#646970';
					$customer = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
					$customer = '' !== $customer ? $customer : $order->get_billing_email();
					?>
					<tr>
						<td style="padding:4px 0;">
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
							<span style="color:#646970;"> <?php echo esc_html( $customer ); ?></span>
						</td>
						<td>
							<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $color ); ?>;">
								<?php echo esc_html( wc_get_order_status_name( $status ) ); ?>
							</span>
						</td>
						<td style="text-align:right;font-weight:600;white-space:nowrap;">
							<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a href="<?php echo esc_url( self::orders_list_url() ); ?>" class="button button-small"><?php esc_html_e( 'View all orders', 'ux-studio' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Top products widget body (raw SQL against wc_order_product_lookup, same
	 * source table legacy used - there is no first-party WC API for this).
	 */
	public static function render_top_products(): void {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT p.ID, p.post_title,
			        SUM(ol.product_qty) AS total_sold,
			        SUM(ol.product_net_revenue) AS revenue
			   FROM {$wpdb->prefix}wc_order_product_lookup ol
			   INNER JOIN {$wpdb->posts} p ON p.ID = ol.product_id
			  WHERE p.post_status = 'publish'
			  GROUP BY ol.product_id
			  ORDER BY total_sold DESC
			  LIMIT 10",
			ARRAY_A
		);

		if ( empty( $results ) ) {
			echo '<p style="color:#646970;font-style:italic;">' . esc_html__( 'No sales yet.', 'ux-studio' ) . '</p>';
			return;
		}

		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
		?>
		<table style="width:100%;border-collapse:collapse;font-size:12px;">
			<tbody>
				<?php foreach ( $results as $row ) : ?>
					<tr>
						<td style="padding:4px 0;">
							<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['ID'] ) ); ?>"><?php echo esc_html( (string) $row['post_title'] ); ?></a>
						</td>
						<td style="text-align:right;color:#646970;white-space:nowrap;">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: quantity sold */
									__( '%s sold', 'ux-studio' ),
									number_format_i18n( (int) $row['total_sold'] )
								)
							);
							?>
						</td>
						<td style="text-align:right;font-weight:600;white-space:nowrap;">
							<?php echo esc_html( number_format_i18n( (float) $row['revenue'], 0 ) . ' ' . $currency ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" class="button button-small"><?php esc_html_e( 'View all products', 'ux-studio' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Admin URL of the orders list: `admin.php?page=wc-orders` only exists
	 * with HPOS (custom order tables) enabled; legacy post storage lists
	 * orders at `edit.php?post_type=shop_order`.
	 */
	private static function orders_list_url(): string {
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		return $hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
	}
}
