<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Test of the Form Builder templates (PLAN.md 20.12) and the multi-step markup of the public renderer.
 *
 * Run inside a WordPress install that has UX Studio active (autoloader, sanitizers), loading THIS checkout:
 *
 *   wp eval-file tests/forms-templates.php --skip-plugins=wp-mail-smtp
 *
 * Exits non-zero when any case fails. Nothing is written to the database.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

$dir = dirname( __DIR__ ) . '/includes/Modules/Forms/';
foreach ( array( 'Fields', 'Templates', 'EmailTemplateRenderer', 'Submissions', 'FileStorage', 'PublicRenderer' ) as $class ) {
	require_once $dir . $class . '.php';
}
require_once dirname( __DIR__ ) . '/includes/Modules/Forms/Module.php';

use UxStudio\Modules\Forms\Fields;
use UxStudio\Modules\Forms\Module;
use UxStudio\Modules\Forms\PublicRenderer;
use UxStudio\Modules\Forms\Templates;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$module = ( new ReflectionClass( Module::class ) )->newInstanceWithoutConstructor();

$expected_steps = array(
	'contact'    => 1,
	'inquiry'    => 2,
	'booking'    => 2,
	'event'      => 1,
	'career'     => 3,
	'order'      => 3,
	'newsletter' => 1,
	'feedback'   => 1,
	'complaint'  => 2,
);
$categories     = array_column( Templates::categories(), 'id' );
$built_in_tags  = array( 'form_title', 'submission_date', 'submission_table' );

$assert( 'there are exactly the nine templates', array_keys( Templates::all() ) === array_keys( $expected_steps ), implode( ',', array_keys( Templates::all() ) ) );
$assert( 'seven categories', $categories === array( 'contact', 'inquiry', 'booking', 'event', 'career', 'shop', 'feedback' ) );
$assert( 'an unknown template has no payload', null === Templates::payload( 'nope' ) );

$catalog = array();
foreach ( Templates::catalog() as $item ) {
	$catalog[ $item['id'] ] = $item;
}

