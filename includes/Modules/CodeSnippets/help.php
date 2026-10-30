<?php
/**
 * Plain-language help of the Code Snippets settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'safe_mode' => array(
		'does'   => __( 'While it is on, none of your code snippets run. The snippets stay saved and you can still edit them.', 'ux-studio' ),
		'effect' => __( 'A snippet that broke the site can no longer do harm, so you can get back into the administration and fix or delete it.', 'ux-studio' ),
		'risk'   => __( 'Everything the snippets did stops working too: added styles, scripts, tracking codes, small functions. The site may look or behave differently until you turn safe mode off.', 'ux-studio' ),
		'off'    => __( 'Turn it on for the time of a repair and off again when the snippets work.', 'ux-studio' ),
	),
);
