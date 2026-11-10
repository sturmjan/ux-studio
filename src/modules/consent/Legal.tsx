import { useEffect, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ExternalLink, Eye, History, LoaderCircle, Plus, ScanSearch, Trash2 } from 'lucide-react';
import { ApiError, api, queryClient } from '../../app/api';
import { formatDate } from '../../app/date';

type DocId = 'cookies' | 'privacy' | 'terms';

interface Recipient {
	kind: string;
	name: string;
	third_country: boolean;
}

interface Profile {
	name: string;
	ico: string;
	dic: string;
	address: string;
	register_note: string;
	email: string;
	phone: string;
	dpo: boolean;
	dpo_contact: string;
	authority_name: string;
	authority_url: string;
	purposes: string[];
	retention: Record< string, string >;
	recipients: Recipient[];
	has_eshop: boolean;
	return_costs: 'customer' | 'seller';
	complaints_address: string;
	complaints_email: string;
	withdrawal_address: string;
	delivery_note: string;
	payment_note: string;
	language: 'auto' | 'cs' | 'en';
}

interface DocState {
	doc: DocId;
	title: string;
	available: boolean;
	page: { id: number; status: string; url: string; edit_url: string; title: string } | null;
	version: number;
	effective: number;
	changed: boolean;
	missing: Record< string, string >;
	versions: number;
}

interface LegalState {
	stored: boolean;
	/** Fields that hold only a guess of the server before the first save (the wizard may replace them). */
	guessed: string[];
	profile: Profile;
	language: 'cs' | 'en';
	purposes: { key: string; label: string; retention: string; suggested: boolean }[];
	recipient_kinds: Record< string, string >;
	detected: string[];
	shop: boolean;
	unclassified: string[];
	docs: DocState[];
}

interface VersionRow {
	id: number;
	ver: number;
	hash: string;
	lang: string;
	effective: number;
}

interface VersionFull extends VersionRow {
	doc: DocId;
	html: string;
}

const KEY = [ 'consent', 'legal' ];

/** One suggestion of GET consent/legal/detect (nothing is saved on the server). */
interface Suggestion {
	value: string | boolean | string[];
	source: string;
	confidence: 'high' | 'medium' | 'low';
	label: string;
}

interface DetectResult {
	suggestions: Record< string, Suggestion >;
	pages: string[];
	ares: 'skipped' | 'ok' | 'not_found' | 'error' | 'no_ico';
}

const DETECT_FIELDS = [ 'name', 'ico', 'dic', 'address', 'register_note', 'email', 'phone' ] as const;
type DetectField = ( typeof DETECT_FIELDS )[ number ];

function omit< T >( map: Record< string, T >, key: string ): Record< string, T > {
	const rest = { ...map };
	delete rest[ key ];
	return rest;
}

function same( a: string, b: string ): boolean {
	return a.trim().replace( /\s+/g, ' ' ).toLowerCase() === b.trim().replace( /\s+/g, ' ' ).toLowerCase();
}

function errorsOf( e: unknown ): Record< string, string > {
	if ( e instanceof ApiError ) {
		const errors = e.data.errors;
		if ( errors && typeof errors === 'object' ) {
			const flat: Record< string, string > = {};
			for ( const [ key, value ] of Object.entries( errors as Record< string, unknown > ) ) {
				if ( typeof value === 'string' ) {
					flat[ key ] = value;
				} else if ( value && typeof value === 'object' ) {
					// Missing details of a document: field => label.
					for ( const [ field, label ] of Object.entries( value as Record< string, string > ) ) {
						flat[ field ] = String( label );
					}
				}
			}
			return flat;
		}
	}
	return {};
}

