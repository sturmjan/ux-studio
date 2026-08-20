<?php
/**
 * Maintenance layout 2 - split, content left / image right.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

require $this->template_path( 'layouts/header.php' );
?>
<div class="uxstudio__container uxstudio__container--split">
	<div class="uxstudio__split uxstudio__split--layout-2">
		<div class="uxstudio__split-content">
			<?php require $this->template_path( 'layouts/content.php' ); ?>
		</div>
		<div class="uxstudio__split-image"></div>
	</div>
</div>
<?php wp_footer(); ?>
</body>
</html>
