<?php
/**
 * Maintenance layout - render an existing page's content.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

/** @var array $data */
$data = $this->get_layout_data();

require $this->template_path( 'layouts/header.php' );

$maintenance_page = get_post( (int) $data['layout']['existing_page'] );
if ( $maintenance_page instanceof WP_Post ) {
	setup_postdata( $maintenance_page );
	echo apply_filters( 'the_content', $maintenance_page->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	wp_reset_postdata();
}

wp_footer();
?>
</body>
</html>
