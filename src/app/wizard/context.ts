/**
 * What the wizard shell hands to every step.
 */
import type { Check, RunState, ScanSource, SourcePlan } from './types';

export interface WizardCtx {
	/** Ids of the sources the admin ticked. */
	selected: string[];
	toggle: ( id: string ) => void;
	setSelected: ( ids: string[] ) => void;

	/** Step 2. */
	scan: ScanSource[] | undefined;
	scanError: string | null;
	/** Plans of every scanned source (Step 3 shows them, Step 4 uses them for warnings). */
	plans: Record< string, SourcePlan > | undefined;

	/** Step 4: the dry run. */
	dry: RunState | null;
	dryBusy: boolean;
	dryError: string | null;
	retryDry: () => void;

	/** Step 5: the real run. */
	run: RunState | null;
	runBusy: boolean;
	runError: string | null;
	startMigration: () => void;

	/** Step 6. */
	verify: Record< string, Check[] > | null;
	verifyBusy: boolean;
	verifyError: string | null;
	rolledBack: boolean;
	rollbackBusy: boolean;
	rollback: () => void;

	/** Navigation. */
	next: () => void;
	back: () => void;
	goTo: ( stepId: string ) => void;
	/** Start over (after a finished run). */
	restart: () => void;
}