function messageOf( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

function Loading(): JSX.Element {
	return (
		<div className="uxs-loading">
			<LoaderCircle size={ 18 } /> { __( 'Loading…', 'ux-studio' ) }
		</div>
	);
}

interface FieldProps {
	label: string;
	error?: string;
	help?: string;
	/** Where the value that is in the field now came from (found on the site, not typed or saved). */
	origin?: Suggestion;
	/** A different value found for a field that already has one. */
	conflict?: Suggestion;
	onReplace?: () => void;
	children: JSX.Element;
}

function SourceBadge( { origin }: { origin: Suggestion } ): JSX.Element {
	const check = origin.confidence === 'low' || origin.source === 'admin_email';
	return <span className={ 'uxs-badge' + ( check ? ' is-warning' : '' ) }>{ origin.label }</span>;
}

function Field( { label, error, help, origin, conflict, onReplace, children }: FieldProps ): JSX.Element {
	return (
		<div className="uxs-form__row">
			<label>
				<span>{ label }</span>
				{ children }
			</label>
			{ origin && (
				<p className="uxs-form__help">
					<SourceBadge origin={ origin } />
				</p>
			) }
			{ conflict && onReplace && (
				<p className="uxs-form__help">
					{ sprintf(
						/* translators: 1: where the suggestion comes from, for example "from ARES"; 2: the suggested value. */
						__( 'Suggestion (%1$s): %2$s', 'ux-studio' ),
						conflict.label,
						String( conflict.value )
					) }{ ' ' }
					<button type="button" className="button button-small" onClick={ onReplace }>
						{ __( 'Use it', 'ux-studio' ) }
					</button>
				</p>
			) }
			{ help && <p className="uxs-form__help">{ help }</p> }
			{ error && <p className="uxs-badge is-danger">{ error }</p> }
		</div>
	);
}

/** Tab of the Cookie Consent page: details of the controller, the generated pages and their versions. */
export default function Legal(): JSX.Element {
	const legal = useQuery( { queryKey: KEY, queryFn: () => api< LegalState >( '/consent/legal' ) } );
	const [ profile, setProfile ] = useState< Profile | null >( null );
	const [ dirty, setDirty ] = useState< boolean >( false );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ selected, setSelected ] = useState< Record< string, boolean > >( {} );
	const [ live, setLive ] = useState< boolean >( false );
	const [ preview, setPreview ] = useState< { doc: DocId; html: string } | null >( null );
	const [ history, setHistory ] = useState< DocId | null >( null );
	const [ shown, setShown ] = useState< number | null >( null );
	const [ notice, setNotice ] = useState< string >( '' );
	// Details found on the site: where each value in the form came from, differing values for filled fields, and what the person typed.
	const [ origin, setOrigin ] = useState< Record< string, Suggestion > >( {} );
	const [ conflicts, setConflicts ] = useState< Record< string, Suggestion > >( {} );
	const [ detectNote, setDetectNote ] = useState< string >( '' );
	const touched = useRef< Record< string, boolean > >( {} );
	const profileRef = useRef< Profile | null >( null );
	const originRef = useRef< Record< string, Suggestion > >( {} );
	const autoRan = useRef< boolean >( false );
	profileRef.current = profile;
	originRef.current = origin;

	useEffect( () => {
		if ( legal.data && ( profile === null || ! dirty ) ) {
			setProfile( legal.data.profile );
		}
		// The form is filled from the server only until the person starts typing.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ legal.data ] );

	const save = useMutation( {
		mutationFn: ( p: Profile ) => api< LegalState >( '/consent/legal/profile', { method: 'POST', body: JSON.stringify( { profile: p } ) } ),
		onSuccess: ( data ) => {
			queryClient.setQueryData( KEY, data );
			setProfile( data.profile );
			setDirty( false );
			setOrigin( {} );
			setConflicts( {} );
			touched.current = {};
			setErrors( {} );
			setNotice( __( 'Details saved. Now make or update the pages below.', 'ux-studio' ) );
		},
		onError: ( e ) => {
			setErrors( errorsOf( e ) );
			setNotice( '' );
		},
	} );

	const publish = useMutation( {
		mutationFn: ( v: { docs: DocId[]; live: boolean } ) =>
			api< { results: Record< string, { created: boolean; version: number } >; state: LegalState } >( '/consent/legal/publish', { method: 'POST', body: JSON.stringify( v ) } ),
		onSuccess: ( data ) => {
			queryClient.setQueryData( KEY, data.state );
			setErrors( {} );
			const made = Object.values( data.results ).filter( ( r ) => r.created ).length;
			setNotice(
				made > 0
					? sprintf(
							/* translators: %d: number of documents that got a new version. */
							__( 'Done. %d document(s) got a new version.', 'ux-studio' ),
							made
					  )
					: __( 'Done. Nothing changed in the text, so no new version was made.', 'ux-studio' )
			);
			void queryClient.invalidateQueries( { queryKey: [ 'consent', 'legal', 'versions' ] } );
		},
		onError: ( e ) => {
			setErrors( errorsOf( e ) );
			setNotice( '' );
		},
	} );

	function applyDetected( res: DetectResult, withAres: boolean ): void {
		const current = profileRef.current;
		if ( ! current ) {
			return;
		}
		const guessed = legal.data?.guessed ?? [];
		const next: Profile = { ...current };
		const found: Record< string, Suggestion > = {};
		const other: Record< string, Suggestion > = {};
		let filled = 0;
		for ( const field of DETECT_FIELDS ) {
			const s = res.suggestions[ field ];
			if ( ! s || typeof s.value !== 'string' ) {
				continue;
			}
			const now = current[ field ];
			if ( same( now, s.value ) ) {
				if ( guessed.includes( field ) || originRef.current[ field ] ) {
					found[ field ] = s;
				}
				continue;
			}
			// Empty, a guess of the server, or a value this very button filled in earlier and the person has not touched.
			const fillable = ! touched.current[ field ] && ( now.trim() === '' || guessed.includes( field ) || !! originRef.current[ field ] );
			if ( fillable ) {
				next[ field ] = s.value;
				found[ field ] = s;
				filled++;
			} else {
				other[ field ] = s;
			}
		}
		const shop = res.suggestions.has_eshop;
		if ( shop && shop.value === true && ! current.has_eshop && ! touched.current.has_eshop ) {
			next.has_eshop = true;
			found.has_eshop = shop;
			filled++;
		}
		const wanted = res.suggestions.purposes;
		if ( wanted && Array.isArray( wanted.value ) && ! touched.current.purposes ) {
			const add = wanted.value.filter( ( k ) => ! current.purposes.includes( k ) );
			if ( add.length > 0 ) {
				next.purposes = [ ...current.purposes, ...add ];
				found.purposes = wanted;
				filled += add.length;
			}
		}
		if ( filled > 0 ) {
			setProfile( next );
			setDirty( true );
		}
		setOrigin( ( o ) => ( { ...o, ...found } ) );
		setConflicts( ( c ) => {
			const rest = { ...c };
			for ( const key of Object.keys( found ) ) {
				delete rest[ key ];
			}
			return { ...rest, ...other };
		} );
		const aresText: Record< string, string > = {
			ok: __( 'Checked in ARES.', 'ux-studio' ),
			not_found: __( 'ARES does not know this company number.', 'ux-studio' ),
			error: __( 'ARES did not answer. Nothing was changed.', 'ux-studio' ),
			no_ico: __( 'No valid company number (IČO) was found. Type it in and try again.', 'ux-studio' ),
		};
		const parts: string[] = [
			filled > 0
				? sprintf(
						/* translators: %d: number of items filled in from the site. */
						__( 'Filled in %d item(s) from the site. Check them, then press Save details.', 'ux-studio' ),
						filled
				  )
				: __( 'Nothing new to fill in: the empty fields were not found on the site.', 'ux-studio' ),
		];
		const aresLine = aresText[ res.ares ];
		if ( withAres && aresLine ) {
			parts.push( aresLine );
		}
		setDetectNote( parts.join( ' ' ) );
	}

	const detect = useMutation( {
		mutationFn: ( v: { ares: boolean; ico: string } ) => api< DetectResult >( `/consent/legal/detect?ares=${ v.ares ? 1 : 0 }&ico=${ encodeURIComponent( v.ico ) }` ),
		onSuccess: ( res, v ) => applyDetected( res, v.ares ),
		onError: ( e ) => setDetectNote( messageOf( e ) ),
	} );

	// The first time the wizard is opened with nothing saved, look at the site (without ARES: that call leaves the site).
	useEffect( () => {
		if ( legal.data && ! legal.data.stored && ! autoRan.current ) {
			autoRan.current = true;
			detect.mutate( { ares: false, ico: '' } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ legal.data ] );

	const openPreview = useMutation( {
		mutationFn: ( doc: DocId ) => api< { doc: DocId; html: string } >( `/consent/legal/preview?doc=${ doc }` ),
		onSuccess: ( data ) => setPreview( { doc: data.doc, html: data.html } ),
	} );

	const versions = useQuery( {
		queryKey: [ 'consent', 'legal', 'versions', history ],
		queryFn: () => api< VersionRow[] >( `/consent/legal/versions?doc=${ history }` ),
		enabled: history !== null,
	} );
	const full = useQuery( {
		queryKey: [ 'consent', 'legal', 'version', shown ],
		queryFn: () => api< VersionFull >( `/consent/legal/version?id=${ shown }` ),
		enabled: shown !== null,
	} );

	if ( legal.isLoading || ! profile ) {
		return <Loading />;
	}
	if ( legal.isError || ! legal.data ) {
		return <p className="uxs-badge is-danger">{ messageOf( legal.error ) }</p>;
	}
	const data = legal.data;

	function change< K extends keyof Profile >( key: K, value: Profile[ K ] ): void {
		setProfile( ( p ) => ( p ? { ...p, [ key ]: value } : p ) );
		setDirty( true );
		// What the person typed is no longer "found on the site".
		touched.current[ key ] = true;
		setOrigin( ( o ) => omit( o, key ) );
		setConflicts( ( c ) => omit( c, key ) );
	}
	function takeSuggestion( key: DetectField ): void {
		const s = conflicts[ key ];
		if ( ! s || typeof s.value !== 'string' ) {
			return;
		}
		const value = s.value;
		setProfile( ( p ) => ( p ? { ...p, [ key ]: value } : p ) );
		setDirty( true );
		setOrigin( ( o ) => ( { ...o, [ key ]: s } ) );
		setConflicts( ( c ) => omit( c, key ) );
	}
	const meta = ( key: DetectField ): Pick< FieldProps, 'origin' | 'conflict' | 'onReplace' > => ( {
		origin: origin[ key ],
		conflict: conflicts[ key ],
		onReplace: () => takeSuggestion( key ),
	} );
	function togglePurpose( key: string ): void {
		const has = profile!.purposes.includes( key );
		change( 'purposes', has ? profile!.purposes.filter( ( k ) => k !== key ) : [ ...profile!.purposes, key ] );
	}
	function setRecipient( index: number, patch: Partial< Recipient > ): void {
		change( 'recipients', profile!.recipients.map( ( r, i ) => ( i === index ? { ...r, ...patch } : r ) ) );
	}

	const err = ( key: string ): string | undefined => errors[ key ];
	const text = ( key: 'name' | 'ico' | 'dic' | 'register_note' | 'email' | 'phone' | 'dpo_contact' | 'authority_name' | 'authority_url' | 'complaints_email' | 'delivery_note' | 'payment_note' ): JSX.Element => (
		<input type="text" className="uxs-input" value={ profile[ key ] } onChange={ ( e ) => change( key, e.target.value ) } />
	);
	const area = ( key: 'address' | 'complaints_address' | 'withdrawal_address' ): JSX.Element => (
		<textarea className="uxs-input" rows={ 2 } value={ profile[ key ] } onChange={ ( e ) => change( key, e.target.value ) } />
	);

	const chosen = data.docs.filter( ( d ) => d.available && selected[ d.doc ] ).map( ( d ) => d.doc );

	return (
		<div className="uxs-form" style={ { maxWidth: 'none' } }>
			<p className="uxs-badge is-warning" style={ { display: 'block', whiteSpace: 'normal' } }>
				{ __(
					'These texts are generated from templates. They are not legal advice. Before you publish them, have a lawyer check them against how your business really works. You, as the site operator, are responsible for what the pages say.',
					'ux-studio'
				) }
			</p>
			<p className="uxs-form__help">
				{ __(
					'Fill in the details once; the cookie policy, the privacy policy and (for a shop) the terms and conditions all use them. The list of cookies and services is not typed here: it is read live from the Services and cookies tab, so the policy always matches the banner.',
					'ux-studio'
				) }
			</p>

			<h3>{ __( 'Who is the controller', 'ux-studio' ) }</h3>
			<div className="uxs-actions">
				<button type="button" className="button" disabled={ detect.isPending } onClick={ () => detect.mutate( { ares: false, ico: '' } ) }>
					{ detect.isPending ? <LoaderCircle size={ 14 } /> : <ScanSearch size={ 14 } /> } { __( 'Load details from the site', 'ux-studio' ) }
				</button>
				<button type="button" className="button" disabled={ detect.isPending } onClick={ () => detect.mutate( { ares: true, ico: profile.ico.replace( /\D/g, '' ) } ) }>
					{ __( 'Verify in ARES', 'ux-studio' ) }
				</button>
			</div>
			<p className="uxs-form__help">
				{ __(
					'Looks at the shop settings, the SEO plugin, the Contact page and the footer of this site and fills in the empty fields; a filled field is never overwritten (a different value is offered next to it). Nothing is saved until you press Save details. ARES is the public register of Czech companies: your company number is sent there only when you press "Verify in ARES".',
					'ux-studio'
				) }
			</p>
			{ detectNote && (
				<p className="uxs-form__help" role="status">
					{ detectNote }
				</p>
			) }
			<Field label={ __( 'Name of the company or person', 'ux-studio' ) } { ...meta( 'name' ) } error={ err( 'name' ) }>
				{ text( 'name' ) }
			</Field>
			<Field label={ __( 'Company number (IČO)', 'ux-studio' ) } { ...meta( 'ico' ) } error={ err( 'ico' ) }>
				{ text( 'ico' ) }
			</Field>
			<Field label={ __( 'VAT number (DIČ), if you have one', 'ux-studio' ) } { ...meta( 'dic' ) } error={ err( 'dic' ) }>
				{ text( 'dic' ) }
			</Field>
			<Field label={ __( 'Address of the seat', 'ux-studio' ) } { ...meta( 'address' ) } error={ err( 'address' ) }>
				{ area( 'address' ) }
			</Field>
			<Field label={ __( 'Entry in a register (optional)', 'ux-studio' ) } { ...meta( 'register_note' ) } help={ __( 'For example: registered in the commercial register kept by the City Court in Prague, file C 12345.', 'ux-studio' ) } error={ err( 'register_note' ) }>
				{ text( 'register_note' ) }
			</Field>
			<Field label={ __( 'E-mail for questions about personal data', 'ux-studio' ) } { ...meta( 'email' ) } error={ err( 'email' ) }>
				{ text( 'email' ) }
			</Field>
			<Field label={ __( 'Telephone (optional)', 'ux-studio' ) } { ...meta( 'phone' ) } error={ err( 'phone' ) }>
				{ text( 'phone' ) }
			</Field>
			<div className="uxs-form__row">
				<label>
					<input type="checkbox" checked={ profile.dpo } onChange={ ( e ) => change( 'dpo', e.target.checked ) } /> { __( 'We have a data protection officer', 'ux-studio' ) }
				</label>
			</div>
			{ profile.dpo && (
				<Field label={ __( 'How to reach the officer', 'ux-studio' ) } error={ err( 'dpo_contact' ) }>
					{ text( 'dpo_contact' ) }
				</Field>
			) }
			<Field label={ __( 'Supervisory authority', 'ux-studio' ) } error={ err( 'authority_name' ) }>
				{ text( 'authority_name' ) }
			</Field>
			<Field label={ __( 'Address of its website', 'ux-studio' ) } error={ err( 'authority_url' ) }>
				{ text( 'authority_url' ) }
			</Field>
			<Field label={ __( 'Language of the pages', 'ux-studio' ) } help={ __( 'Automatic means the language of the site.', 'ux-studio' ) }>
				<select value={ profile.language } onChange={ ( e ) => change( 'language', e.target.value as Profile[ 'language' ] ) }>
					<option value="auto">{ __( 'Automatic', 'ux-studio' ) }</option>
					<option value="cs">{ __( 'Czech', 'ux-studio' ) }</option>
					<option value="en">{ __( 'English', 'ux-studio' ) }</option>
				</select>
			</Field>

			<h3>{ __( 'What you do with personal data', 'ux-studio' ) }</h3>
			<p className="uxs-form__help">{ __( 'Tick what applies. Each purpose comes with the data, the legal basis and a retention period; you can change the period.', 'ux-studio' ) }</p>
			{ origin.purposes && (
				<p className="uxs-form__help">
					<SourceBadge origin={ origin.purposes } />
				</p>
			) }
			{ err( 'purposes' ) && <p className="uxs-badge is-danger">{ err( 'purposes' ) }</p> }
			{ data.purposes.map( ( p ) => {
				const on = profile.purposes.includes( p.key );
				return (
					<div className="uxs-form__row" key={ p.key }>
						<label>
							<input type="checkbox" checked={ on } onChange={ () => togglePurpose( p.key ) } /> { p.label }
							{ p.suggested && ! on && <span className="uxs-badge" style={ { marginLeft: 6 } }>{ __( 'your site seems to need this', 'ux-studio' ) }</span> }
						</label>
						{ on && (
							<input
								type="text"
								className="uxs-input"
								aria-label={ sprintf( /* translators: %s: purpose. */ __( 'Retention: %s', 'ux-studio' ), p.label ) }
								placeholder={ p.retention }
								value={ profile.retention[ p.key ] ?? '' }
								onChange={ ( e ) => change( 'retention', { ...profile.retention, [ p.key ]: e.target.value } ) }
							/>
						) }
						{ err( 'retention.' + p.key ) && <p className="uxs-badge is-danger">{ err( 'retention.' + p.key ) }</p> }
					</div>
				);
			} ) }

			<h3>{ __( 'Who else receives the data', 'ux-studio' ) }</h3>
			{ data.detected.length > 0 && (
				<>
					<p className="uxs-form__help">{ __( 'Found automatically from the services on your site (you cannot edit these here):', 'ux-studio' ) }</p>
					<ul>
						{ data.detected.map( ( d ) => (
							<li key={ d }>{ d }</li>
						) ) }
					</ul>
				</>
			) }
			<p className="uxs-form__help">{ __( 'Add the others by hand: carriers, payment services, the accountant, hosting, e-mail service. Tick "outside the EEA" where the company may process data outside the European Economic Area.', 'ux-studio' ) }</p>
			{ err( 'recipients' ) && <p className="uxs-badge is-danger">{ err( 'recipients' ) }</p> }
			{ profile.recipients.map( ( r, i ) => (
				<div key={ i } style={ { display: 'flex', gap: 'var(--uxs-sp-2)', flexWrap: 'wrap', alignItems: 'center', marginBottom: 'var(--uxs-sp-2)' } }>
					<select value={ r.kind } aria-label={ __( 'Kind', 'ux-studio' ) } onChange={ ( e ) => setRecipient( i, { kind: e.target.value } ) }>
						{ Object.entries( data.recipient_kinds ).map( ( [ k, label ] ) => (
							<option key={ k } value={ k }>
								{ label }
							</option>
						) ) }
					</select>
					<input type="text" className="uxs-input" style={ { minWidth: 260 } } aria-label={ __( 'Name of the company', 'ux-studio' ) } value={ r.name } onChange={ ( e ) => setRecipient( i, { name: e.target.value } ) } />
					<label>
						<input type="checkbox" checked={ r.third_country } onChange={ ( e ) => setRecipient( i, { third_country: e.target.checked } ) } /> { __( 'outside the EEA', 'ux-studio' ) }
					</label>
					<button type="button" className="button button-small" aria-label={ __( 'Remove', 'ux-studio' ) } onClick={ () => change( 'recipients', profile.recipients.filter( ( _, j ) => j !== i ) ) }>
						<Trash2 size={ 14 } />
					</button>
					{ err( 'recipients.' + i ) && <span className="uxs-badge is-danger">{ err( 'recipients.' + i ) }</span> }
				</div>
			) ) }
			<button type="button" className="button button-small" onClick={ () => change( 'recipients', [ ...profile.recipients, { kind: 'carrier', name: '', third_country: false } ] ) }>
				<Plus size={ 14 } /> { __( 'Add a recipient', 'ux-studio' ) }
			</button>

			<h3>{ __( 'Online shop', 'ux-studio' ) }</h3>
			<div className="uxs-form__row">
				<label>
					<input type="checkbox" checked={ profile.has_eshop } onChange={ ( e ) => change( 'has_eshop', e.target.checked ) } /> { __( 'We run an online shop (needed for the terms and conditions)', 'ux-studio' ) }
				</label>
				{ origin.has_eshop && (
					<p className="uxs-form__help">
						<SourceBadge origin={ origin.has_eshop } />
					</p>
				) }
				{ ! data.shop && profile.has_eshop && <p className="uxs-form__help">{ __( 'WooCommerce is not active, so delivery and payment methods cannot be read. The terms will tell customers to look at the basket.', 'ux-studio' ) }</p> }
			</div>
			{ profile.has_eshop && (
				<>
					<Field label={ __( 'Who pays for returning goods after a withdrawal', 'ux-studio' ) }>
						<select value={ profile.return_costs } onChange={ ( e ) => change( 'return_costs', e.target.value as Profile[ 'return_costs' ] ) }>
							<option value="customer">{ __( 'The customer', 'ux-studio' ) }</option>
							<option value="seller">{ __( 'We do', 'ux-studio' ) }</option>
						</select>
					</Field>
					<Field label={ __( 'Address for returned goods (empty = the seat)', 'ux-studio' ) } error={ err( 'withdrawal_address' ) }>
						{ area( 'withdrawal_address' ) }
					</Field>
					<Field label={ __( 'Address for complaints (empty = the seat)', 'ux-studio' ) } error={ err( 'complaints_address' ) }>
						{ area( 'complaints_address' ) }
					</Field>
					<Field label={ __( 'E-mail for complaints (empty = the e-mail above)', 'ux-studio' ) } error={ err( 'complaints_email' ) }>
						{ text( 'complaints_email' ) }
					</Field>
					<Field label={ __( 'Note on delivery (optional)', 'ux-studio' ) } help={ __( 'For example when you dispatch an order.', 'ux-studio' ) } error={ err( 'delivery_note' ) }>
						{ text( 'delivery_note' ) }
					</Field>
					<Field label={ __( 'Note on payment (optional)', 'ux-studio' ) } error={ err( 'payment_note' ) }>
						{ text( 'payment_note' ) }
					</Field>
				</>
			) }

			<div className="uxs-actions">
				<button type="button" className="button button-primary" disabled={ save.isPending || ! dirty } onClick={ () => save.mutate( profile ) }>
					{ save.isPending && <LoaderCircle size={ 14 } /> } { __( 'Save details', 'ux-studio' ) }
				</button>
				{ dirty && <span className="uxs-form__help">{ __( 'Unsaved changes.', 'ux-studio' ) }</span> }
			</div>
			{ save.isError && Object.keys( errors ).length === 0 && <p className="uxs-badge is-danger">{ messageOf( save.error ) }</p> }
			{ Object.keys( errors ).length > 0 && <p className="uxs-badge is-danger">{ __( 'Some details are missing or not valid, see the marked fields.', 'ux-studio' ) }</p> }

			<h3>{ __( 'Pages', 'ux-studio' ) }</h3>
			<p className="uxs-form__help">
				{ __(
					'A page holds one shortcode and is filled live: the tables of cookies follow your site. Every change of the text makes a new numbered version with its date; older versions stay in the history and under the text on the page. A new page is a draft until you tick "publish". The privacy page is also set as the WordPress privacy policy page, the cookie page as the link of the banner, and the terms page as the WooCommerce terms page, but only where nothing is set yet.',
					'ux-studio'
				) }
			</p>
			<div className="uxs-table-wrap">
				<table className="uxs-table">
					<thead>
						<tr>
							<th />
							<th>{ __( 'Document', 'ux-studio' ) }</th>
							<th>{ __( 'Page', 'ux-studio' ) }</th>
							<th>{ __( 'Version', 'ux-studio' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ data.docs.map( ( d ) => (
							<tr key={ d.doc }>
								<td>
									<input
										type="checkbox"
										disabled={ ! d.available }
										aria-label={ sprintf( /* translators: %s: document title. */ __( 'Select %s', 'ux-studio' ), d.title ) }
										checked={ ! ! selected[ d.doc ] && d.available }
										onChange={ ( e ) => setSelected( { ...selected, [ d.doc ]: e.target.checked } ) }
									/>
								</td>
								<td>
									<strong>{ d.title }</strong>
									{ ! d.available && <div className="uxs-form__help">{ __( 'Switch on "We run an online shop" to make the terms.', 'ux-studio' ) }</div> }
									{ d.available && Object.keys( d.missing ).length > 0 && (
										<div className="uxs-form__help">{ __( 'Still missing: ', 'ux-studio' ) + Object.values( d.missing ).join( ', ' ) }</div>
									) }
								</td>
								<td>
									{ d.page ? (
										<>
											<span className={ 'uxs-badge ' + ( d.page.status === 'publish' ? 'is-success' : '' ) }>{ d.page.status === 'publish' ? __( 'published', 'ux-studio' ) : __( 'draft', 'ux-studio' ) }</span>{ ' ' }
											<a href={ d.page.url } target="_blank" rel="noopener noreferrer">
												{ __( 'View', 'ux-studio' ) } <ExternalLink size={ 12 } />
											</a>
											{ ' · ' }
											<a href={ d.page.edit_url }>{ __( 'Edit page', 'ux-studio' ) }</a>
										</>
									) : (
										<span className="uxs-form__help">{ __( 'not made yet', 'ux-studio' ) }</span>
									) }
								</td>
								<td>
									{ d.version > 0 ? (
										<>
											{ sprintf( /* translators: 1: version number, 2: date. */ __( 'Version %1$d, from %2$s', 'ux-studio' ), d.version, formatDate( d.effective ) ) }
											{ d.changed && (
												<div>
													<span className="uxs-badge is-warning">{ __( 'text changed since, update the page for a new version', 'ux-studio' ) }</span>
												</div>
											) }
										</>
									) : (
										<span className="uxs-form__help">-</span>
									) }
								</td>
								<td>
									<button type="button" className="button button-small" disabled={ ! d.available || openPreview.isPending } onClick={ () => openPreview.mutate( d.doc ) }>
										<Eye size={ 14 } /> { __( 'Preview', 'ux-studio' ) }
									</button>{ ' ' }
									<button type="button" className="button button-small" disabled={ d.version === 0 } onClick={ () => { setHistory( d.doc ); setShown( null ); } }>
										<History size={ 14 } /> { __( 'History', 'ux-studio' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<div className="uxs-actions">
				<label>
					<input type="checkbox" checked={ live } onChange={ ( e ) => setLive( e.target.checked ) } /> { __( 'Publish the pages (otherwise new pages stay drafts)', 'ux-studio' ) }
				</label>
				<button type="button" className="button button-primary" disabled={ publish.isPending || chosen.length === 0 || dirty } onClick={ () => publish.mutate( { docs: chosen, live } ) }>
					{ publish.isPending && <LoaderCircle size={ 14 } /> } { __( 'Make or update the selected pages', 'ux-studio' ) }
				</button>
				{ dirty && <span className="uxs-form__help">{ __( 'Save the details first.', 'ux-studio' ) }</span> }
			</div>
			{ notice && <p className="uxs-badge is-success" style={ { display: 'block', whiteSpace: 'normal' } }>{ notice }</p> }
			{ publish.isError && Object.keys( errors ).length === 0 && <p className="uxs-badge is-danger">{ messageOf( publish.error ) }</p> }
			{ data.unclassified.length > 0 && (
				<p className="uxs-form__help">
					{ sprintf(
						/* translators: %s: list of hosts. */
						__( 'These hosts found on your site are not classified yet and are missing from the cookie policy: %s. Decide them in the Services and cookies tab.', 'ux-studio' ),
						data.unclassified.join( ', ' )
					) }
				</p>
			) }

			{ preview && (
				<section>
					<h3>{ sprintf( /* translators: %s: document title. */ __( 'Preview: %s', 'ux-studio' ), data.docs.find( ( d ) => d.doc === preview.doc )?.title ?? '' ) }</h3>
					<p className="uxs-form__help">{ __( 'This is the text as it would be published now, with your latest saved details. The preview is read-only.', 'ux-studio' ) }</p>
					<div className="uxs-legal-preview" style={ { maxHeight: 520, overflow: 'auto', padding: 'var(--uxs-sp-4)', border: '1px solid var(--uxs-border, #dcdcde)' } } dangerouslySetInnerHTML={ { __html: preview.html } } />
					<button type="button" className="button button-small" onClick={ () => setPreview( null ) }>
						{ __( 'Close preview', 'ux-studio' ) }
					</button>
				</section>
			) }

			{ history && (
				<section>
					<h3>{ sprintf( /* translators: %s: document title. */ __( 'History: %s', 'ux-studio' ), data.docs.find( ( d ) => d.doc === history )?.title ?? '' ) }</h3>
					<p className="uxs-form__help">{ __( 'Every version that was in force, newest first. The text of a version is the snapshot taken when it was made.', 'ux-studio' ) }</p>
					{ versions.isLoading && <Loading /> }
					{ versions.data && (
						<div className="uxs-table-wrap">
							<table className="uxs-table">
								<thead>
									<tr>
										<th>{ __( 'Version', 'ux-studio' ) }</th>
										<th>{ __( 'In force from', 'ux-studio' ) }</th>
										<th>{ __( 'Fingerprint', 'ux-studio' ) }</th>
										<th />
									</tr>
								</thead>
								<tbody>
									{ versions.data.map( ( v ) => (
										<tr key={ v.id }>
											<td>{ v.ver }</td>
											<td>{ formatDate( v.effective ) }</td>
											<td>
												<code>{ v.hash.slice( 0, 12 ) }</code>
											</td>
											<td>
												<button type="button" className="button button-small" onClick={ () => setShown( v.id ) }>
													{ __( 'Show text', 'ux-studio' ) }
												</button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
					{ shown !== null && full.isLoading && <Loading /> }
					{ shown !== null && full.data && (
						<div className="uxs-legal-preview" style={ { maxHeight: 520, overflow: 'auto', padding: 'var(--uxs-sp-4)', border: '1px solid var(--uxs-border, #dcdcde)' } } dangerouslySetInnerHTML={ { __html: full.data.html } } />
					) }
					<button type="button" className="button button-small" onClick={ () => { setHistory( null ); setShown( null ); } }>
						{ __( 'Close history', 'ux-studio' ) }
					</button>
				</section>
			) }
		</div>
	);
}
