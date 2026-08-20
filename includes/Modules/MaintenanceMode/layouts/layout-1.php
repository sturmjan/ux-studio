<?php
/**
 * Maintenance layout 1 - centered card.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

require $this->template_path( 'layouts/header.php' );
?>
<div class="uxstudio__container">
	<?php require $this->template_path( 'layouts/content.php' ); ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