foreach ( Templates::all() as $id => $tpl ) {
	$raw       = $tpl['fields'];
	$clean     = Fields::sanitize_fields( $raw );
	$keys      = array_column( $clean, 'key' );
	$types     = array_column( $clean, 'type' );
	$payload   = Templates::payload( $id );
	$settings  = $module->sanitize_settings( $payload['settings'] );

	$assert( "$id: every field survives sanitizing", count( $clean ) === count( $raw ), count( $clean ) . ' of ' . count( $raw ) );
	$assert( "$id: field keys are unique and keep their name", count( $keys ) === count( array_unique( $keys ) ) && array_column( $raw, 'key' ) === $keys );
	$assert( "$id: category is one of the chips", in_array( $tpl['category'], $categories, true ) );
	$assert( "$id: has title, description and at least two fields", '' !== $tpl['title'] && '' !== $tpl['description'] && Templates::count_inputs( $clean ) >= 2 );
	$assert( "$id: step count is {$expected_steps[$id]}", Templates::count_steps( $clean ) === $expected_steps[ $id ], (string) Templates::count_steps( $clean ) );
	$assert( "$id: catalog agrees with the definition", isset( $catalog[ $id ] ) && $catalog[ $id ]['step_count'] === $expected_steps[ $id ] && $catalog[ $id ]['field_count'] === Templates::count_inputs( $clean ) );

	$widths_ok = true;
	foreach ( $clean as $field ) {
		foreach ( array( 'width', 'width_tablet', 'width_mobile' ) as $w ) {
			$widths_ok = $widths_ok && in_array( $field[ $w ], Fields::ALLOWED_WIDTHS, true );
		}
	}
	$assert( "$id: widths for desktop, tablet and mobile are allowed values", $widths_ok );

	$steps_titled = true;
	foreach ( $clean as $field ) {
		if ( 'step' === $field['type'] && '' === $field['step_title'] ) {
			$steps_titled = false;
		}
	}
	$assert( "$id: every step has a title", $steps_titled );

	$choice_ok = true;
	foreach ( $clean as $field ) {
		if ( in_array( $field['type'], Fields::CHOICE_TYPES, true ) && empty( $field['options'] ) ) {
			$choice_ok = false;
		}
	}
	$assert( "$id: every choice field has options", $choice_ok );

	$cond_ok = true;
	foreach ( $clean as $field ) {
		foreach ( $field['conditions'] as $cond ) {
			$cond_ok = $cond_ok && in_array( $cond['field'], $keys, true );
		}
	}
	$assert( "$id: conditions point at existing fields", $cond_ok );

	// Personal data (an e-mail or phone field) needs a required GDPR consent.
	$collects = (bool) array_intersect( array( 'email', 'tel' ), $types );
	$has_consent = false;
	foreach ( $clean as $field ) {
		if ( 'acceptance' === $field['type'] && 'consent' === $field['key'] && $field['required'] ) {
			$has_consent = true;
		}
	}
	$assert( "$id: collects personal data -> required GDPR consent", ! $collects || $has_consent );

	// Settings pass the real settings sanitizer unchanged in the parts a template sets.
	$assert( "$id: layout / progress / columns survive the settings sanitizer", ( $payload['settings']['layout'] ?? 'plain' ) === $settings['layout'] && $payload['settings']['progress_style'] === $settings['progress_style'] && ( $payload['settings']['columns'] ?? 1 ) === $settings['columns'] );
	$assert( "$id: every action survives the settings sanitizer", count( $settings['actions'] ) === count( $payload['settings']['actions'] ) );

	// Admin notification always; confirmation to the sender when the e-mail field is always asked.
	$actions       = $settings['actions'];
	$email_field   = null;
	foreach ( $clean as $field ) {
		if ( 'email' === $field['type'] && 'email' === $field['key'] ) {
			$email_field = $field;
		}
	}
	$to_admin = array_filter( $actions, static fn( $a ) => 'email' === $a['type'] && '' === $a['to'] );
	$to_user  = array_filter( $actions, static fn( $a ) => 'email' === $a['type'] && '{email}' === $a['to'] );
	$assert( "$id: notifies the site administrator", 1 === count( $to_admin ) );
	$needs_confirmation = null !== $email_field && empty( $email_field['conditions'] );
	$assert( "$id: confirmation to the sender exactly where the e-mail is always asked", $needs_confirmation === ( 1 === count( $to_user ) ), 'confirmation=' . count( $to_user ) );

	// Merge tags in subjects and messages must name real fields (or a built-in tag).
	$tags_ok = true;
	$bad     = '';
	foreach ( $actions as $action ) {
		preg_match_all( '/\{([a-z0-9_]+)\}/', $action['subject'] . ' ' . $action['message'], $m );
		foreach ( $m[1] as $tag ) {
			if ( ! in_array( $tag, $keys, true ) && ! in_array( $tag, $built_in_tags, true ) ) {
				$tags_ok = false;
				$bad     = $tag;
			}
		}
	}
	$assert( "$id: merge tags name real fields", $tags_ok, $bad );

	// The public renderer builds one fieldset per step, a progress area and the submit text.
	$form = array(
		'id'          => 1,
		'title'       => $payload['title'],
		'description' => $payload['description'],
		'fields'      => $clean,
		'settings'    => $settings,
		'status'      => 'active',
	);
	$html = ( new PublicRenderer() )->render( $form );
	$fieldsets = substr_count( $html, '<fieldset class="uxs-fp-step" data-uxs-step="' );
	$headings  = substr_count( $html, '<legend class="uxs-fp-step__title" tabindex="-1" data-uxs-step-head>' );
	$assert( "$id: public markup has {$expected_steps[$id]} step fieldset(s)", $fieldsets === $expected_steps[ $id ], (string) $fieldsets );
	$assert( "$id: multi-step has a focusable heading per step, single-step has none", $headings === ( $expected_steps[ $id ] > 1 ? $expected_steps[ $id ] : 0 ), (string) $headings );
	$assert( "$id: custom button text is rendered", '' === $settings['submit_text'] || false !== strpos( $html, '>' . esc_html( $settings['submit_text'] ) . '</button>' ) );
	$assert( "$id: grid placement is set on the fields", false !== strpos( $html, '--uxs-c:' ) && false !== strpos( $html, '--uxs-cm:' ) );
	$assert( "$id: card layout draws a header with the form title", 'card' !== $settings['layout'] || ( false !== strpos( $html, 'uxs-fp-card__title' ) && false !== strpos( $html, esc_html( $payload['title'] ) ) ) );
}

