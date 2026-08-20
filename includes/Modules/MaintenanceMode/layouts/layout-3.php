<?php
/**
 * Maintenance layout 3 - split, image left / content right.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

require $this->template_path( 'layouts/header.php' );
?>
<div class="uxstudio__container uxstudio__container--split">
	<div class="uxstudio__split uxstudio__split--layout-3">
		<div class="uxstudio__split-image"></div>
		<div class="uxstudio__split-content">
			<?php require $this->template_path( 'layouts/content.php' ); ?>
		</div>
	</div>
</div>
<?php wp_footer(); ?>
</body>
</html>
