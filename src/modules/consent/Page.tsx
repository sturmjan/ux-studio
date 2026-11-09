import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { ArrowLeft } from 'lucide-react';
import { navigate } from '../../app/route';
import { TopicSettings, inSections } from '../../app/TopicSettings';
import { TabsNav } from '../../app/SubNav';

const MODULE = 'consent';

/** One menu item per settings section (the PHP schema groups the fields by the same labels). */
const TOPICS: { id: string; label: string }[] = [
	{ id: 'display', label: __( 'Display', 'ux-studio' ) },
	{ id: 'texts', label: __( 'Texts', 'ux-studio' ) },
	{ id: 'appearance', label: __( 'Appearance', 'ux-studio' ) },
	{ id: 'blocking', label: __( 'Blocking', 'ux-studio' ) },
	{ id: 'google', label: __( 'Google Consent Mode', 'ux-studio' ) },
];

function Note( { children }: { children: JSX.Element | string } ): JSX.Element {
	return <p className="uxs-form__help">{ children }</p>;
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< string >( 'display' );
	const topic = TOPICS.find( ( t ) => t.id === tab ) ?? ( TOPICS[ 0 ] as ( typeof TOPICS )[ number ] );

	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					<button
						type="button"
						onClick={ () => navigate( '' ) }
						aria-label={ __( 'Back to modules', 'ux-studio' ) }
						style={ { background: 'none', border: 'none', cursor: 'pointer', verticalAlign: 'middle' } }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ __( 'Cookie Consent', 'ux-studio' ) }
				</h1>
			</header>
			<TabsNav>
				{ TOPICS.map( ( t ) => (
					<button key={ t.id } className={ tab === t.id ? 'is-active' : '' } onClick={ () => setTab( t.id ) }>
						{ t.label }
					</button>
				) ) }
			</TabsNav>
			<TopicSettings key={ topic.id } moduleId={ MODULE } include={ inSections( topic.label ) }>
				{ topic.id === 'display' && (
					<Note>
						{ __(
							'A link that reopens the cookie settings anywhere (for example in the footer or on the privacy page): use the shortcode [uxstudio_cookie_settings], or give any link the address #uxc-open. Saving these settings empties the page cache of the common cache plugins. The texts on this banner are your responsibility; have them checked against your actual use of cookies.',
							'ux-studio'
						) }
					</Note>
				) }
				{ topic.id === 'blocking' && (
					<Note>
						{ __(
							'Scripts are held back by rewriting their tag, so a held-back script starts without a page reload once the visitor agrees. The list of known services (analytics, advertising, video, maps, chat) is built in; add your own with the rules below. A script that was already running cannot be stopped: if a visitor withdraws consent, the page reloads.',
							'ux-studio'
						) }
					</Note>
				) }
				{ topic.id === 'google' && (
					<Note>
						{ __(
							'Google Tag Manager Kit and the Pixel and Tag Manager module follow this setting automatically. Defaults are sent to Google before any tag, so every consent type starts as denied. Warning: a tag inside your Tag Manager container that is not from Google (Meta Pixel, Sklik, TikTok, Hotjar) does not read this signal. In the container, fire it with a custom event trigger named uxc_consent and a condition that uxc_marketing (or uxc_statistics, uxc_preferences) is true, or use Additional consent checks. The event is pushed to the dataLayer on every page, also for a choice remembered from an earlier visit.',
							'ux-studio'
						) }
					</Note>
				) }
			</TopicSettings>
		</>
	);
}
