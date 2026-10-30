/**
 * "Details" under an option: what it does, what changes, what can go wrong, when to switch it off (and, in the
 * onboarding wizard, when the starter pack leaves it out). The wording comes from PHP (Core\SettingHelp, filled from
 * each module's help.php), the same for every module's settings screen and for the wizard. Texts may carry
 * `[[term]]` glossary markers, see GlossaryTerm.tsx.
 */
import { __ } from '@wordpress/i18n';
import { HelpText } from './GlossaryTerm';

export interface Explain {
	does: string;
	/** What changes for the site and its visitors. Empty for older entries. */
	effect?: string;
	risk?: string;
	off?: string;
	skip?: string;
}

export default function SettingExplain( { explain }: { explain: Explain } ): JSX.Element {
	const parts: Array< [ string, string | undefined ] > = [
		[ __( 'What it does', 'ux-studio' ), explain.does ],
		[ __( 'What changes', 'ux-studio' ), explain.effect ],
		[ __( 'Risk', 'ux-studio' ), explain.risk ],
		[ __( 'When to switch it off', 'ux-studio' ), explain.off ],
		[ __( 'When the starter pack leaves it out', 'ux-studio' ), explain.skip ],
	];
	return (
		<details className="uxs-explain">
			<summary>{ __( 'Details', 'ux-studio' ) }</summary>
			<dl>
				{ parts.map( ( [ title, text ] ) =>
					text ? (
						<div key={ title } className="uxs-explain__part">
							<dt>{ title }</dt>
							<dd>
								<HelpText text={ text } />
							</dd>
						</div>
					) : null
				) }
			</dl>
		</details>
	);
}
