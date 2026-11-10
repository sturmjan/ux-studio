/**
 * Help for an option: a round "?" button next to the control that opens a floating window with what the option does,
 * what changes, what can go wrong, when to switch it off (and, in the onboarding wizard, when the starter pack
 * leaves it out). The wording comes from PHP (Core\SettingHelp, filled from each module's help.php), the same for
 * every module's settings screen and for the wizard. Texts may carry `[[term]]` glossary markers, see GlossaryTerm.tsx.
 *
 * Behaviour (the state machine and the placement are in help-tip-logic.ts, tested by tests/help-tip.mjs):
 *  - Mouse: hovering the button shows the window, leaving it closes it after a short grace period so the pointer can
 *    travel into the window and click a link. A click on the button (or in the window) pins it open.
 *  - Keyboard: focusing the button shows the window; Enter / Space pins it and moves the focus into the window so its
 *    links can be reached with Tab; Escape closes it and gives the focus back to the button; Tab past the last link
 *    closes it and continues from the button (the window is portalled to <body>, so its natural tab position is far
 *    away from the field).
 *  - Touch: a tap opens (pinned), a second tap or a tap outside closes.
 *  - The window is a non-modal `role="dialog"` (it holds interactive content, links and glossary terms, so a
 *    `role="tooltip"` would be wrong); the button carries aria-expanded, aria-controls and aria-describedby.
 */
import { createPortal } from 'react-dom';
import { useCallback, useEffect, useId, useLayoutEffect, useReducer, useRef, useState } from 'react';
import type { CSSProperties, KeyboardEvent as ReactKeyboardEvent, PointerEvent as ReactPointerEvent } from 'react';
import { CircleHelp } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';
import { HelpText } from './GlossaryTerm';
import { CLOSED, helpTipReducer, placeHelpTip } from './help-tip-logic';

export interface Explain {
	does: string;
	/** What changes for the site and its visitors. Empty for older entries. */
	effect?: string;
	risk?: string;
	off?: string;
	skip?: string;
}

/** Grace period after the pointer leaves the button or the window, in ms. */
const CLOSE_DELAY = 220;
/** Closes the help window that is open now when another one opens (only one at a time). */
let closeActive: ( () => void ) | null = null;

const HIDDEN: CSSProperties = { visibility: 'hidden' };
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

interface Props {
	explain: Explain;
	/** Name of the option, for the accessible name of the button ("Help: <name>"). */
	label: string;
}

