<?php
/**
 * Maintenance page content card.
 *
 * Included from a layout template; $this is the module instance, $data is set.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

/** @var array $data */
$data = $this->get_layout_data();
?>
<div class="uxstudio__card">
	<?php if ( $data['header']['enabled'] && ! empty( $data['header']['logo']['image'] ) ) : ?>
		<div class="uxstudio__logo">
			<?php
			echo wp_get_attachment_image(
				$data['header']['logo']['image'],
				'full',
				false,
				array(
					'class' => 'uxstudio__logo-img',
					'alt'   => esc_attr( $data['header']['logo']['alt'] ),
				)
			);
			?>
		</div>
	<?php endif; ?>

	<h1 class="uxstudio__headline">
		<?php echo esc_html( $data['content']['headline']['text'] ); ?>
	</h1>

	<div class="uxstudio__description">
		<div class="uxstudio__description-text">
			<?php echo wp_kses_post( wpautop( $data['content']['body']['text'] ) ); ?>
		</div>
	</div>

	<?php if ( ! empty( $data['countdown']['enabled'] ) ) : ?>
		<?php
		$uxs_cd    = $data['countdown'];
		$uxs_parts = \UxStudio\Modules\MaintenanceMode\MaintenanceRules::split( (int) $uxs_cd['remaining'] );
		$uxs_units = array(
			'd' => __( 'days', 'ux-studio' ),
			'h' => __( 'hours', 'ux-studio' ),
			'm' => __( 'minutes', 'ux-studio' ),
			's' => __( 'seconds', 'ux-studio' ),
		);
		?>
		<div class="uxstudio__countdown" data-remaining="<?php echo (int) $uxs_cd['remaining']; ?>" data-end="<?php echo (int) $uxs_cd['end']; ?>" data-reload="<?php echo $uxs_cd['reload'] ? '1' : '0'; ?>">
			<p class="uxstudio__countdown-label"><?php echo esc_html( $uxs_cd['label'] ); ?></p>
			<div class="uxstudio__countdown-units" role="timer" aria-live="off">
				<?php foreach ( $uxs_units as $uxs_key => $uxs_name ) : ?>
					<div class="uxstudio__countdown-unit">
						<span class="uxstudio__countdown-num" data-unit="<?php echo esc_attr( $uxs_key ); ?>"><?php echo esc_html( sprintf( '%02d', $uxs_parts[ $uxs_key ] ) ); ?></span>
						<small><?php echo esc_html( $uxs_name ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>
			<noscript><p class="uxstudio__countdown-label"><?php echo esc_html( $uxs_cd['end_label'] ); ?></p></noscript>
		</div>
		<script>
		( function () {
			var box = document.querySelector( '.uxstudio__countdown' );
			if ( ! box ) { return; }
			var start = Date.now(), total = parseInt( box.getAttribute( 'data-remaining' ), 10 ) || 0;
			var cells = {};
			[ 'd', 'h', 'm', 's' ].forEach( function ( k ) { cells[ k ] = box.querySelector( '[data-unit="' + k + '"]' ); } );
			function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
			function tick() {
				var left = Math.max( 0, total - Math.floor( ( Date.now() - start ) / 1000 ) );
				cells.d.textContent = pad( Math.floor( left / 86400 ) );
				cells.h.textContent = pad( Math.floor( left % 86400 / 3600 ) );
				cells.m.textContent = pad( Math.floor( left % 3600 / 60 ) );
				cells.s.textContent = pad( left % 60 );
				if ( left > 0 ) { return; }
				window.clearInterval( timer );
				if ( box.getAttribute( 'data-reload' ) === '1' && ! window.sessionStorage.getItem( 'uxs-cd-' + box.getAttribute( 'data-end' ) ) ) {
					window.sessionStorage.setItem( 'uxs-cd-' + box.getAttribute( 'data-end' ), '1' );
					window.setTimeout( function () { window.location.reload(); }, 2000 );
				}
			}
			var timer = window.setInterval( tick, 1000 );
			tick();
		}() );
		</script>
	<?php endif; ?>

	<?php if ( '' !== trim( $data['content']['footer']['text'] ) ) : ?>
		<footer class="uxstudio__footer">
			<p><?php echo esc_html( $data['content']['footer']['text'] ); ?></p>
		</footer>
	<?php endif; ?>
</div>
