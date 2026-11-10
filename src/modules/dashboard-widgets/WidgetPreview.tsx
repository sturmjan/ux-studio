/**
 * Preview of one dashboard widget: an eye button that, on hover or keyboard focus (or a tap), opens a small popover with the
 * widget drawn live by the server (admin-ajax, administrators only, nonce) inside a frame that is scaled down.
 *
 * The server page says "Preview not available" by itself when a widget cannot be drawn; a frame that does not answer in
 * time gets the same message here.
 */
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { __, sprintf } from '@wordpress/i18n';
import { Eye, LoaderCircle } from 'lucide-react';

/** The widget is drawn at this size and shown scaled down to 75 %, so it fills about 420 x 315 px. */
const FRAME_W = 560;
const FRAME_H = 420;
const SCALE = 0.75;
const BOX_W = Math.round( FRAME_W * SCALE );
const BOX_H = Math.round( FRAME_H * SCALE );
const OPEN_DELAY = 350;
const CLOSE_DELAY = 120;
const TIMEOUT = 12_000;

interface Pos {
	left: number;
	top: number;
}

/** Next to the trigger, flipped when there is no room, always inside the window. */
function place( rect: DOMRect, boxH: number = BOX_H ): Pos {
	const gap = 8;
	const width = BOX_W + 2;
	const height = boxH + 2;
	let left = rect.left;
	if ( left + width > window.innerWidth - 8 ) {
		left = window.innerWidth - width - 8;
	}
	let top = rect.bottom + gap;
	if ( top + height > window.innerHeight - 8 ) {
		top = rect.top - height - gap;
	}
	return { left: Math.max( 8, left ), top: Math.max( 8, top ) };
}

export default function WidgetPreview( { id, title, base }: { id: string; title: string; base: string } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const [ pos, setPos ] = useState< Pos >( { left: 0, top: 0 } );
	const [ ready, setReady ] = useState( false );
	const [ failed, setFailed ] = useState( false );
	// Height of the drawn widget in frame pixels: a small widget gets a small box, not a grey empty area.
	const [ frameH, setFrameH ] = useState( FRAME_H );
	const trigger = useRef< HTMLButtonElement >( null );
	const frame = useRef< HTMLIFrameElement >( null );
	const timer = useRef< number | undefined >( undefined );
	const popId = useId();

	const clear = (): void => window.clearTimeout( timer.current );
	const show = useCallback( ( delay: number ): void => {
		clear();
		timer.current = window.setTimeout( () => setOpen( true ), delay );
	}, [] );
	const hide = useCallback( ( delay: number ): void => {
		clear();
		timer.current = window.setTimeout( () => setOpen( false ), delay );
	}, [] );

	useEffect( () => clear, [] );

	// Every opening starts with a fresh frame.
	useEffect( () => {
		if ( open ) {
			setReady( false );
			setFailed( false );
			setFrameH( FRAME_H );
		}
	}, [ open ] );

	const boxH = Math.round( frameH * SCALE );

	useLayoutEffect( () => {
		if ( open && trigger.current ) {
			setPos( place( trigger.current.getBoundingClientRect(), boxH ) );
		}
	}, [ open, boxH ] );

	/** The frame is on the same origin, so its content height can be read (again a moment later for late images). */
	const measure = useCallback( (): void => {
		try {
			const doc = frame.current?.contentDocument;
			// The admin styles give html and body the full frame height, so measure the widget box itself.
			const box = doc?.querySelector( '.postbox, #welcome-panel' );
			const h = box ? Math.ceil( box.getBoundingClientRect().bottom + 16 ) : 0;
			if ( h > 0 ) {
				setFrameH( Math.min( FRAME_H, Math.max( 120, h ) ) );
			}
		} catch {
			// A frame that cannot be read keeps the full size.
		}
	}, [] );

	// A frame that stays silent is reported as not available.
	useEffect( () => {
		if ( ! open || ready ) {
			return undefined;
		}
		const t = window.setTimeout( () => setFailed( true ), TIMEOUT );
		return () => window.clearTimeout( t );
	}, [ open, ready ] );

	// The popover would stay over a scrolled page; close it on scroll and on Escape.
	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const close = (): void => setOpen( false );
		const key = ( e: KeyboardEvent ): void => {
			if ( 'Escape' === e.key ) {
				setOpen( false );
			}
		};
		window.addEventListener( 'scroll', close, true );
		window.addEventListener( 'resize', close );
		document.addEventListener( 'keydown', key );
		return () => {
			window.removeEventListener( 'scroll', close, true );
			window.removeEventListener( 'resize', close );
			document.removeEventListener( 'keydown', key );
		};
	}, [ open ] );

	const src = `${ base }&widget=${ encodeURIComponent( id ) }`;
	const theme = document.getElementById( 'ux-studio-root' )?.getAttribute( 'data-theme' ) ?? 'light';

	return (
		<>
			<button
				ref={ trigger }
				type="button"
				className="uxs-wt__eye"
				aria-label={ sprintf(
					/* translators: %s: widget name. */
					__( 'Preview of %s', 'ux-studio' ),
					title
				) }
				aria-describedby={ open ? popId : undefined }
				aria-expanded={ open }
				onMouseEnter={ () => show( OPEN_DELAY ) }
				onMouseLeave={ () => hide( CLOSE_DELAY ) }
				onFocus={ () => show( 0 ) }
				onBlur={ () => hide( CLOSE_DELAY ) }
				onClick={ () => {
					clear();
					setOpen( ( v ) => ! v );
				} }
			>
				<Eye size={ 15 } aria-hidden="true" />
			</button>
			{ open
				? createPortal(
						<div
							id={ popId }
							role="tooltip"
							data-theme={ theme }
							className="uxs-portal uxs-wt__preview"
							style={ { left: pos.left, top: pos.top, width: BOX_W + 2, height: boxH + 2 } }
							onMouseEnter={ clear }
							onMouseLeave={ () => hide( CLOSE_DELAY ) }
						>
							{ ! ready && ! failed ? (
								<span className="uxs-wt__preview-wait">
									<LoaderCircle size={ 22 } className="uxs-spin" aria-hidden="true" />
									<span className="screen-reader-text">{ __( 'Loading…', 'ux-studio' ) }</span>
								</span>
							) : null }
							{ failed ? (
								<span className="uxs-wt__preview-fail">
									<strong>{ __( 'Preview not available', 'ux-studio' ) }</strong>
									{ __( 'The widget did not answer in time.', 'ux-studio' ) }
								</span>
							) : (
								<iframe
									src={ src }
									title={ sprintf(
										/* translators: %s: widget name. */
										__( 'Preview of the widget %s', 'ux-studio' ),
										title
									) }
									ref={ frame }
									tabIndex={ -1 }
									loading="lazy"
									referrerPolicy="same-origin"
									style={ { width: FRAME_W, height: frameH, transform: `scale(${ SCALE })` } }
									onLoad={ () => {
										setReady( true );
										measure();
										window.setTimeout( measure, 600 );
									} }
								/>
							) }
						</div>,
						document.body
				  )
				: null }
		</>
	);
}
