<?php
/**
 * Plain-language help of the Duplicate Post settings (format: docs/help-texts.md).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'post_types'          => array(
		'does'   => __( 'The chosen content types get a Duplicate link in their lists.', 'ux-studio' ),
		'effect' => __( 'Only those types can be copied in one click.', 'ux-studio' ),
	),
	'all_post_types'      => array(
		'does'   => __( 'The Duplicate link appears for every content type that has a list in the administration, whatever you chose above.', 'ux-studio' ),
		'effect' => __( 'Also the types of other plugins (products, forms, events) can be copied.', 'ux-studio' ),
		'risk'   => __( 'Copying some special content types can lead to duplicates that the other plugin does not expect, for example orders or form entries.', 'ux-studio' ),
		'off'    => __( 'Leave it off and choose the types by hand.', 'ux-studio' ),
	),
	'allowed_roles'       => array(
		'does'   => __( 'Only users with these roles see the Duplicate link. Administrators can always duplicate.', 'ux-studio' ),
		'effect' => __( 'With the list empty, everybody who may edit the post can also copy it.', 'ux-studio' ),
	),
	'post_redirect'       => array(
		'does'   => __( 'After copying, the editor of the new post opens straight away.', 'ux-studio' ),
		'off'    => __( 'Turn it off when you copy many posts in a row and want to stay in the list.', 'ux-studio' ),
	),
	'post_status'         => array(
		'does'   => __( 'The status the copy gets: draft, pending review, private or published.', 'ux-studio' ),
		'effect' => __( 'A draft is safe, because nobody sees it until you publish it.', 'ux-studio' ),
		'risk'   => __( 'With Published the copy appears on the site at once, as a second page with the same content. That can hurt search results.', 'ux-studio' ),
	),
	'keep_status'         => array(
		'does'   => __( 'The copy gets the same status as the original instead of the status chosen above.', 'ux-studio' ),
		'effect' => __( 'A scheduled post becomes a draft, and a user who may not publish always gets a copy waiting for review.', 'ux-studio' ),
		'risk'   => __( 'A copy of a published post is published at once too, so the same content is on the site twice.', 'ux-studio' ),
	),
	'duplicate_elements'  => array(
		'does'   => __( 'Which parts of the post are copied: for example content, excerpt, image, categories, tags, comments or custom fields.', 'ux-studio' ),
		'effect' => __( 'What you leave out has to be filled in again in the copy.', 'ux-studio' ),
	),
	'excluded_meta'       => array(
		'does'   => __( 'Names of custom fields that are not copied. One per line or separated by commas, a star stands for any characters (for example _edit_* or *_cache). A line that starts with # is a comment.', 'ux-studio' ),
		'effect' => __( 'Use it for values that must not be repeated, such as counters, unique codes or caches. The edit lock and the caches of Elementor are never copied.', 'ux-studio' ),
	),
	'excluded_taxonomies' => array(
		'does'   => __( 'The copy does not get the categories, tags or other groupings (taxonomies) you choose here.', 'ux-studio' ),
		'effect' => __( 'The copy starts without them and you assign them again.', 'ux-studio' ),
	),
	'custom_title'        => array(
		'does'   => __( 'Gives the copy a title made from your pattern instead of the title of the original.', 'ux-studio' ),
		'effect' => __( 'You can tell copies from originals in the list at a glance.', 'ux-studio' ),
	),
	'custom_title_format' => array(
		'does'   => __( 'The pattern of the title of a copy. {post_title} is replaced by the title of the original.', 'ux-studio' ),
		'effect' => __( 'The title is a part of the address of the copy, so rename it before you publish it.', 'ux-studio' ),
	),
);
