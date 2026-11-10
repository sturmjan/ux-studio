/**
 * Pure logic of the help popover (HelpTip.tsx): the open / pinned state machine and the placement of the floating
 * window. Kept free of React and of the DOM so `node tests/help-tip.mjs` can run it.
 */

export interface HelpTipState {
	open: boolean;
	/** Opened by a click / tap / Enter: stays until Escape, a click outside or another click on the button. */
	pinned: boolean;
}

export type HelpTipAction =
	/** Pointer over the button or the window, or keyboard focus on the button: show it (a peek, not pinned). */
	| { type: 'peek' }
	/** The grace period after the pointer left (or the focus moved away) ran out. */
	| { type: 'release' }
	/** A click or tap on the button, or a click inside the window: pin it; a second click on the button closes it. */
	| { type: 'toggle' }
	/** A click inside the open window (a link, a glossary term): keep it open. */
	| { type: 'pin' }
	/** Escape, a click outside, another help window opened. */
	| { type: 'close' };

export const CLOSED: HelpTipState = { open: false, pinned: false };

export function helpTipReducer( state: HelpTipState, action: HelpTipAction ): HelpTipState {
	switch ( action.type ) {
		case 'peek':
			return state.open ? state : { open: true, pinned: false };
		case 'release':
			return state.pinned ? state : CLOSED;
		case 'toggle':
			return state.pinned ? CLOSED : { open: true, pinned: true };
		case 'pin':
			return state.open ? { open: true, pinned: true } : state;
		case 'close':
			return CLOSED;
	}
}

export interface Box {
	left: number;
	top: number;
	width: number;
	height: number;
}

export interface Placement {
	left: number;
	top: number;
	width: number;
	/** Set when neither side has room for the whole window: the window scrolls inside. */
	maxHeight: number | null;
	side: 'below' | 'above';
}

/**
 * Where to draw the window. `anchor` is the button's rect, `content` the natural size of the window at `width`,
 * `viewport` the visible size. The window opens below the button when it fits (or when there is more room below
 * than above), otherwise above; its right edge lines up with the button when it fits that way (the button sits at
 * the right end of a row), otherwise its left edge does; it never leaves the viewport, and on a narrow screen it
 * spans the width minus the margins.
 */
export function placeHelpTip(
	anchor: Box,
	content: { width: number; height: number },
	viewport: { width: number; height: number },
	options: { margin?: number; gap?: number; maxWidth?: number } = {}
): Placement {
	const margin = options.margin ?? 12;
	const gap = options.gap ?? 8;
	const maxWidth = options.maxWidth ?? 460;
	const width = Math.max( 0, Math.min( maxWidth, content.width, viewport.width - margin * 2 ) );

	const anchorRight = anchor.left + anchor.width;
	let left = anchorRight - width; // Right edges together.
	if ( left < margin ) {
		left = anchor.left; // Not enough room on the left: left edges together.
	}
	left = Math.max( margin, Math.min( left, viewport.width - margin - width ) );

	const below = viewport.height - ( anchor.top + anchor.height ) - gap - margin;
	const above = anchor.top - gap - margin;
	const fitsBelow = content.height <= below;
	const fitsAbove = content.height <= above;
	const side: 'below' | 'above' = fitsBelow || ( ! fitsAbove && below >= above ) ? 'below' : 'above';
	const room = side === 'below' ? below : above;
	const height = Math.min( content.height, Math.max( 0, room ) );
	const top = side === 'below' ? anchor.top + anchor.height + gap : anchor.top - gap - height;
	return { left, top, width, maxHeight: content.height > room ? Math.max( 0, room ) : null, side };
}
