<?php
/**
 * Ready-made form templates (PLAN.md 20.12).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Templates are plain PHP definitions (own texts, translated through the
 * normal gettext path), not stored data: a template never changes after a form
 * was created from it. Creating a form feeds the raw definition through
 * exactly the same sanitizing path as a normal save (Module::create_form() ->
 * Fields::sanitize_fields() / Module::sanitize_settings()), so a template can
 * never produce anything the builder could not.
 *
 * Every template that collects personal data (an e-mail or phone field) also
 * carries a required GDPR consent (`acceptance`); where the sender gives an
 * e-mail address, a confirmation e-mail to the sender is added next to the
 * notification to the site admin.
 */
final class Templates {

	/**
	 * @return array<int, array{id:string,label:string}> Category chips of the gallery, in display order.
	 */
	public static function categories(): array {
		return array(
			array( 'id' => 'contact', 'label' => __( 'Contact', 'ux-studio' ) ),
			array( 'id' => 'inquiry', 'label' => __( 'Inquiry', 'ux-studio' ) ),
			array( 'id' => 'booking', 'label' => __( 'Booking', 'ux-studio' ) ),
			array( 'id' => 'event', 'label' => __( 'Event', 'ux-studio' ) ),
			array( 'id' => 'career', 'label' => __( 'Careers', 'ux-studio' ) ),
			array( 'id' => 'shop', 'label' => __( 'Shop', 'ux-studio' ) ),
			array( 'id' => 'feedback', 'label' => __( 'Feedback', 'ux-studio' ) ),
		);
	}

