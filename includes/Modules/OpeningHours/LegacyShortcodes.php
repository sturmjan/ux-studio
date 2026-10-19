<?php
/**
 * Legacy [oh_*] shortcodes of the old UX1 opening-hours module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\OpeningHours;

defined( 'ABSPATH' ) || exit;

/**
 * The shortcode names, attributes and CSS classes are embedded in live content
 * and theme templates, so they are kept 1:1: oh_status, oh_week, oh_banner,
 * oh_next_open, oh_list. oh_map and oh_widget (maps / decorative widgets) were
 * deliberately dropped; they are still registered so that existing content
 * does not print the raw shortcode (oh_map renders nothing, oh_widget falls
 * back to the card / status badge).
 *
 * Theme overrides: {theme}/ux-studio/opening-hours/{name}.php (new) and
 * {theme}/ux1-opening-hours/{name}.php (legacy path), child theme first. The
 * template receives the same variables as in the legacy plugin ($ids, $atts,
 * plus $status / $today / $days where the legacy shortcode passed them).
 */
final class LegacyShortcodes {

	private const DAY_KEYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	private Module $module;

	private Frontend $frontend;

	/** Whether the stylesheet / switcher script was already printed. */
	private static bool $printed_css    = false;
	private static bool $printed_script = false;

	/**
	 * @param Module   $module   Owning module.
	 * @param Frontend $frontend New-style renderer (used for the oh_widget fallback).
	 */
	public function __construct( Module $module, Frontend $frontend ) {
		$this->module   = $module;
		$this->frontend = $frontend;
	}

	/**
	 * Register the legacy shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'oh_status', array( $this, 'render_status' ) );
		add_shortcode( 'oh_week', array( $this, 'render_week' ) );
		add_shortcode( 'oh_banner', array( $this, 'render_banner' ) );
		add_shortcode( 'oh_next_open', array( $this, 'render_next_open' ) );
		add_shortcode( 'oh_list', array( $this, 'render_list' ) );
		add_shortcode( 'oh_map', '__return_empty_string' );
		add_shortcode( 'oh_widget', array( $this, 'render_widget' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Shortcodes
	 * ------------------------------------------------------------------ */

	/**
	 * [oh_status location="" format="badge" template=""]
	 *
	 * @param array|string $atts Attributes.
	 */
	public function render_status( $atts ): string {
		$atts = shortcode_atts(
			array(
				'location' => '',
				'format'   => 'badge',
				'template' => '',
			),
			(array) $atts,
			'oh_status'
		);
		$ids  = $this->resolve_locations( $atts );
		if ( empty( $ids ) ) {
			return '';
		}

		$statuses = array();
		foreach ( $ids as $id ) {
			$statuses[] = $this->legacy_status( $id );
		}
		$custom = $this->render_template(
			'' !== $atts['template'] ? $atts['template'] : 'status',
			array(
				'ids'    => $ids,
				'atts'   => $atts,
				'status' => $statuses,
			)
		);
		if ( null !== $custom ) {
			return $custom;
		}

		$dropdown = $this->show_dropdown();
		$out      = $this->css();
		if ( count( $ids ) > 1 && $dropdown ) {
			$out .= $this->dropdown( $ids, 'status' );
		}
		$out .= '<div class="ux1-oh-widget" data-ux1-oh-widget="status">';
		foreach ( $ids as $i => $id ) {
			$status = $statuses[ $i ];
			$hidden = ( $i > 0 && $dropdown ) ? ' hidden' : '';
			$cls    = $status['open'] ? 'ux1-oh-status--open' : 'ux1-oh-status--closed';
			$label  = $status['open'] ? __( 'Open now', 'ux-studio' ) : __( 'Closed now', 'ux-studio' );
			$until  = '';
			if ( ! empty( $status['until'] ) ) {
				/* translators: %s: time (HH:MM). */
				$until_text = $status['open'] ? __( 'until %s', 'ux-studio' ) : __( 'opens at %s', 'ux-studio' );
				$until      = '<span class="ux1-oh-status__until"> · ' . esc_html( sprintf( $until_text, $status['until'] ) ) . '</span>';
			}
			$out .= sprintf(
				'<span class="ux1-oh-status %s" data-location="%d"%s><span class="ux1-oh-status__dot"></span><span class="ux1-oh-status__label">%s</span>%s</span>',
				esc_attr( $cls ),
				(int) $id,
				$hidden,
				esc_html( $label ),
				$until
			);
		}
		$out .= '</div>';
		return $out . $this->switch_script( $dropdown && count( $ids ) > 1 );
	}

