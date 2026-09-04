import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Download, LoaderCircle, Upload } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';

type Tab = 'import' | 'export';
type Source = 'file' | 'url' | 'html' | 'json';
type Mode = 'new_page' | 'replace' | 'append';

interface ImportResult {
	post_id: number;
	title: string;
	widgets_created: number;
	mode: string;
	edit_url: string;
	url: string;
}

interface ExportResult {
	version: string;
	type: string;
	title: string;
	post_type: string;
	elementor_version: string;
	export_date: string;
	content: unknown;
	page_settings: unknown;
	filename: string;
}

/** Upload a file to the multipart endpoint (api() forces JSON, so do a raw fetch here). */
async function uploadFile( file: File ): Promise< ImportResult > {
	const boot = window.uxStudioBoot;
	const body = new FormData();
	body.append( 'file', file );
	const res = await fetch( `${ boot.restUrl }/elementor-import`, {
		method: 'POST',
		headers: { 'X-WP-Nonce': boot.nonce },
		body,
	} );
	if ( ! res.ok ) {
		const err: { message?: string } = await res.json().catch( () => ( {} ) );
		throw new Error( err.message ?? `HTTP ${ res.status }` );
	}
	const json: { data: ImportResult } = await res.json();
	return json.data;
}

function triggerDownload( data: ExportResult ): void {
	const { filename, ...payload } = data;
	const blob = new Blob( [ JSON.stringify( payload, null, 2 ) ], { type: 'application/json' } );
	const href = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = href;
	a.download = filename || 'elementor-export.json';
	document.body.appendChild( a );
	a.click();
	a.remove();
	URL.revokeObjectURL( href );
}

function ResultNotice( { result }: { result: ImportResult } ): JSX.Element {
	return (
		<div style={ { marginTop: 'var(--uxs-sp-4)' } }>
			<p>
				<span className="uxs-badge is-success">{ __( 'Imported', 'ux-studio' ) }</span>{ ' ' }
				<strong>{ result.title }</strong> ({ result.widgets_created } { __( 'widgets', 'ux-studio' ) }, { result.mode }).
			</p>
			<p>
				<a className="button" href={ result.edit_url }>
					{ __( 'Edit in Elementor', 'ux-studio' ) }
				</a>{ ' ' }
				<a className="button" href={ result.url } target="_blank" rel="noreferrer">
					{ __( 'View page', 'ux-studio' ) }
				</a>
			</p>
		</div>
	);
}

function ModeFields( {
	source,
	mode,
	setMode,
	postId,
	setPostId,
}: {
	source: Source;
	mode: Mode;
	setMode: ( m: Mode ) => void;
	postId: string;
	setPostId: ( v: string ) => void;
} ): JSX.Element | null {
	// File upload always creates a new draft.
	if ( source === 'file' ) {
		return null;
	}
	return (
		<>
			<div className="uxs-form__row">
				<label htmlFor="uxs-ei-mode">{ __( 'Mode', 'ux-studio' ) }</label>
				<select
					id="uxs-ei-mode"
					value={ mode }
					onChange={ ( e ) => setMode( e.target.value as Mode ) }
				>
					<option value="new_page">{ __( 'New draft page', 'ux-studio' ) }</option>
					<option value="replace">{ __( 'Replace existing page content', 'ux-studio' ) }</option>
					<option value="append">{ __( 'Append to existing page', 'ux-studio' ) }</option>
				</select>
			</div>
			{ mode !== 'new_page' ? (
				<div className="uxs-form__row">
					<label htmlFor="uxs-ei-target">{ __( 'Target page ID', 'ux-studio' ) }</label>
					<input
						id="uxs-ei-target"
						type="number"
						value={ postId }
						onChange={ ( e ) => setPostId( e.target.value ) }
						placeholder="123"
					/>
					<p className="uxs-form__help">
						{ __( 'The numeric ID of the page whose Elementor content will be replaced or appended to.', 'ux-studio' ) }
					</p>
				</div>
			) : null }
		</>
	);
}

