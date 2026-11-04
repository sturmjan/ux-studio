<?php
/**
 * Plain-language help of the Tables settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'default_striped'    => array(
		'does'   => __( 'Decides whether a new table starts with every second row shaded.', 'ux-studio' ),
		'effect' => __( 'Long tables are easier to read line by line. Existing tables keep their own choice.', 'ux-studio' ),
		'off'    => __( 'Turn it off when your theme already styles table rows or you prefer a plain look.', 'ux-studio' ),
	),
	'default_responsive' => array(
		'does'   => __( 'Chooses what a new table does on a narrow screen. "Scroll" keeps the table as it is and lets visitors swipe sideways. "Stack" turns every row into a small card. "None" leaves the table alone.', 'ux-studio' ),
		'effect' => __( 'Phone visitors can read wide tables without zooming. Existing tables keep their own choice.', 'ux-studio' ),
		'risk'   => __( 'Stack works best when the table has a header row, because the header text becomes the label of every value. Without it the cards show values with no labels.', 'ux-studio' ),
		'off'    => __( 'Choose "None" only when the table is always narrow or your theme handles small screens itself.', 'ux-studio' ),
	),
);
