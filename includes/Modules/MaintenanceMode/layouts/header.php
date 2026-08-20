<?php
/**
 * Maintenance page <head> and opening <body>.
 *
 * Included from Module::load_template(); $this is the module instance.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

/** @var array $data */
$data = $this->get_layout_data();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<title><?php echo esc_html( $data['meta']['title'] ); ?></title>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>" />
	<meta name="viewport" content="width=device-width, maximum-scale=1, initial-scale=1, minimum-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="description" content="<?php echo esc_attr( $data['meta']['description'] ); ?>" />

	<meta property="og:type" content="website" />
	<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
	<meta property="og:title" content="<?php echo esc_attr( $data['meta']['title'] ); ?>" />
	<meta property="og:url" content="<?php echo esc_url( home_url() ); ?>" />
	<meta property="og:description" content="<?php echo esc_attr( $data['meta']['description'] ); ?>" />

	<meta name="twitter:card" content="summary" />
	<meta name="twitter:title" content="<?php echo esc_attr( $data['meta']['title'] ); ?>" />
	<meta name="twitter:description" content="<?php echo esc_attr( $data['meta']['description'] ); ?>" />

	<?php
	if ( function_exists( 'has_site_icon' ) && has_site_icon() ) {
		wp_site_icon();
	}

	if ( isset( $data['css_files'] ) ) {
		foreach ( $data['css_files'] as $handle => $css_file ) {
			wp_enqueue_style( 'uxstudio-maintenance-mode-' . $handle, $css_file, array(), defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false );
		}
	}

	wp_head();
	?>

	<?php if ( 'page' !== $data['layout']['type'] ) : ?>
		<style id="uxstudio-maintenance-mode-variables">
			:root {
				--uxstudio-headline-color: <?php echo esc_html( $data['content']['headline']['color'] ); ?>;
				--uxstudio-body-color: <?php echo esc_html( $data['content']['body']['color'] ); ?>;
				--uxstudio-footer-color: <?php echo esc_html( $data['content']['footer']['color'] ); ?>;
				--uxstudio-background-color: <?php echo esc_html( $data['background']['color'] ); ?>;
				--uxstudio-logo-width: <?php echo (int) $data['header']['logo']['dimensions']['width']; ?>px;
				<?php if ( $data['background']['enable_image'] ) : ?>
				--uxstudio-background-image: url('<?php echo esc_url( $data['background']['image'] ); ?>');
				<?php endif; ?>
			}
		</style>
	<?php endif; ?>

	<?php if ( '' !== trim( $data['custom_css'] ) ) : ?>
		<style id="uxstudio-maintenance-mode-custom-css"><?php echo wp_strip_all_tags( $data['custom_css'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
	<?php endif; ?>
</head>
<body <?php body_class( 'uxstudio__body' ); ?> data-background-image="<?php echo $data['background']['enable_image'] ? 'true' : 'false'; ?>">
	<?php wp_body_open(); ?>