function ImportTab(): JSX.Element {
	const [ source, setSource ] = useState< Source >( 'file' );
	const [ mode, setMode ] = useState< Mode >( 'new_page' );
	const [ postId, setPostId ] = useState( '' );
	const [ file, setFile ] = useState< File | null >( null );
	const [ url, setUrl ] = useState( '' );
	const [ selector, setSelector ] = useState( '' );
	const [ html, setHtml ] = useState( '' );
	const [ json, setJson ] = useState( '' );
	const [ title, setTitle ] = useState( '' );

	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ result, setResult ] = useState< ImportResult | null >( null );

	const targetId = Number( postId ) || 0;

	async function submit(): Promise< void > {
		setBusy( true );
		setError( '' );
		setResult( null );
		try {
			let res: ImportResult;
			if ( source === 'file' ) {
				if ( ! file ) {
					throw new Error( __( 'Choose a .json or .zip file first.', 'ux-studio' ) );
				}
				res = await uploadFile( file );
			} else if ( source === 'url' ) {
				res = await api< ImportResult >( 'elementor-import/url', {
					method: 'POST',
					body: JSON.stringify( { url, selector, mode, post_id: targetId } ),
				} );
			} else if ( source === 'html' ) {
				res = await api< ImportResult >( 'elementor-import/html', {
					method: 'POST',
					body: JSON.stringify( { html, title, mode, post_id: targetId } ),
				} );
			} else {
				let parsed: unknown;
				try {
					parsed = JSON.parse( json );
				} catch {
					throw new Error( __( 'The pasted text is not valid JSON.', 'ux-studio' ) );
				}
				res = await api< ImportResult >( 'elementor-import/json', {
					method: 'POST',
					body: JSON.stringify( { import_data: parsed, mode, post_id: targetId } ),
				} );
			}
			setResult( res );
		} catch ( e ) {
			setError( ( e as Error ).message );
		} finally {
			setBusy( false );
		}
	}

	return (
		<div className="uxs-form">
			<div className="uxs-form__row">
				<label htmlFor="uxs-ei-source">{ __( 'Source', 'ux-studio' ) }</label>
				<select
					id="uxs-ei-source"
					value={ source }
					onChange={ ( e ) => {
						setSource( e.target.value as Source );
						setResult( null );
						setError( '' );
					} }
				>
					<option value="file">{ __( 'Upload file (.json / .zip)', 'ux-studio' ) }</option>
					<option value="json">{ __( 'Paste template JSON', 'ux-studio' ) }</option>
					<option value="url">{ __( 'From URL', 'ux-studio' ) }</option>
					<option value="html">{ __( 'Paste HTML', 'ux-studio' ) }</option>
				</select>
			</div>

			{ source === 'file' ? (
				<div className="uxs-form__row">
					<label htmlFor="uxs-ei-file">{ __( 'Template file', 'ux-studio' ) }</label>
					<input
						id="uxs-ei-file"
						type="file"
						accept=".json,.zip,application/json,application/zip"
						onChange={ ( e ) => setFile( e.target.files?.[ 0 ] ?? null ) }
					/>
				</div>
			) : null }

			{ source === 'json' ? (
				<div className="uxs-form__row">
					<label htmlFor="uxs-ei-json">{ __( 'Template JSON', 'ux-studio' ) }</label>
					<textarea
						id="uxs-ei-json"
						rows={ 8 }
						value={ json }
						onChange={ ( e ) => setJson( e.target.value ) }
						placeholder='{ "content": [ ... ] }'
					/>
				</div>
			) : null }

			{ source === 'url' ? (
				<>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ei-url">{ __( 'Page URL', 'ux-studio' ) }</label>
						<input
							id="uxs-ei-url"
							type="url"
							value={ url }
							onChange={ ( e ) => setUrl( e.target.value ) }
							placeholder="https://example.com/page"
						/>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ei-selector">{ __( 'Content CSS selector (optional)', 'ux-studio' ) }</label>
						<input
							id="uxs-ei-selector"
							type="text"
							value={ selector }
							onChange={ ( e ) => setSelector( e.target.value ) }
							placeholder="main, #content, .page-content"
						/>
						<p className="uxs-form__help">
							{ __( 'Leave blank to auto-detect the main content.', 'ux-studio' ) }
						</p>
					</div>
				</>
			) : null }

			{ source === 'html' ? (
				<>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ei-title">{ __( 'Page title', 'ux-studio' ) }</label>
						<input
							id="uxs-ei-title"
							type="text"
							value={ title }
							onChange={ ( e ) => setTitle( e.target.value ) }
							placeholder={ __( 'Imported HTML', 'ux-studio' ) }
						/>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ei-html">{ __( 'HTML', 'ux-studio' ) }</label>
						<textarea
							id="uxs-ei-html"
							rows={ 10 }
							value={ html }
							onChange={ ( e ) => setHtml( e.target.value ) }
							placeholder="<section>…</section>"
						/>
					</div>
				</>
			) : null }

			<ModeFields
				source={ source }
				mode={ mode }
				setMode={ setMode }
				postId={ postId }
				setPostId={ setPostId }
			/>

			<button type="button" className="button button-primary" disabled={ busy } onClick={ () => void submit() }>
				{ busy ? <LoaderCircle size={ 14 } /> : <Upload size={ 14 } /> } { __( 'Import', 'ux-studio' ) }
			</button>

			{ error ? (
				<p className="uxs-form__help" style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<span className="uxs-badge is-danger">{ __( 'Error', 'ux-studio' ) }</span> { error }
				</p>
			) : null }
			{ result ? <ResultNotice result={ result } /> : null }
		</div>
	);
}