	/**
	 * [oh_week location="" template=""]
	 *
	 * @param array|string $atts Attributes.
	 */
	public function render_week( $atts ): string {
		$atts = shortcode_atts(
			array(
				'location' => '',
				'template' => '',
			),
			(array) $atts,
			'oh_week'
		);
		$ids  = $this->resolve_locations( $atts );
		if ( empty( $ids ) ) {
			return '';
		}

		$today  = $this->today();
		$labels = $this->day_labels();
		$custom = $this->render_template(
			'' !== $atts['template'] ? $atts['template'] : 'week',
			array(
				'ids'   => $ids,
				'atts'  => $atts,
				'today' => $today,
				'days'  => $labels,
			)
		);
		if ( null !== $custom ) {
			return $custom;
		}

		$dropdown = $this->show_dropdown();
		$out      = $this->css();
		if ( count( $ids ) > 1 && $dropdown ) {
			$out .= $this->dropdown( $ids, 'week' );
		}
		$out .= '<div class="ux1-oh-widget" data-ux1-oh-widget="week">';
		foreach ( $ids as $i => $id ) {
			$hidden = ( $i > 0 && $dropdown ) ? ' hidden' : '';
			$status = $this->legacy_status( $id );
			$out   .= sprintf( '<table class="ux1-oh-week" data-location="%d"%s><tbody>', (int) $id, $hidden );
			foreach ( self::DAY_KEYS as $key ) {
				$row = $status['week'][ $key ] ?? null;
				if ( ! $row ) {
					continue;
				}
				$row_cls = $row['date'] === $today ? ' class="is-today"' : '';
				if ( empty( $row['blocks'] ) ) {
					$blocks = '<em>' . esc_html__( 'Closed', 'ux-studio' ) . '</em>';
				} else {
					$parts = array();
					foreach ( $row['blocks'] as $b ) {
						$parts[] = esc_html( $b['from'] . '–' . $b['to'] );
					}
					$blocks = implode( ', ', $parts );
				}
				if ( ! empty( $row['message'] ) ) {
					$blocks .= ' <small>(' . esc_html( (string) $row['message'] ) . ')</small>';
				}
				$out .= sprintf( '<tr%s><th>%s</th><td>%s</td></tr>', $row_cls, esc_html( $labels[ $key ]['long'] ), $blocks );
			}
			$out .= '</tbody></table>';
		}
		$out .= '</div>';
		return $out . $this->switch_script( $dropdown && count( $ids ) > 1 );
	}

	/**
	 * [oh_banner location="" template=""] - active special announcements.
	 *
	 * @param array|string $atts Attributes.
	 */
	public function render_banner( $atts ): string {
		$atts = shortcode_atts(
			array(
				'location' => '',
				'template' => '',
			),
			(array) $atts,
			'oh_banner'
		);
		$ids  = $this->resolve_locations( $atts );
		if ( empty( $ids ) ) {
			return '';
		}

		$custom = $this->render_template(
			'' !== $atts['template'] ? $atts['template'] : 'banner',
			array(
				'ids'  => $ids,
				'atts' => $atts,
			)
		);
		if ( null !== $custom ) {
			return $custom;
		}

		$colors = array(
			'info'     => (string) $this->module->setting( 'severity_info_color', '#2271b1' ),
			'warning'  => (string) $this->module->setting( 'severity_warning_color', '#dba617' ),
			'critical' => (string) $this->module->setting( 'severity_critical_color', '#d63638' ),
		);

		$out = '';
		foreach ( $ids as $id ) {
			$status = $this->legacy_status( $id );
			$a      = $status['announcement'];
			if ( ! $a || '' === (string) $a['text'] ) {
				continue;
			}
			$sev    = (string) $a['severity'];
			$color  = sanitize_hex_color( $colors[ $sev ] ?? '' );
			$color  = $color ? $color : ( sanitize_hex_color( (string) $this->module->setting( 'banner_color', '#1d3998' ) ) ?: '#1d3998' );
			$out   .= sprintf(
				'<div class="ux1-oh-banner ux1-oh-banner--%s" data-location="%d" style="--ux1-oh-banner: %s;">%s</div>',
				esc_attr( $sev ),
				(int) $id,
				esc_attr( $color ),
				esc_html( (string) $a['text'] )
			);
		}
		return '' === $out ? '' : $this->css() . $out;
	}