	/**
	 * Raw definitions, keyed by template id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array(
			'contact'    => self::contact(),
			'inquiry'    => self::inquiry(),
			'booking'    => self::booking(),
			'event'      => self::event(),
			'career'     => self::career(),
			'order'      => self::order(),
			'newsletter' => self::newsletter(),
			'feedback'   => self::feedback(),
			'complaint'  => self::complaint(),
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * What the gallery needs: meta + the sanitized fields (for the live mini
	 * preview, drawn from our own field definitions) + counts. Actions are
	 * left out - they are only applied when a form is created.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function catalog(): array {
		$out = array();
		foreach ( self::all() as $id => $tpl ) {
			$fields = Fields::sanitize_fields( $tpl['fields'] );
			$out[]  = array(
				'id'          => (string) $id,
				'category'    => (string) $tpl['category'],
				'title'       => (string) $tpl['title'],
				'description' => (string) $tpl['description'],
				'field_count' => self::count_inputs( $fields ),
				'step_count'  => self::count_steps( $fields ),
				'fields'      => $fields,
				'settings'    => array(
					'layout'            => (string) ( $tpl['settings']['layout'] ?? 'plain' ),
					'columns'           => (int) ( $tpl['settings']['columns'] ?? 1 ),
					'numbered_sections' => ! empty( $tpl['settings']['numbered_sections'] ),
					'progress_style'    => (string) ( $tpl['settings']['progress_style'] ?? 'steps' ),
					'submit_text'       => (string) ( $tpl['settings']['submit_text'] ?? '' ),
				),
			);
		}
		return $out;
	}

	/**
	 * Input for Module::create_form(): title, description, fields and settings.
	 *
	 * @param string $title Name typed by the user, '' = the template's own title.
	 * @return array<string, mixed>|null Null for an unknown template.
	 */
	public static function payload( string $id, string $title = '' ): ?array {
		$tpl = self::get( $id );
		if ( null === $tpl ) {
			return null;
		}

		$privacy_url = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
		$fields      = array();
		foreach ( $tpl['fields'] as $field ) {
			if ( 'acceptance' === ( $field['type'] ?? '' ) && 'consent' === ( $field['key'] ?? '' ) && '' !== $privacy_url ) {
				$field['terms_url'] = $privacy_url;
			}
			$fields[] = $field;
		}

		$title = trim( $title );
		return array(
			'title'       => '' !== $title ? $title : (string) $tpl['title'],
			'description' => (string) ( $tpl['form_description'] ?? '' ),
			'fields'      => $fields,
			'settings'    => $tpl['settings'],
			'status'      => 'draft',
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $fields Sanitized fields.
	 */
	public static function count_inputs( array $fields ): int {
		$n = 0;
		foreach ( $fields as $field ) {
			if ( ! in_array( $field['type'] ?? '', Fields::NON_INPUT_TYPES, true ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Steps as the public renderer builds them: one per `step` break, or one
	 * implicit step when there is none.
	 *
	 * @param array<int, array<string, mixed>> $fields Sanitized fields.
	 */
	public static function count_steps( array $fields ): int {
		$breaks = 0;
		foreach ( $fields as $field ) {
			if ( 'step' === ( $field['type'] ?? '' ) ) {
				++$breaks;
			}
		}
		$starts_with_break = isset( $fields[0] ) && 'step' === ( $fields[0]['type'] ?? '' );
		return max( 1, $starts_with_break ? $breaks : $breaks + 1 );
	}

	// =====================================================================
	// Builders
	// =====================================================================

	/**
	 * One field. `w` is shorthand for the desktop / tablet / mobile width.
	 *
	 * @param array<string, mixed> $extra Any other field attribute; `w` => [desktop, tablet, mobile].
	 * @return array<string, mixed>
	 */
	private static function f( string $type, string $key, string $label, array $extra = array() ): array {
		$w = $extra['w'] ?? array( 100, 100, 100 );
		unset( $extra['w'] );
		return array_merge(
			array(
				'type'         => $type,
				'key'          => $key,
				'label'        => $label,
				'width'        => $w[0],
				'width_tablet' => $w[1],
				'width_mobile' => $w[2],
			),
			$extra
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function step( string $title, string $description = '' ): array {
		return array(
			'type'             => 'step',
			'key'              => 'step_' . substr( md5( $title ), 0, 6 ),
			'label'            => $title,
			'step_title'       => $title,
			'step_description' => $description,
		);
	}

	/**
	 * @param array<int, string> $labels Option labels (value = label).
	 * @return array<int, array{label:string,value:string}>
	 */
	private static function opts( array $labels ): array {
		return array_map(
			static fn( string $label ): array => array( 'label' => $label, 'value' => $label ),
			$labels
		);
	}

	/**
	 * Required GDPR consent, always the key `consent`.
	 *
	 * @param array<string, mixed> $extra Extra attributes (e.g. conditions).
	 * @return array<string, mixed>
	 */
	private static function consent( array $extra = array() ): array {
		return self::f(
			'acceptance',
			'consent',
			__( 'I agree to the processing of my personal data', 'ux-studio' ),
			array_merge(
				array(
					'required'      => true,
					'label_display' => 'placeholder_only',
					'placeholder'   => __( 'I agree to the processing of my personal data for the purpose of handling this request.', 'ux-studio' ),
				),
				$extra
			)
		);
	}

	/**
	 * Notification to the site administrator.
	 *
	 * @return array<string, mixed>
	 */
	private static function admin_mail( string $subject ): array {
		return array(
			'type'          => 'email',
			'to'            => '',
			'subject'       => $subject,
			'message'       => '<p>' . __( 'A new submission arrived on {submission_date}.', 'ux-studio' ) . '</p>',
			'template'      => 'branded',
			'include_table' => true,
		);
	}

	/**
	 * Confirmation to the sender, to the address typed into the `email` field.
	 *
	 * @return array<string, mixed>
	 */
	private static function confirmation_mail( string $subject, string $message_html ): array {
		return array(
			'type'          => 'email',
			'to'            => '{email}',
			'subject'       => $subject,
			'message'       => $message_html,
			'template'      => 'minimal',
			'include_table' => false,
		);
	}

	// =====================================================================
	// Templates
	// =====================================================================

	/** @return array<string, mixed> */
	private static function contact(): array {
		return array(
			'category'         => 'contact',
			'title'            => __( 'Contact form', 'ux-studio' ),
			'description'      => __( 'Name, e-mail, subject and message in a card with a header and footer.', 'ux-studio' ),
			'form_description' => __( 'Write to us and we will get back to you as soon as possible.', 'ux-studio' ),
			'fields'           => array(
				self::f( 'text', 'name', __( 'Your name', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'email', 'email', __( 'E-mail', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'text', 'subject', __( 'Subject', 'ux-studio' ) ),
				self::f( 'textarea', 'message', __( 'Message', 'ux-studio' ), array( 'required' => true, 'rows' => 5 ) ),
				self::consent(),
			),
			'settings'         => array(
				'layout'         => 'card',
				'progress_style' => 'none',
				'submit_text'    => __( 'Send message', 'ux-studio' ),
				'success_text'   => __( 'Thank you for your message. We will reply as soon as we can.', 'ux-studio' ),
				'actions'        => array(
					self::admin_mail( __( 'New message: {subject}', 'ux-studio' ) ),
					self::confirmation_mail(
						__( 'We received your message', 'ux-studio' ),
						'<p>' . __( 'Hello {name},', 'ux-studio' ) . '</p><p>' . __( 'thank you for your message. We will get back to you as soon as possible.', 'ux-studio' ) . '</p>'
					),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function inquiry(): array {
		return array(
			'category'         => 'inquiry',
			'title'            => __( 'Service inquiry', 'ux-studio' ),
			'description'      => __( 'Two steps: who you are, then what you need. Two columns.', 'ux-studio' ),
			'form_description' => __( 'Tell us what you need and we will prepare an offer.', 'ux-studio' ),
			'fields'           => array(
				self::step( __( 'Contact', 'ux-studio' ), __( 'How can we reach you?', 'ux-studio' ) ),
				self::f( 'text', 'name', __( 'Name and surname', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'text', 'company', __( 'Company', 'ux-studio' ), array( 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'email', 'email', __( 'E-mail', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'tel', 'phone', __( 'Phone', 'ux-studio' ), array( 'w' => array( 50, 50, 100 ) ) ),
				self::step( __( 'Inquiry details', 'ux-studio' ), __( 'What would you like us to do?', 'ux-studio' ) ),
				self::f(
					'select',
					'service',
					__( 'Service', 'ux-studio' ),
					array(
						'required' => true,
						'options'  => self::opts( array( __( 'New project', 'ux-studio' ), __( 'Redesign', 'ux-studio' ), __( 'Maintenance and support', 'ux-studio' ), __( 'Consultation', 'ux-studio' ), __( 'Something else', 'ux-studio' ) ) ),
						'w'        => array( 50, 50, 100 ),
					)
				),
				self::f(
					'select',
					'deadline',
					__( 'When do you need it?', 'ux-studio' ),
					array(
						'options' => self::opts( array( __( 'As soon as possible', 'ux-studio' ), __( 'Within a month', 'ux-studio' ), __( 'Within three months', 'ux-studio' ), __( 'No rush', 'ux-studio' ) ) ),
						'w'       => array( 50, 50, 100 ),
					)
				),
				self::f( 'textarea', 'details', __( 'Describe your inquiry', 'ux-studio' ), array( 'required' => true, 'rows' => 5 ) ),
				self::consent(),
			),
			'settings'         => array(
				'layout'         => 'card',
				'columns'        => 2,
				'progress_style' => 'numbers',
				'submit_text'    => __( 'Send inquiry', 'ux-studio' ),
				'success_text'   => __( 'Thank you, your inquiry was sent. We will contact you with an offer.', 'ux-studio' ),
				'actions'        => array(
					self::admin_mail( __( 'New inquiry from {name}', 'ux-studio' ) ),
					self::confirmation_mail(
						__( 'We received your inquiry', 'ux-studio' ),
						'<p>' . __( 'Hello {name},', 'ux-studio' ) . '</p><p>' . __( 'thank you for your inquiry. We will review it and send you an offer soon.', 'ux-studio' ) . '</p>'
					),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function booking(): array {
		return array(
			'category'         => 'booking',
			'title'            => __( 'Booking a time slot', 'ux-studio' ),
			'description'      => __( 'Two steps: pick a date and time, then leave your details.', 'ux-studio' ),
			'form_description' => __( 'Choose a date that suits you. We confirm every booking by e-mail.', 'ux-studio' ),
			'fields'           => array(
				self::step( __( 'Date and time', 'ux-studio' ), __( 'When would you like to come?', 'ux-studio' ) ),
				self::f( 'date', 'date', __( 'Date', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'time', 'time', __( 'Time', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f(
					'select',
					'service',
					__( 'What is it for?', 'ux-studio' ),
					array(
						'required' => true,
						'options'  => self::opts( array( __( 'First visit', 'ux-studio' ), __( 'Follow-up visit', 'ux-studio' ), __( 'Consultation', 'ux-studio' ), __( 'Other', 'ux-studio' ) ) ),
						'w'        => array( 50, 50, 100 ),
					)
				),
				self::f( 'number', 'people', __( 'Number of people', 'ux-studio' ), array( 'default_value' => '1', 'min' => 1, 'max' => 20, 'w' => array( 50, 50, 100 ) ) ),
				self::step( __( 'Your details', 'ux-studio' ), __( 'Where do we send the confirmation?', 'ux-studio' ) ),
				self::f( 'text', 'name', __( 'Name and surname', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'tel', 'phone', __( 'Phone', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'email', 'email', __( 'E-mail', 'ux-studio' ), array( 'required' => true ) ),
				self::f( 'textarea', 'note', __( 'Note', 'ux-studio' ), array( 'rows' => 3 ) ),
				self::consent(),
			),
			'settings'         => array(
				'layout'         => 'card',
				'columns'        => 2,
				'progress_style' => 'numbers',
				'submit_text'    => __( 'Book the slot', 'ux-studio' ),
				'success_text'   => __( 'Your booking request was sent. We will confirm it by e-mail.', 'ux-studio' ),
				'actions'        => array(
					self::admin_mail( __( 'New booking: {date} {time}', 'ux-studio' ) ),
					self::confirmation_mail(
						__( 'Your booking request', 'ux-studio' ),
						'<p>' . __( 'Hello {name},', 'ux-studio' ) . '</p><p>' . __( 'we received your request for {date} at {time}. We will confirm the slot by e-mail shortly.', 'ux-studio' ) . '</p>'
					),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function event(): array {
		return array(
			'category'         => 'event',
			'title'            => __( 'Event registration', 'ux-studio' ),
			'description'      => __( 'Attendee details, number of seats and the way of attending, in two columns.', 'ux-studio' ),
			'form_description' => __( 'Reserve your seat. Places are limited.', 'ux-studio' ),
			'fields'           => array(
				self::f( 'text', 'name', __( 'Name and surname', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'email', 'email', __( 'E-mail', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'tel', 'phone', __( 'Phone', 'ux-studio' ), array( 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'number', 'seats', __( 'Number of seats', 'ux-studio' ), array( 'default_value' => '1', 'min' => 1, 'max' => 10, 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f(
					'radio',
					'attendance',
					__( 'How will you attend?', 'ux-studio' ),
					array(
						'required'     => true,
						'choice_style' => 'cards',
						'options'      => self::opts( array( __( 'In person', 'ux-studio' ), __( 'Online', 'ux-studio' ) ) ),
					)
				),
				self::f( 'textarea', 'note', __( 'Questions or special requirements', 'ux-studio' ), array( 'rows' => 3 ) ),
				self::consent(),
			),
			'settings'         => array(
				'layout'         => 'card',
				'progress_style' => 'none',
				'submit_text'    => __( 'Register', 'ux-studio' ),
				'success_text'   => __( 'You are registered. We sent you a confirmation by e-mail.', 'ux-studio' ),
				'actions'        => array(
					self::admin_mail( __( 'New registration: {name}', 'ux-studio' ) ),
					self::confirmation_mail(
						__( 'Your registration is confirmed', 'ux-studio' ),
						'<p>' . __( 'Hello {name},', 'ux-studio' ) . '</p><p>' . __( 'your registration for {seats} seat(s) was received. We look forward to seeing you.', 'ux-studio' ) . '</p>'
					),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function career(): array {
		return array(
			'category'         => 'career',
			'title'            => __( 'Job application', 'ux-studio' ),
			'description'      => __( 'Three steps: personal details, experience, CV upload and consent.', 'ux-studio' ),
			'form_description' => __( 'Join the team. Filling this in takes about five minutes.', 'ux-studio' ),
			'fields'           => array(
				self::step( __( 'Personal details', 'ux-studio' ), __( 'Tell us who you are.', 'ux-studio' ) ),
				self::f( 'text', 'first_name', __( 'First name', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'text', 'name', __( 'Surname', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'email', 'email', __( 'E-mail', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'tel', 'phone', __( 'Phone', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'text', 'city', __( 'City', 'ux-studio' ) ),
				self::step( __( 'Experience', 'ux-studio' ), __( 'What have you done so far?', 'ux-studio' ) ),
				self::f(
					'select',
					'position',
					__( 'Position', 'ux-studio' ),
					array(
						'required' => true,
						'options'  => self::opts( array( __( 'Sales', 'ux-studio' ), __( 'Marketing', 'ux-studio' ), __( 'Development', 'ux-studio' ), __( 'Administration', 'ux-studio' ), __( 'Other', 'ux-studio' ) ) ),
						'w'        => array( 50, 50, 100 ),
					)
				),
				self::f(
					'select',
					'years',
					__( 'Years of experience', 'ux-studio' ),
					array(
						'options' => self::opts( array( __( 'No experience yet', 'ux-studio' ), __( '1 to 2 years', 'ux-studio' ), __( '3 to 5 years', 'ux-studio' ), __( 'More than 5 years', 'ux-studio' ) ) ),
						'w'       => array( 50, 50, 100 ),
					)
				),
				self::f( 'date', 'start_date', __( 'Earliest start', 'ux-studio' ), array( 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'url', 'profile_url', __( 'Profile or portfolio link', 'ux-studio' ), array( 'placeholder' => 'https://', 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'textarea', 'experience', __( 'Tell us about your experience', 'ux-studio' ), array( 'required' => true, 'rows' => 6 ) ),
				self::step( __( 'CV and consent', 'ux-studio' ), __( 'Attach your CV and confirm the consent.', 'ux-studio' ) ),
				self::f( 'file', 'cv', __( 'CV', 'ux-studio' ), array( 'required' => true, 'accept' => array( 'pdf', 'doc', 'docx' ), 'max_size_mb' => 5 ) ),
				self::consent( array( 'placeholder' => __( 'I agree to the processing of my personal data for the purpose of this selection procedure.', 'ux-studio' ) ) ),
				self::f(
					'acceptance',
					'consent_future',
					__( 'Keep my data for future vacancies', 'ux-studio' ),
					array( 'label_display' => 'placeholder_only', 'placeholder' => __( 'You may keep my application for six months and contact me about other vacancies (optional).', 'ux-studio' ) )
				),
			),
			'settings'         => array(
				'layout'         => 'card',
				'progress_style' => 'numbers',
				'submit_text'    => __( 'Send application', 'ux-studio' ),
				'success_text'   => __( 'Thank you, your application was sent. We will be in touch.', 'ux-studio' ),
				'actions'        => array(
					self::admin_mail( __( 'New application: {first_name} {name}', 'ux-studio' ) ),
					self::confirmation_mail(
						__( 'We received your application', 'ux-studio' ),
						'<p>' . __( 'Hello {first_name},', 'ux-studio' ) . '</p><p>' . __( 'thank you for your interest in working with us. We will review your application and contact you.', 'ux-studio' ) . '</p>'
					),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function order(): array {
		return array(
			'category'         => 'shop',
			'title'            => __( 'Order', 'ux-studio' ),
			'description'      => __( 'Numbered sections like a checkout: details, delivery with cards, summary.', 'ux-studio' ),
			'form_description' => __( 'Place an order in three short steps.', 'ux-studio' ),
			'fields'           => array(
				self::step( __( 'Your details', 'ux-studio' ), __( 'Who is ordering?', 'ux-studio' ) ),
				self::f( 'text', 'name', __( 'Name and surname', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'email', 'email', __( 'E-mail', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'tel', 'phone', __( 'Phone', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'text', 'company', __( 'Company (optional)', 'ux-studio' ), array( 'w' => array( 50, 50, 100 ) ) ),
				self::step( __( 'Delivery', 'ux-studio' ), __( 'How should we get it to you?', 'ux-studio' ) ),
				self::f(
					'radio',
					'delivery',
					__( 'Delivery method', 'ux-studio' ),
					array(
						'required'     => true,
						'choice_style' => 'cards',
						'options'      => self::opts( array( __( 'Courier to your door', 'ux-studio' ), __( 'Pickup point', 'ux-studio' ), __( 'Personal pickup', 'ux-studio' ) ) ),
					)
				),
				self::f( 'text', 'street', __( 'Street and number', 'ux-studio' ), array( 'required' => true ) ),
				self::f( 'text', 'city', __( 'City', 'ux-studio' ), array( 'required' => true, 'w' => array( 66, 66, 100 ) ) ),
				self::f( 'text', 'zip', __( 'Postcode', 'ux-studio' ), array( 'required' => true, 'w' => array( 33, 33, 100 ) ) ),
				self::step( __( 'Summary and consent', 'ux-studio' ), __( 'Check the order and confirm.', 'ux-studio' ) ),
				self::f( 'textarea', 'items', __( 'What are you ordering?', 'ux-studio' ), array( 'required' => true, 'rows' => 4, 'placeholder' => __( 'Product, variant and quantity', 'ux-studio' ) ) ),
				self::f(
					'radio',
					'payment',
					__( 'Payment', 'ux-studio' ),
					array(
						'required'     => true,
						'choice_style' => 'cards',
						'options'      => self::opts( array( __( 'Bank transfer', 'ux-studio' ), __( 'Card online', 'ux-studio' ), __( 'Cash on delivery', 'ux-studio' ) ) ),
					)
				),
				self::f( 'textarea', 'note', __( 'Note for the seller', 'ux-studio' ), array( 'rows' => 2 ) ),
				self::f(
					'acceptance',
					'terms',
					__( 'Terms and conditions', 'ux-studio' ),
					array(
						'required'      => true,
						'label_display' => 'placeholder_only',
						'placeholder'   => __( 'I have read the terms and conditions and I agree with them.', 'ux-studio' ),
					)
				),
				self::consent( array( 'placeholder' => __( 'I agree to the processing of my personal data for the purpose of handling this order.', 'ux-studio' ) ) ),
			),
			'settings'         => array(
				'layout'            => 'card',
				'columns'           => 1,
				'numbered_sections' => true,
				'progress_style'    => 'numbers',
				'submit_text'       => __( 'Place order', 'ux-studio' ),
				'success_text'      => __( 'Thank you, your order was received. We will confirm it by e-mail.', 'ux-studio' ),
				'actions'           => array(
					self::admin_mail( __( 'New order from {name}', 'ux-studio' ) ),
					self::confirmation_mail(
						__( 'We received your order', 'ux-studio' ),
						'<p>' . __( 'Hello {name},', 'ux-studio' ) . '</p><p>' . __( 'thank you for your order. We will confirm it and let you know when it ships.', 'ux-studio' ) . '</p>'
					),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function newsletter(): array {
		return array(
			'category'         => 'contact',
			'title'            => __( 'Newsletter signup', 'ux-studio' ),
			'description'      => __( 'Compact: e-mail and button in one row, consent underneath.', 'ux-studio' ),
			'form_description' => '',
			'fields'           => array(
				self::f( 'email', 'email', __( 'E-mail', 'ux-studio' ), array( 'required' => true, 'placeholder' => __( 'Your e-mail address', 'ux-studio' ), 'label_display' => 'placeholder_only', 'w' => array( 75, 100, 100 ) ) ),
				self::consent( array( 'placeholder' => __( 'I agree to receive the newsletter and to the processing of my e-mail address for that purpose.', 'ux-studio' ) ) ),
			),
			'settings'         => array(
				'layout'         => 'inline',
				'progress_style' => 'none',
				'submit_text'    => __( 'Subscribe', 'ux-studio' ),
				'success_text'   => __( 'Thank you for subscribing.', 'ux-studio' ),
				'actions'        => array(
					self::admin_mail( __( 'New newsletter subscriber', 'ux-studio' ) ),
					self::confirmation_mail(
						__( 'Welcome to our newsletter', 'ux-studio' ),
						'<p>' . __( 'Thank you for subscribing. We will send you news only now and then.', 'ux-studio' ) . '</p>'
					),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function feedback(): array {
		return array(
			'category'         => 'feedback',
			'title'            => __( 'Feedback and rating', 'ux-studio' ),
			'description'      => __( 'A 1 to 5 rating, what worked and what did not, optional reply.', 'ux-studio' ),
			'form_description' => __( 'Your opinion helps us improve. It takes a minute.', 'ux-studio' ),
			'fields'           => array(
				self::f(
					'radio',
					'rating',
					__( 'How satisfied are you? (1 = not at all, 5 = very)', 'ux-studio' ),
					array(
						'required'     => true,
						'choice_style' => 'inline',
						'options'      => self::opts( array( '1', '2', '3', '4', '5' ) ),
					)
				),
				self::f( 'textarea', 'liked', __( 'What did you like?', 'ux-studio' ), array( 'rows' => 3 ) ),
				self::f( 'textarea', 'improve', __( 'What could we do better?', 'ux-studio' ), array( 'rows' => 3 ) ),
				self::f(
					'radio',
					'recommend',
					__( 'Would you recommend us?', 'ux-studio' ),
					array( 'options' => self::opts( array( __( 'Yes', 'ux-studio' ), __( 'Maybe', 'ux-studio' ), __( 'No', 'ux-studio' ) ) ) )
				),
				self::f( 'checkbox', 'reply', __( 'Reply', 'ux-studio' ), array( 'label_display' => 'placeholder_only', 'placeholder' => __( 'I would like a reply to my feedback', 'ux-studio' ) ) ),
				self::f(
					'email',
					'email',
					__( 'E-mail for the reply', 'ux-studio' ),
					array(
						'required'   => true,
						'conditions' => array( array( 'field' => 'reply', 'operator' => 'not_empty', 'value' => '' ) ),
					)
				),
				self::consent( array( 'conditions' => array( array( 'field' => 'reply', 'operator' => 'not_empty', 'value' => '' ) ) ) ),
			),
			'settings'         => array(
				'layout'         => 'card',
				'progress_style' => 'none',
				'submit_text'    => __( 'Send feedback', 'ux-studio' ),
				'success_text'   => __( 'Thank you for your feedback.', 'ux-studio' ),
				'actions'        => array(
					self::admin_mail( __( 'New feedback: rating {rating}', 'ux-studio' ) ),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private static function complaint(): array {
		return array(
			'category'         => 'shop',
			'title'            => __( 'Complaint', 'ux-studio' ),
			'description'      => __( 'Two steps: order and contact, then the problem with photos.', 'ux-studio' ),
			'form_description' => __( 'Describe the problem and attach photos. We will resolve it quickly.', 'ux-studio' ),
			'fields'           => array(
				self::step( __( 'Order and contact', 'ux-studio' ), __( 'So we can find your purchase.', 'ux-studio' ) ),
				self::f( 'text', 'order_number', __( 'Order number', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'date', 'purchase_date', __( 'Date of purchase', 'ux-studio' ), array( 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'text', 'name', __( 'Name and surname', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::f( 'email', 'email', __( 'E-mail', 'ux-studio' ), array( 'required' => true, 'w' => array( 50, 50, 100 ) ) ),
				self::step( __( 'The problem', 'ux-studio' ), __( 'What is wrong with the goods?', 'ux-studio' ) ),
				self::f( 'text', 'product', __( 'Product', 'ux-studio' ), array( 'required' => true ) ),
				self::f(
					'radio',
					'reason',
					__( 'What happened?', 'ux-studio' ),
					array(
						'required' => true,
						'options'  => self::opts( array( __( 'Arrived damaged', 'ux-studio' ), __( 'Does not work', 'ux-studio' ), __( 'Differs from the description', 'ux-studio' ), __( 'Something else', 'ux-studio' ) ) ),
					)
				),
				self::f( 'textarea', 'description', __( 'Describe the problem', 'ux-studio' ), array( 'required' => true, 'rows' => 5 ) ),
				self::f( 'file', 'photos', __( 'Photos of the problem', 'ux-studio' ), array( 'multiple' => true, 'accept' => array( 'jpg', 'jpeg', 'png', 'webp' ), 'max_size_mb' => 8 ) ),
				self::f(
					'radio',
					'solution',
					__( 'How would you like it resolved?', 'ux-studio' ),
					array(
						'required'     => true,
						'choice_style' => 'cards',
						'options'      => self::opts( array( __( 'Refund', 'ux-studio' ), __( 'Replacement', 'ux-studio' ), __( 'Repair', 'ux-studio' ) ) ),
					)
				),
				self::consent( array( 'placeholder' => __( 'I agree to the processing of my personal data for the purpose of handling this complaint.', 'ux-studio' ) ) ),
			),
			'settings'         => array(
				'layout'         => 'card',
				'progress_style' => 'numbers',
				'submit_text'    => __( 'Send complaint', 'ux-studio' ),
				'success_text'   => __( 'Your complaint was received. We will respond within the legal deadline.', 'ux-studio' ),
				'actions'        => array(
					self::admin_mail( __( 'New complaint: order {order_number}', 'ux-studio' ) ),
					self::confirmation_mail(
						__( 'We received your complaint', 'ux-studio' ),
						'<p>' . __( 'Hello {name},', 'ux-studio' ) . '</p><p>' . __( 'we received your complaint for order {order_number} and will handle it as soon as possible.', 'ux-studio' ) . '</p>'
					),
				),
			),
		);
	}
}
