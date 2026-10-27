/**
 * "Details" under an option: what it does, what can go wrong, when to switch it off (and, in the onboarding wizard,
 * when the starter pack leaves it out). The wording comes from PHP (Security SettingHelp), the same for the settings
 * screen and the wizard.
 */
import { __ } from '@wordpress/i18n';

export interface Explain {
	does: string;
	risk: string;
	off: string;
	skip?: string;
}

export default function SettingExplain( { explain }: { explain: Explain } ): JSX.Element {
	return (
		<details className="uxs-explain">
			<summary>{ __( 'Details', 'ux-studio' ) }</summary>
			<dl>
				<dt>{ __( 'What it does', 'ux-studio' ) }</dt>
				<dd>{ explain.does }</dd>
				<dt>{ __( 'Risk', 'ux-studio' ) }</dt>
				<dd>{ explain.risk }</dd>
				<dt>{ __( 'When to switch it off', 'ux-studio' ) }</dt>
				<dd>{ explain.off }</dd>
				{ explain.skip ? (
					<>
						<dt>{ __( 'When the starter pack leaves it out', 'ux-studio' ) }</dt>
						<dd>{ explain.skip }</dd>
					</>
				) : null }
			</dl>
		</details>
	);
}
