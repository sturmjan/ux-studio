/**
 * What the new-site wizard shell hands to every step (PLAN.md 29.4 F3).
 */
import type { ApplyResult, PhpVersionInfo, StarterData } from './starter-types';

export interface ApplyState {
	status: 'idle' | 'running' | 'done';
	/** Item being applied right now. */
	current: string | null;
	/** Item id => result of the server. */
	results: Record< string, ApplyResult >;
	/** Item id => message when the request itself failed. */
	errors: Record< string, string >;
	/** Item ids in the order they are applied. */
	order: string[];
}

export interface StarterCtx {
	data: StarterData | undefined;
	loadError: string | null;
	/** Reload GET onboarding/starter (after apply, confirm, revert). */
	reload: () => Promise< void >;
	php: PhpVersionInfo | undefined;

	selected: string[];
	toggle: ( id: string ) => void;
	setSelected: ( ids: string[] ) => void;
	/** Items for which the administrator confirmed a partial overlap. */
	confirmed: string[];
	toggleConfirmed: ( id: string ) => void;

	apply: ApplyState;
	startApply: () => void;

	/** The login address was confirmed in this session. */
	loginConfirmed: boolean;
	setLoginConfirmed: ( done: boolean ) => void;

	/** Everything was undone in this session. */
	reverted: boolean;
	revertBusy: boolean;
	revertError: string | null;
	revert: () => void;

	next: () => void;
	back: () => void;
	goTo: ( stepId: string ) => void;
}