	/**
	 * [oh_next_open location="" format="short|long|datetime" prefix="" template=""]
	 *
	 * @param array|string $atts Attributes.
	 */
	public function render_next_open( $atts ): string {
		$atts = shortcode_atts(
			array(
				'location' => '',
				'format'   => 'short',
				'prefix'   => '',
				'template' => '',
			),
			(array) $atts,
			'oh_next_open'
		);
		$ids  = $this->resolve_locations( $atts );
		if ( empty( $ids ) ) {
			return '';
		}

		$custom = $this->render_template(
			'' !== $atts['template'] ? $atts['template'] : 'next-open',
			array(
				'ids'  => $ids,
				'atts' => $atts,
			)
		);
		if ( null !== $custom ) {
			return $custom;
		}

		$tz       = wp_timezone();
		$dropdown = $this->show_dropdown();
		$out      = $this->css() . '<div class="ux1-oh-widget" data-ux1-oh-widget="next-open">';
		foreach ( $ids as $i => $id ) {
			$hidden = ( $i > 0 && $dropdown ) ? ' hidden' : '';
			$status = $this->legacy_status( $id );
			$line   = '';
			if ( $status['open'] ) {
				/* translators: %s: closing time (HH:MM). */
				$line = $status['until'] ? sprintf( __( 'Open until %s', 'ux-studio' ), $status['until'] ) : __( 'Open', 'ux-studio' );
			} else {
				$next_ts = $this->module->next_open_timestamp( $id );
				if ( null === $next_ts ) {
					$line = __( 'No opening currently scheduled', 'ux-studio' );
				} else {
					$diff      = $next_ts - time();
					$today     = $this->today();
					$next_date = wp_date( 'Y-m-d', $next_ts, $tz );
					$time_str  = wp_date( 'H:i', $next_ts, $tz );
					$tomorrow  = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS, $tz );

					if ( 'datetime' === $atts['format'] ) {
						/* translators: %s: date and time. */
						$line = sprintf( __( 'We open %s', 'ux-studio' ), wp_date( 'j. n. Y H:i', $next_ts, $tz ) );
					} elseif ( 'long' === $atts['format'] ) {
						if ( $diff < HOUR_IN_SECONDS ) {
							/* translators: %d: minutes. */
							$line = sprintf( __( 'We open in %d min', 'ux-studio' ), max( 1, (int) round( $diff / 60 ) ) );
						} elseif ( $next_date === $today ) {
							/* translators: %s: time. */
							$line = sprintf( __( 'We open today at %s', 'ux-studio' ), $time_str );
						} elseif ( $next_date === $tomorrow ) {
							/* translators: %s: time. */
							$line = sprintf( __( 'We open tomorrow at %s', 'ux-studio' ), $time_str );
						} else {
							/* translators: 1: weekday and date, 2: time. */
							$line = sprintf( __( 'We open %1$s at %2$s', 'ux-studio' ), wp_date( 'l j. n.', $next_ts, $tz ), $time_str );
						}
					} elseif ( $next_date === $today ) {
						/* translators: %s: time. */
						$line = sprintf( __( 'We open at %s', 'ux-studio' ), $time_str );
					} else {
						/* translators: 1: date, 2: time. */
						$line = sprintf( __( 'We open %1$s %2$s', 'ux-studio' ), wp_date( 'j. n.', $next_ts, $tz ), $time_str );
					}
				}
			}

			$prefix = '' !== $atts['prefix'] ? esc_html( $atts['prefix'] ) . ' ' : '';
			$out   .= sprintf(
				'<span class="ux1-oh-next-open" data-location="%d"%s>%s%s</span>',
				(int) $id,
				$hidden,
				$prefix,
				esc_html( $line )
			);
		}
		return $out . '</div>' . $this->switch_script( $dropdown && count( $ids ) > 1 );
	}

	/**
	 * [oh_list open="now" columns="2" show="address,phone,status" template=""]
	 *
	 * @param array|string $atts Attributes.
	 */
	public function render_list( $atts ): string {
		$atts = shortcode_atts(
			array(
				'open'     => '',
				'columns'  => 2,
				'show'     => 'address,phone,status',
				'template' => '',
			),
			(array) $atts,
			'oh_list'
		);
		$ids  = $this->resolve_locations( array( 'location' => '' ) );
		if ( empty( $ids ) ) {
			return '';
		}

		$custom = $this->render_template(
			'' !== $atts['template'] ? $atts['template'] : 'list',
			array(
				'ids'  => $ids,
				'atts' => $atts,
			)
		);
		if ( null !== $custom ) {
			return $custom;
		}

		$show      = array_filter( array_map( 'trim', explode( ',', (string) $atts['show'] ) ) );
		$cols      = max( 1, min( 6, (int) $atts['columns'] ) );
		$only_open = 'now' === $atts['open'];

		$out = $this->css() . sprintf( '<div class="ux1-oh-list" style="display:grid;grid-template-columns:repeat(%d,1fr);gap:1em;">', $cols );
		foreach ( $ids as $id ) {
			$location = $this->module->get_location( $id );
			if ( null === $location ) {
				continue;
			}
			$status = $this->legacy_status( $id );
			if ( $only_open && empty( $status['open'] ) ) {
				continue;
			}

			$out .= '<div class="ux1-oh-list__item" data-location="' . (int) $id . '">';
			$out .= '<h4 class="ux1-oh-list__title">' . esc_html( (string) $location['title'] ) . '</h4>';
			if ( in_array( 'address', $show, true ) && '' !== (string) $location['address'] ) {
				$out .= '<p class="ux1-oh-list__addr">' . esc_html( (string) $location['address'] ) . '</p>';
			}
			if ( in_array( 'phone', $show, true ) && '' !== (string) $location['phone'] ) {
				$out .= '<p class="ux1-oh-list__phone"><a href="tel:' . esc_attr( (string) preg_replace( '/[^0-9+]/', '', (string) $location['phone'] ) ) . '">' . esc_html( (string) $location['phone'] ) . '</a></p>';
			}
			if ( in_array( 'status', $show, true ) ) {
				$cls   = $status['open'] ? 'ux1-oh-status--open' : 'ux1-oh-status--closed';
				$label = $status['open'] ? __( 'Open now', 'ux-studio' ) : __( 'Closed now', 'ux-studio' );
				$until = ! empty( $status['until'] ) ? ' · ' . esc_html( (string) $status['until'] ) : '';
				$out  .= sprintf( '<span class="ux1-oh-status %s"><span class="ux1-oh-status__dot"></span> %s%s</span>', esc_attr( $cls ), esc_html( $label ), $until );
			}
			$out .= '</div>';
		}
		return $out . '</div>';
	}

	/**
	 * [oh_widget type="card|pill|clock|minimap" location=""] - decorative
	 * widgets were not ported; degrade to the card (type=card) or a status badge.
	 *
	 * @param array|string $atts Attributes.
	 */
	public function render_widget( $atts ): string {
		$atts = shortcode_atts(
			array(
				'type'     => 'card',
				'variant'  => 'default',
				'location' => '',
			),
			(array) $atts,
			'oh_widget'
		);
		if ( 'minimap' === $atts['type'] ) {
			return '';
		}
		if ( 'card' === $atts['type'] ) {
			return $this->frontend->render_card( array( 'location' => $atts['location'] ) );
		}
		return $this->render_status( array( 'location' => $atts['location'] ) );
	}

	/* ------------------------------------------------------------------ *
	 * Data helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Location ids: explicit `location`, else all (max 50, by title).
	 *
	 * @param array $atts Attributes with `location`.
	 * @return int[]
	 */
	private function resolve_locations( array $atts ): array {
		$explicit = isset( $atts['location'] ) && '' !== $atts['location'] ? (int) $atts['location'] : 0;
		if ( $explicit > 0 ) {
			return null !== $this->module->get_location( $explicit ) ? array( $explicit ) : array();
		}
		$ids = array_map( static fn ( array $l ): int => (int) $l['id'], $this->module->list_locations() );
		return array_slice( $ids, 0, 50 );
	}

	/**
	 * Status in the shape the legacy templates expect: open, until (HH:MM),
	 * today_blocks/week blocks as {from,to}, message, announcement, timezone,
	 * override.
	 *
	 * @param int $id Location id.
	 * @return array<string, mixed>
	 */
	private function legacy_status( int $id ): array {
		$empty = array(
			'open'          => false,
			'until'         => null,
			'today_blocks'  => array(),
			'today_message' => null,
			'week'          => array(),
			'message'       => null,
			'announcement'  => null,
			'timezone'      => wp_timezone_string(),
			'override'      => null,
		);
		$location = $this->module->get_location( $id );
		if ( null === $location ) {
			return $empty;
		}

		$tz      = wp_timezone();
		$now     = current_datetime();
		$today   = $now->format( 'Y-m-d' );
		$status  = $this->module->compute_status( $id );
		$open    = is_array( $status ) && ! empty( $status['open'] );
		$message = $this->module->day_note( $location, $today );

		$until = null;
		if ( $open && is_array( $status ) && ! empty( $status['next_change'] ) ) {
			$until = ( new \DateTimeImmutable( (string) $status['next_change'] ) )->setTimezone( $tz )->format( 'H:i' );
		} elseif ( ! $open ) {
			$next = $this->module->next_open_timestamp( $id );
			if ( null !== $next ) {
				$tomorrow = $now->modify( '+1 day' )->format( 'Y-m-d' );
				if ( wp_date( 'Y-m-d', $next, $tz ) <= $tomorrow ) {
					$until = wp_date( 'H:i', $next, $tz );
				}
			}
		}

		$today_blocks = $this->to_blocks( $this->module->day_ranges( $location, $today ) );
		$override     = $location['override'];
		if ( is_array( $override ) ) {
			$open         = false;
			$until        = wp_date( 'H:i', (int) $override['until'], $tz );
			$today_blocks = array();
			$message      = '' !== (string) $override['reason'] ? (string) $override['reason'] : __( 'Temporarily closed', 'ux-studio' );
		}

		$week   = array();
		$monday = $now->modify( '-' . ( (int) $now->format( 'N' ) - 1 ) . ' days' );
		foreach ( self::DAY_KEYS as $i => $key ) {
			$date        = $monday->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$week[ $key ] = array(
				'date'    => $date,
				'blocks'  => $this->to_blocks( $this->module->day_ranges( $location, $date ) ),
				'message' => '' !== $this->module->day_note( $location, $date ) ? $this->module->day_note( $location, $date ) : null,
			);
		}

		return array(
			'open'          => $open,
			'until'         => $until,
			'today_blocks'  => $today_blocks,
			'today_message' => '' !== $message ? $message : null,
			'week'          => $week,
			'message'       => '' !== $message ? $message : null,
			'announcement'  => Module::active_announcement( (array) $location['announcements'], $today ),
			'timezone'      => wp_timezone_string(),
			'override'      => $override,
		);
	}

	/**
	 * {open,close} ranges -> legacy {from,to} blocks.
	 *
	 * @param array $ranges Ranges.
	 * @return array<int, array{from:string,to:string}>
	 */
	private function to_blocks( array $ranges ): array {
		$blocks = array();
		foreach ( $ranges as $range ) {
			$blocks[] = array(
				'from' => (string) ( $range['open'] ?? '' ),
				'to'   => (string) ( $range['close'] ?? '' ),
			);
		}
		return $blocks;
	}

	/**
	 * Localized weekday labels in the legacy MetaBoxes::getDayLabels() shape.
	 *
	 * @return array<string, array{long:string,short:string}>
	 */
	private function day_labels(): array {
		$labels = array();
		$base   = new \DateTimeImmutable( '2024-01-01', wp_timezone() ); // A Monday.
		foreach ( self::DAY_KEYS as $i => $key ) {
			$ts            = $base->modify( '+' . $i . ' day' )->getTimestamp();
			$labels[ $key ] = array(
				'long'  => wp_date( 'l', $ts ),
				'short' => wp_date( 'D', $ts ),
			);
		}
		return $labels;
	}

	private function today(): string {
		return current_datetime()->format( 'Y-m-d' );
	}

	private function show_dropdown(): bool {
		return (bool) $this->module->setting( 'show_location_dropdown', false );
	}

	/* ------------------------------------------------------------------ *
	 * Templates
	 * ------------------------------------------------------------------ */

	/**
	 * Locate a theme override. Order: child {ux-studio/opening-hours}, child
	 * {ux1-opening-hours} (legacy), parent new, parent legacy.
	 *
	 * @param string $name Template name without extension.
	 */
	public static function locate_template( string $name ): ?string {
		$name = sanitize_file_name( $name );
		if ( '' === $name || false !== strpos( $name, '..' ) ) {
			return null;
		}
		$dirs = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		foreach ( $dirs as $dir ) {
			foreach ( array( 'ux-studio/opening-hours/', 'ux1-opening-hours/' ) as $rel ) {
				$path = trailingslashit( $dir ) . $rel . $name . '.php';
				if ( is_file( $path ) ) {
					return $path;
				}
			}
		}
		return null;
	}

	/**
	 * Render a theme override, or null when none exists.
	 *
	 * @param string               $name Template name.
	 * @param array<string, mixed> $vars Variables extracted into the template.
	 */
	private function render_template( string $name, array $vars ): ?string {
		$path = self::locate_template( $name );
		if ( null === $path ) {
			return null;
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- legacy template contract.
		ob_start();
		include $path;
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ *
	 * Markup helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @param int[]  $ids    Location ids.
	 * @param string $target data-target of the widget to switch.
	 */
	private function dropdown( array $ids, string $target ): string {
		$opts = '';
		foreach ( $ids as $id ) {
			$location = $this->module->get_location( $id );
			$opts    .= sprintf( '<option value="%d">%s</option>', (int) $id, esc_html( (string) ( $location['title'] ?? '' ) ) );
		}
		return sprintf(
			'<div class="ux1-oh-location-select"><label>%s <select data-ux1-oh-switch data-target="%s">%s</select></label></div>',
			esc_html__( 'Location:', 'ux-studio' ),
			esc_attr( $target ),
			$opts
		);
	}

	/**
	 * Inline switcher for the location dropdown (once per page).
	 *
	 * @param bool $needed Whether a dropdown was rendered.
	 */
	private function switch_script( bool $needed ): string {
		if ( ! $needed || self::$printed_script ) {
			return '';
		}
		self::$printed_script = true;
		$js                   = "document.querySelectorAll('[data-ux1-oh-switch]').forEach(function(s){s.addEventListener('change',function(){"
			. "var t=s.getAttribute('data-target'),v=s.value;"
			. "document.querySelectorAll('[data-ux1-oh-widget=\"'+t+'\"] [data-location]').forEach(function(l){l.hidden=l.getAttribute('data-location')!==v;});});});";
		return wp_get_inline_script_tag( $js );
	}

	/**
	 * Legacy stylesheet, printed at most once per request.
	 */
	private function css(): string {
		if ( self::$printed_css ) {
			return '';
		}
		self::$printed_css = true;
		$banner            = sanitize_hex_color( (string) $this->module->setting( 'banner_color', '#1d3998' ) ) ?: '#1d3998';

		return '<style>'
			. ':root{--ux1-oh-banner:' . $banner . '}'
			. '.ux1-oh-status{display:inline-flex;align-items:center;gap:8px;font-size:14px}'
			. '.ux1-oh-status__dot{display:inline-block;width:10px;height:10px;border-radius:50%}'
			. '.ux1-oh-status--open .ux1-oh-status__dot{background:#00a32a}'
			. '.ux1-oh-status--closed .ux1-oh-status__dot{background:#d63638}'
			. '.ux1-oh-status__label{font-weight:600}.ux1-oh-status__until{color:#555}'
			. '.ux1-oh-week{width:100%;border-collapse:collapse;font-size:14px}'
			. '.ux1-oh-week th,.ux1-oh-week td{padding:6px 10px;border-bottom:1px solid #eee;text-align:left}'
			. '.ux1-oh-week tr.is-today{background:#f6faff;font-weight:600}'
			. '.ux1-oh-banner{padding:10px 14px;border-left:4px solid var(--ux1-oh-banner,#1d3998);background:#f6f7f7;margin:10px 0}'
			. '.ux1-oh-banner--critical{background:#fcf0f1}'
			. '.ux1-oh-location-select{margin-bottom:10px}'
			. '</style>';
	}
}