// Specific promises of the brief.
$career = Fields::sanitize_fields( Templates::get( 'career' )['fields'] );
$upload = array_values( array_filter( $career, static fn( $f ) => 'file' === $f['type'] ) );
$assert( 'career: CV upload is a required file field', 1 === count( $upload ) && $upload[0]['required'] );
$complaint = Fields::sanitize_fields( Templates::get( 'complaint' )['fields'] );
$photos    = array_values( array_filter( $complaint, static fn( $f ) => 'file' === $f['type'] ) );
$assert( 'complaint: photo upload accepts several images', 1 === count( $photos ) && $photos[0]['multiple'] && ! in_array( 'pdf', $photos[0]['accept'], true ) );
$order = Fields::sanitize_fields( Templates::get( 'order' )['fields'] );
$cards = array_filter( $order, static fn( $f ) => 'radio' === $f['type'] && 'cards' === ( $f['choice_style'] ?? '' ) );
$assert( 'order: delivery and payment are radio cards', count( $cards ) >= 2 );
$assert( 'order: numbered sections look', true === Templates::get( 'order' )['settings']['numbered_sections'] );
$news = Templates::get( 'newsletter' );
$assert( 'newsletter: inline layout, e-mail and button share a row', 'inline' === $news['settings']['layout'] && 75 === $news['fields'][0]['width'] && 100 === $news['fields'][0]['width_mobile'] );
$feedback = Fields::sanitize_fields( Templates::get( 'feedback' )['fields'] );
$rating   = array_values( array_filter( $feedback, static fn( $f ) => 'rating' === $f['key'] ) );
$assert( 'feedback: rating is five inline radio options', 1 === count( $rating ) && 5 === count( $rating[0]['options'] ) && 'inline' === $rating[0]['choice_style'] );
$assert( 'the title typed by the user wins, the template title is the default', 'My form' === Templates::payload( 'contact', 'My form' )['title'] && Templates::get( 'contact' )['title'] === Templates::payload( 'contact', '  ' )['title'] );
$assert( 'forms from a template start as draft', 'draft' === Templates::payload( 'contact' )['status'] );

// New field attributes are sanitized.
$odd = Fields::sanitize_fields( array( array( 'type' => 'radio', 'key' => 'r', 'options' => array( 'a' ), 'choice_style' => 'evil' ), array( 'type' => 'step', 'key' => 's', 'step_title' => 'T', 'step_description' => '<b>x</b>' ) ) );
$assert( 'an unknown choice style falls back to list', 'list' === $odd[0]['choice_style'] );
$assert( 'step description is plain text', 'x' === $odd[1]['step_description'] );
$s = $module->sanitize_settings( array( 'layout' => 'bogus', 'columns' => 7, 'progress_style' => 'numbers', 'numbered_sections' => '1', 'submit_text' => '<i>Go</i>' ) );
$assert( 'settings: bad layout -> plain, columns clamp to 1, numbers accepted, text stripped', 'plain' === $s['layout'] && 1 === $s['columns'] && 'numbers' === $s['progress_style'] && true === $s['numbered_sections'] && 'Go' === $s['submit_text'] );

echo PHP_EOL . ( 0 === $failures ? 'All passed.' : "$failures FAILED." ) . PHP_EOL;
if ( $failures ) {
	exit( 1 );
}
