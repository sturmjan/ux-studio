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

	<?php if ( '' !== trim( $data['content']['footer']['text'] ) ) : ?>
		<footer class="uxstudio__footer">
			<p><?php echo esc_html( $data['content']['footer']['text'] ); ?></p>
		</footer>
	<?php endif; ?>
</div>
