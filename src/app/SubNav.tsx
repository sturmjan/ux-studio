/**
 * Sub-navigation of a module (its tabs / sections) shown as a tree in the app
 * sidebar instead of a horizontal tab bar inside the page. Pages keep their
 * tab buttons + state exactly as before and just wrap the buttons in
 * <TabsNav>; the buttons are portalled into the sidebar slot. Level-2 navs
 * (sections inside a tab) are inserted right under the active level-1 button.
 * Without a slot (e.g. rendered outside the shell) they render inline as before.
 */
import { Children, createContext, isValidElement, useContext, useSyncExternalStore, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

export const SubNavSlot = createContext< HTMLElement | null >( null );

/** Slot under the active level-1 button, where a level-2 nav is portalled. */
let childSlot: HTMLElement | null = null;
const listeners = new Set< () => void >();
function setChildSlot( el: HTMLElement | null ): void {
	if ( el !== childSlot ) {
		childSlot = el;
		listeners.forEach( ( l ) => l() );
	}
}
function useChildSlot(): HTMLElement | null {
	return useSyncExternalStore(
		( l ) => {
			listeners.add( l );
			return () => listeners.delete( l );
		},
		() => childSlot
	);
}

interface TabsNavProps {
	children: ReactNode;
	/** 1 = module tabs, 2 = sections inside a tab (nested under the active tab). */
	level?: 1 | 2;
	/** Class of the inline fallback container. */
	inlineClass?: string;
}

export function TabsNav( { children, level = 1, inlineClass = 'uxs-tabs' }: TabsNavProps ): JSX.Element {
	const slot = useContext( SubNavSlot );
	const nested = useChildSlot();
	if ( ! slot ) {
		return <div className={ inlineClass }>{ children }</div>;
	}
	if ( 2 === level ) {
		return createPortal(
			<div className="uxs-subnav__list is-level-2" role="tablist">
				{ children }
			</div>,
			nested ?? slot
		);
	}
	// Level 1: leave a hook point right after the active button for level-2 entries.
	const items: ReactNode[] = [];
	Children.toArray( children ).forEach( ( child ) => {
		items.push( child );
		if ( isValidElement< { className?: string } >( child ) && ( child.props.className ?? '' ).includes( 'is-active' ) ) {
			items.push( <div key="uxs-subnav-child" ref={ setChildSlot } className="uxs-subnav__child" /> );
		}
	} );
	return createPortal(
		<div className="uxs-subnav__list is-level-1" role="tablist">
			{ items }
		</div>,
		slot
	);
}