function ExportTab(): JSX.Element {
	const [ postId, setPostId ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ done, setDone ] = useState( '' );

	async function run(): Promise< void > {
		const id = Number( postId ) || 0;
		if ( id <= 0 ) {
			setError( __( 'Enter a valid page ID.', 'ux-studio' ) );
			return;
		}
		setBusy( true );
		setError( '' );
		setDone( '' );
		try {
			const data = await api< ExportResult >( `elementor-import/export/${ id }` );
			triggerDownload( data );
			setDone( data.title );
		} catch ( e ) {
			setError( ( e as Error ).message );
		} finally {
			setBusy( false );
		}
	}

	return (
		<div className="uxs-form">
			<div className="uxs-form__row">
				<label htmlFor="uxs-ei-export-id">{ __( 'Page ID to export', 'ux-studio' ) }</label>
				<input
					id="uxs-ei-export-id"
					type="number"
					value={ postId }
					onChange={ ( e ) => setPostId( e.target.value ) }
					placeholder="123"
				/>
				<p className="uxs-form__help">
					{ __( 'Exports the page\'s Elementor content and page settings to a downloadable JSON file.', 'ux-studio' ) }
				</p>
			</div>
			<button type="button" className="button button-primary" disabled={ busy } onClick={ () => void run() }>
				{ busy ? <LoaderCircle size={ 14 } /> : <Download size={ 14 } /> } { __( 'Export JSON', 'ux-studio' ) }
			</button>
			{ error ? (
				<p className="uxs-form__help" style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<span className="uxs-badge is-danger">{ __( 'Error', 'ux-studio' ) }</span> { error }
				</p>
			) : null }
			{ done ? (
				<p style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<span className="uxs-badge is-success">{ __( 'Exported', 'ux-studio' ) }</span> <strong>{ done }</strong>.
				</p>
			) : null }
		</div>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'import' );

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
					{ __( 'Elementor Import', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'import' ? 'is-active' : '' } onClick={ () => setTab( 'import' ) }>
					{ __( 'Import', 'ux-studio' ) }
				</button>
				<button className={ tab === 'export' ? 'is-active' : '' } onClick={ () => setTab( 'export' ) }>
					{ __( 'Export', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'import' ? <ImportTab /> : <ExportTab /> }
		</>
	);
}