export default function SettingExplain( { explain, label }: Props ): JSX.Element {
	const [ state, dispatch ] = useReducer( helpTipReducer, CLOSED );
	const [ style, setStyle ] = useState< CSSProperties >( HIDDEN );
	const buttonRef = useRef< HTMLButtonElement >( null );
	const panelRef = useRef< HTMLDivElement >( null );
	const timer = useRef< number | undefined >( undefined );
	const focusPanel = useRef( false );
	/** Set while the focus is given back to the button, so that this focus does not open the window again. */
	const restoring = useRef( false );
	const id = useId();

	const parts: Array< [ string, string | undefined ] > = [
		[ __( 'What it does', 'ux-studio' ), explain.does ],
		[ __( 'What changes', 'ux-studio' ), explain.effect ],
		[ __( 'Risk', 'ux-studio' ), explain.risk ],
		[ __( 'When to switch it off', 'ux-studio' ), explain.off ],
		[ __( 'When the starter pack leaves it out', 'ux-studio' ), explain.skip ],
	];

	const cancelRelease = useCallback( () => window.clearTimeout( timer.current ), [] );
	const scheduleRelease = useCallback( () => {
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => dispatch( { type: 'release' } ), CLOSE_DELAY );
	}, [] );
	useEffect( () => cancelRelease, [ cancelRelease ] );

	const focusButton = useCallback( () => {
		restoring.current = true;
		buttonRef.current?.focus();
		restoring.current = false;
	}, [] );

	const close = useCallback(
		( returnFocus: boolean ) => {
			cancelRelease();
			dispatch( { type: 'close' } );
			if ( returnFocus ) {
				focusButton();
			}
		},
		[ cancelRelease, focusButton ]
	);

	// Place the window next to the button (fixed, viewport coordinates), again on scroll and resize.
	const reposition = useCallback( () => {
		const button = buttonRef.current;
		const panel = panelRef.current;
		if ( ! button || ! panel ) {
			return;
		}
		const a = button.getBoundingClientRect();
		const p = placeHelpTip(
			{ left: a.left, top: a.top, width: a.width, height: a.height },
			{ width: panel.offsetWidth, height: panel.scrollHeight },
			{ width: document.documentElement.clientWidth, height: window.innerHeight }
		);
		setStyle( { left: p.left, top: p.top, maxHeight: p.maxHeight ?? undefined, visibility: 'visible' } );
	}, [] );

	useLayoutEffect( () => {
		if ( ! state.open ) {
			setStyle( HIDDEN );
			return;
		}
		reposition();
	}, [ state.open, reposition ] );

	useEffect( () => {
		if ( ! state.open ) {
			return;
		}
		// Only one help window at a time.
		const self = () => dispatch( { type: 'close' } );
		if ( closeActive && closeActive !== self ) {
			closeActive();
		}
		closeActive = self;

		let frame = 0;
		const again = () => {
			window.cancelAnimationFrame( frame );
			frame = window.requestAnimationFrame( reposition );
		};
		const outside = ( e: PointerEvent ) => {
			const target = e.target as Element | null;
			if ( target && ( buttonRef.current?.contains( target ) || panelRef.current?.contains( target ) || target.closest( '.uxs-gloss__popover' ) ) ) {
				return;
			}
			dispatch( { type: 'close' } );
		};
		const escape = ( e: KeyboardEvent ) => {
			if ( 'Escape' === e.key && ! e.defaultPrevented ) {
				const inside = panelRef.current?.contains( document.activeElement );
				dispatch( { type: 'close' } );
				if ( inside ) {
					focusButton();
				}
			}
		};
		window.addEventListener( 'resize', again );
		window.addEventListener( 'scroll', again, true );
		document.addEventListener( 'pointerdown', outside, true );
		document.addEventListener( 'keydown', escape );
		return () => {
			window.cancelAnimationFrame( frame );
			window.removeEventListener( 'resize', again );
			window.removeEventListener( 'scroll', again, true );
			document.removeEventListener( 'pointerdown', outside, true );
			document.removeEventListener( 'keydown', escape );
			if ( closeActive === self ) {
				closeActive = null;
			}
		};
	}, [ state.open, reposition, focusButton ] );

	// Opened with the keyboard (Enter / Space): move the focus into the window so its links can be reached.
	useEffect( () => {
		if ( state.open && state.pinned && focusPanel.current ) {
			focusPanel.current = false;
			panelRef.current?.focus( { preventScroll: true } );
		}
	}, [ state.open, state.pinned ] );

	const mouseLike = ( e: ReactPointerEvent ): boolean => 'touch' !== e.pointerType;

	const onPanelKeyDown = ( e: ReactKeyboardEvent< HTMLDivElement > ) => {
		if ( 'Tab' !== e.key ) {
			return;
		}
		const panel = panelRef.current;
		if ( ! panel ) {
			return;
		}
		const items = Array.from( panel.querySelectorAll< HTMLElement >( FOCUSABLE ) );
		const active = document.activeElement;
		const first = items[ 0 ] ?? panel;
		const last = items[ items.length - 1 ] ?? panel;
		const leavingBackwards = e.shiftKey && ( active === panel || active === first );
		const leavingForwards = ! e.shiftKey && ( 0 === items.length || active === last );
		if ( leavingBackwards || leavingForwards ) {
			e.preventDefault();
			close( true );
		}
	};

	const theme = document.getElementById( 'ux-studio-root' )?.getAttribute( 'data-theme' ) ?? 'light';

	return (
		<>
			<button
				ref={ buttonRef }
				type="button"
				className={ `uxs-tipbtn${ state.open ? ' is-open' : '' }` }
				// translators: %s: the name of a setting.
				aria-label={ sprintf( __( 'Help: %s', 'ux-studio' ), label ) }
				aria-haspopup="dialog"
				aria-expanded={ state.open }
				aria-controls={ state.open ? id : undefined }
				aria-describedby={ state.open ? id : undefined }
				onPointerEnter={ ( e ) => {
					if ( mouseLike( e ) ) {
						cancelRelease();
						dispatch( { type: 'peek' } );
					}
				} }
				onPointerLeave={ ( e ) => {
					if ( mouseLike( e ) ) {
						scheduleRelease();
					}
				} }
				onFocus={ ( e ) => {
					// Only keyboard focus peeks; a mouse click gives focus too, but its click decides.
					if ( ! restoring.current && e.currentTarget.matches( ':focus-visible' ) ) {
						cancelRelease();
						dispatch( { type: 'peek' } );
					}
				} }
				onBlur={ ( e ) => {
					if ( ! panelRef.current?.contains( e.relatedTarget as Node | null ) ) {
						scheduleRelease();
					}
				} }
				onClick={ ( e ) => {
					cancelRelease();
					focusPanel.current = ! state.pinned && 0 === e.detail;
					dispatch( { type: 'toggle' } );
				} }
			>
				<CircleHelp size={ 18 } aria-hidden="true" />
			</button>
			{ state.open
				? createPortal(
						<div
							ref={ panelRef }
							id={ id }
							role="dialog"
							aria-label={ label }
							tabIndex={ -1 }
							data-theme={ theme }
							data-pinned={ state.pinned ? 'true' : undefined }
							className="uxs-tip uxs-portal"
							style={ style }
							onPointerEnter={ ( e ) => mouseLike( e ) && cancelRelease() }
							onPointerLeave={ ( e ) => mouseLike( e ) && scheduleRelease() }
							onClickCapture={ () => {
								cancelRelease();
								dispatch( { type: 'pin' } );
							} }
							onBlur={ ( e ) => {
								const to = e.relatedTarget as Element | null;
								if ( ! e.currentTarget.contains( to ) && to !== buttonRef.current && ! to?.closest( '.uxs-gloss__popover' ) ) {
									scheduleRelease();
								}
							} }
							onKeyDown={ onPanelKeyDown }
						>
							<dl>
								{ parts.map( ( [ title, text ] ) =>
									text ? (
										<div key={ title } className="uxs-tip__part">
											<dt>{ title }</dt>
											<dd>
												<HelpText text={ text } />
											</dd>
										</div>
									) : null
								) }
							</dl>
						</div>,
						document.body
				  )
				: null }
		</>
	);
}
