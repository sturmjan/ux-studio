/**
 * Import part: load a file, see exactly what it would change (nothing is written), choose, apply, undo.
 */
import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowRight, Check, ChevronDown, ChevronRight, FileUp, KeyRound, LoaderCircle, ShieldCheck, TriangleAlert, Undo2, X } from 'lucide-react';
import { AnimatedCheckbox } from '../../components/AnimatedCheckbox';
import { ApiError, api, queryClient } from '../api';
import { formatDate } from '../date';
import { ModuleIcon } from '../moduleIcons';
import { navigate } from '../route';
import { ConfirmModal, ErrorLine, Warnings } from '../wizard/parts';
import { transferStatusKey } from './Transfer';
import type {
	ApplyResponse,
	ApplyResultRow,
	ImportOptions,
	LoginState,
	PreviewModule,
	PreviewResponse,
	SnapshotInfo,
	TransferStatus,
	UndoResponse,
} from './types';

const DEFAULT_OPTIONS: ImportOptions = { mode: 'merge', apply_enabled: false, disable_with_data: false, allow_maintenance: false };

function message( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

function moduleSummary( m: PreviewModule ): string {
	const parts: string[] = [];
	const n = m.changes.length + m.secrets.length;
	if ( n > 0 ) {
		parts.push( sprintf( /* translators: %d: number of changed settings. */ _n( '%d change', '%d changes', n, 'ux-studio' ), n ) );
	}
	if ( m.data ) {
		const c = m.data.counts.create + m.data.counts.update;
		if ( c > 0 ) {
			parts.push( sprintf( /* translators: 1: content name, 2: number of items. */ __( '%1$s: %2$d', 'ux-studio' ), m.data.label, c ) );
		}
	}
	if ( 'enable' === m.enabled.change ) {
		parts.push( __( 'switch on', 'ux-studio' ) );
	} else if ( 'disable' === m.enabled.change ) {
		parts.push( __( 'switch off', 'ux-studio' ) );
	}
	if ( 0 === parts.length ) {
		parts.push( __( 'no change', 'ux-studio' ) );
	}
	return parts.join( ' · ' );
}

export function ImportPanel( { status }: { status: TransferStatus } ): JSX.Element {
	const [ content, setContent ] = useState( '' );
	const [ fileName, setFileName ] = useState( '' );
	const [ pass, setPass ] = useState( '' );
	const [ options, setOptions ] = useState< ImportOptions >( DEFAULT_OPTIONS );
	const [ preview, setPreview ] = useState< PreviewResponse | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ selection, setSelection ] = useState< Set< string > >( new Set() );
	const [ confirmedOverlap, setConfirmedOverlap ] = useState< Set< string > >( new Set() );
	const [ open, setOpen ] = useState< Set< string > >( new Set() );
	const [ askReplace, setAskReplace ] = useState( false );
	const [ askApply, setAskApply ] = useState( false );
	const [ askUndo, setAskUndo ] = useState( false );
	const [ result, setResult ] = useState< ApplyResponse | null >( null );
	const [ undone, setUndone ] = useState< UndoResponse | null >( null );
	const [ snapshot, setSnapshot ] = useState< SnapshotInfo >( status.snapshot );
	const [ dragging, setDragging ] = useState( false );
	const fresh = useRef( true );
	const fileInput = useRef< HTMLInputElement >( null );

	const refreshStatus = (): void => {
		void queryClient.invalidateQueries( { queryKey: transferStatusKey } );
		void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
	};

	const runPreview = useCallback(
		async ( text: string, passphrase: string, opts: ImportOptions ): Promise< void > => {
			setBusy( true );
			setError( null );
			try {
				const data = await api< PreviewResponse >( 'transfer/import/preview', {
					method: 'POST',
					body: JSON.stringify( { content: text, passphrase, options: opts } ),
				} );
				setPreview( data );
				setSelection( ( prev ) => {
					const ok = data.modules.filter( ( m ) => m.selectable && m.has_work ).map( ( m ) => m.id );
					if ( fresh.current ) {
						fresh.current = false;
						return new Set( ok );
					}
					return new Set( [ ...prev ].filter( ( id ) => ok.includes( id ) ) );
				} );
			} catch ( e ) {
				setPreview( null );
				setError( message( e ) );
				if ( e instanceof ApiError && 'uxstudio_transfer_bad_passphrase' === e.code ) {
					setPass( '' );
				}
			} finally {
				setBusy( false );
			}
		},
		[]
	);

	// The diff depends on the options (merge or replace, switching modules), so it is asked again when they change.
	const optionsKey = JSON.stringify( options );
	const loaded = useRef( '' );
	useEffect( () => {
		if ( preview && content && loaded.current === content ) {
			void runPreview( content, pass, options );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ optionsKey ] );

	const start = ( text: string, name: string ): void => {
		fresh.current = true;
		loaded.current = text;
		setContent( text );
		setFileName( name );
		setResult( null );
		setUndone( null );
		setOptions( DEFAULT_OPTIONS );
		setConfirmedOverlap( new Set() );
		setOpen( new Set() );
		void runPreview( text, '', DEFAULT_OPTIONS );
	};

	const readFile = async ( file: File | undefined ): Promise< void > => {
		if ( ! file ) {
			return;
		}
		if ( file.size > status.max_bytes ) {
			setError( __( 'The file is larger than 2 MB, so it is not a settings export of UX Studio.', 'ux-studio' ) );
			return;
		}
		start( await file.text(), file.name );
	};

	const onDrop = ( e: DragEvent< HTMLElement > ): void => {
		e.preventDefault();
		setDragging( false );
		void readFile( e.dataTransfer.files[ 0 ] );
	};

	const reset = (): void => {
		setPreview( null );
		setContent( '' );
		setFileName( '' );
		setPass( '' );
		setError( null );
		setResult( null );
		setSelection( new Set() );
	};

	const apply = useMutation( {
		mutationFn: () =>
			api< ApplyResponse >( 'transfer/import/apply', {
				method: 'POST',
				body: JSON.stringify( { content, passphrase: pass, options: { ...options, modules: [ ...selection ], confirmed: [ ...confirmedOverlap ] } } ),
			} ),
		onSuccess: ( data ) => {
			setResult( data );
			setSnapshot( data.snapshot );
			setAskApply( false );
			setPreview( null );
			refreshStatus();
		},
	} );

	const undo = useMutation( {
		mutationFn: () => api< UndoResponse >( 'transfer/import/undo', { method: 'POST' } ),
		onSuccess: ( data ) => {
			setUndone( data );
			setSnapshot( { available: false, time: 0, expires: 0, modules: [], user_id: 0 } );
			setResult( null );
			setAskUndo( false );
			refreshStatus();
		},
	} );

	const chosen = useMemo( () => ( preview?.modules ?? [] ).filter( ( m ) => selection.has( m.id ) ), [ preview, selection ] );
	const changeCount = chosen.reduce( ( n, m ) => n + m.changes.length + m.secrets.length + ( m.data ? m.data.counts.create + m.data.counts.update : 0 ) + ( [ 'enable', 'disable' ].includes( m.enabled.change ) ? 1 : 0 ), 0 );
	const hasMaintenance = !! preview?.modules.some( ( m ) => 'maintenance-mode' === m.id );
	const needsPass = 'needs_passphrase' === preview?.secrets_state;

	const toggleSet = ( set: Set< string >, id: string, on: boolean ): Set< string > => {
		const next = new Set( set );
		if ( on ) {
			next.add( id );
		} else {
			next.delete( id );
		}
		return next;
	};

	return (
		<div className="uxs-tr">
			{ snapshot.available && (
				<div className="uxs-tr-undo" role="status">
					<Undo2 size={ 16 } aria-hidden />
					<p>
						{ sprintf(
							/* translators: 1: date and time of the import, 2: date until the snapshot is kept. */
							__( 'The last import (%1$s) can be undone until %2$s.', 'ux-studio' ),
							formatDate( snapshot.time * 1000 ),
							formatDate( snapshot.expires * 1000, { dateOnly: true } )
						) }
					</p>
					<button type="button" className="button" onClick={ () => setAskUndo( true ) }>
						{ __( 'Undo the import', 'ux-studio' ) }
					</button>
				</div>
			) }

			{ undone && (
				<div className="uxs-tr-card" role="status">
					<p className="uxs-tr-ok">
						<Check size={ 14 } aria-hidden /> { __( 'The import was undone: settings, the list of modules and the content it created are back as they were.', 'ux-studio' ) }
					</p>
					{ undone.modules_off.length > 0 && <p className="uxs-tr-muted">{ sprintf( /* translators: %s: module ids. */ __( 'Switched off again: %s.', 'ux-studio' ), undone.modules_off.join( ', ' ) ) }</p> }
					<Warnings warnings={ undone.errors } />
				</div>
			) }

			{ ! preview && ! result && (
				<section className="uxs-tr-card" aria-labelledby="uxs-tr-load">
					<header className="uxs-tr-card__head">
						<h2 id="uxs-tr-load">{ __( 'Load a file', 'ux-studio' ) }</h2>
					</header>
					<label
						className={ `uxs-tr-drop${ dragging ? ' is-over' : '' }` }
						onDragOver={ ( e ) => {
							e.preventDefault();
							setDragging( true );
						} }
						onDragLeave={ () => setDragging( false ) }
						onDrop={ onDrop }
					>
						<FileUp size={ 28 } aria-hidden />
						<strong>{ __( 'Drop the .json file here, or click to choose it', 'ux-studio' ) }</strong>
						<span className="uxs-tr-muted">{ __( 'A settings export of UX Studio, up to 2 MB. Nothing is saved yet: you will see what it would change first.', 'ux-studio' ) }</span>
						<input ref={ fileInput } type="file" accept=".json,application/json" onChange={ ( e ) => void readFile( e.target.files?.[ 0 ] ) } />
					</label>
					<PasteBox busy={ busy } onUse={ ( text ) => start( text, '' ) } />
					<ErrorLine text={ error } />
					{ busy && (
						<p className="uxs-tr-muted">
							<LoaderCircle size={ 14 } className="uxs-tr-spin" aria-hidden /> { __( 'Checking the file…', 'ux-studio' ) }
						</p>
					) }
				</section>
			) }

			{ preview && (
				<>
					<section className="uxs-tr-card" aria-labelledby="uxs-tr-file">
						<header className="uxs-tr-card__head">
							<h2 id="uxs-tr-file">{ __( 'The file', 'ux-studio' ) }</h2>
							<button type="button" className="button" onClick={ reset }>
								<X size={ 14 } aria-hidden /> { __( 'Choose another file', 'ux-studio' ) }
							</button>
						</header>
						<dl className="uxs-tr-facts">
							{ fileName && (
								<>
									<dt>{ __( 'File', 'ux-studio' ) }</dt>
									<dd>{ fileName }</dd>
								</>
							) }
							<dt>{ __( 'From site', 'ux-studio' ) }</dt>
							<dd>{ preview.file.site.name || preview.file.site.host || '-' }{ preview.file.site.host && preview.file.site.name ? ` (${ preview.file.site.host })` : '' }</dd>
							<dt>{ __( 'Exported', 'ux-studio' ) }</dt>
							<dd>{ formatDate( preview.file.exported_at ) || '-' }</dd>
							<dt>{ __( 'Plugin version', 'ux-studio' ) }</dt>
							<dd>{ preview.file.plugin_version || '-' }</dd>
						</dl>
						<Warnings warnings={ preview.warnings.map( ( w ) => w.message ) } />
						{ needsPass && (
							<div className="uxs-tr-pass">
								<p>
									<KeyRound size={ 14 } aria-hidden /> { __( 'The file holds secret values (API keys, passwords) encrypted with a passphrase. Type it to include them in the preview, or leave it and import without them.', 'ux-studio' ) }
								</p>
								{ ! preview.sodium && <p className="uxs-tr-warn">{ __( 'This server has no libsodium (the PHP sodium extension), so the secrets cannot be opened here.', 'ux-studio' ) }</p> }
								<label>
									<span>{ __( 'Passphrase', 'ux-studio' ) }</span>
									<input type="password" autoComplete="off" value={ pass } onChange={ ( e ) => setPass( e.target.value ) } />
								</label>
								<button type="button" className="button" disabled={ '' === pass || busy || ! preview.sodium } onClick={ () => void runPreview( content, pass, options ) }>
									{ __( 'Open the secrets', 'ux-studio' ) }
								</button>
							</div>
						) }
						{ 'decrypted' === preview.secrets_state && (
							<p className="uxs-tr-ok">
								<Check size={ 14 } aria-hidden /> { __( 'The secrets were opened. They are never shown here, only whether they differ.', 'ux-studio' ) }
							</p>
						) }
						<ErrorLine text={ error } />
					</section>

					<section className="uxs-tr-card uxs-tr-safe" aria-labelledby="uxs-tr-safe">
						<header className="uxs-tr-card__head">
							<h2 id="uxs-tr-safe">
								<ShieldCheck size={ 16 } aria-hidden /> { __( 'Safety measures', 'ux-studio' ) }
							</h2>
						</header>
						{ preview.measures.length > 0 ? (
							<ul className="uxs-tr-measures">
								{ preview.measures.map( ( m, i ) => (
									<li key={ `${ m.module }-${ m.code }-${ i }` }>
										<TriangleAlert size={ 14 } aria-hidden />
										<span>
											<strong>{ m.module_name }:</strong> { m.message }
										</span>
									</li>
								) ) }
							</ul>
						) : (
							<p className="uxs-tr-muted">{ __( 'None of the rules below had to hold anything back for this file.', 'ux-studio' ) }</p>
						) }
						<details>
							<summary>{ __( 'All rules that always apply to an import', 'ux-studio' ) }</summary>
							<ul>
								{ preview.safety_rules.map( ( r ) => (
									<li key={ r }>{ r }</li>
								) ) }
							</ul>
						</details>
					</section>

					<section className="uxs-tr-card" aria-labelledby="uxs-tr-how">
						<header className="uxs-tr-card__head">
							<h2 id="uxs-tr-how">{ __( 'How to import', 'ux-studio' ) }</h2>
						</header>
						<div className="uxs-tr-radios" role="radiogroup" aria-label={ __( 'How to import', 'ux-studio' ) }>
							<label>
								<input type="radio" name="uxs-tr-mode" checked={ 'merge' === options.mode } onChange={ () => setOptions( { ...options, mode: 'merge' } ) } />
								<span>
									<strong>{ __( 'Merge', 'ux-studio' ) }</strong> <span className="uxs-badge is-success">{ __( 'recommended', 'ux-studio' ) }</span>
									<br />
									<span className="uxs-tr-muted">{ __( 'Overwrites only the settings the file lists. Everything else stays as it is.', 'ux-studio' ) }</span>
								</span>
							</label>
							<label>
								<input type="radio" name="uxs-tr-mode" checked={ 'replace' === options.mode } onChange={ () => setAskReplace( true ) } />
								<span>
									<strong>{ __( 'Replace the whole setting of a module', 'ux-studio' ) }</strong>
									<br />
									<span className="uxs-tr-muted">{ __( 'Settings the file does not mention go back to their defaults.', 'ux-studio' ) }</span>
								</span>
							</label>
						</div>
						<div className="uxs-tr-opt">
							<AnimatedCheckbox isSelected={ options.apply_enabled } onChange={ ( on ) => setOptions( { ...options, apply_enabled: on, disable_with_data: on ? options.disable_with_data : false } ) }>
								{ __( 'Switch modules on and off as in the file', 'ux-studio' ) }
							</AnimatedCheckbox>
							<p className="uxs-tr-muted">{ __( 'Off by default. Switching on goes through the same check as the Modules screen (a plugin that does the same job can refuse it).', 'ux-studio' ) }</p>
						</div>
						{ options.apply_enabled && (
							<div className="uxs-tr-opt">
								<AnimatedCheckbox isSelected={ options.disable_with_data } onChange={ ( on ) => setOptions( { ...options, disable_with_data: on } ) }>
									{ __( 'Also switch off modules that hold data here', 'ux-studio' ) }
								</AnimatedCheckbox>
								<p className="uxs-tr-muted">{ __( 'Forms, redirects, snippets and the like stay on unless you tick this. Their data is never deleted.', 'ux-studio' ) }</p>
							</div>
						) }
						{ hasMaintenance && (
							<div className="uxs-tr-opt">
								<AnimatedCheckbox isSelected={ options.allow_maintenance } onChange={ ( on ) => setOptions( { ...options, allow_maintenance: on } ) }>
									{ __( 'Allow switching on maintenance mode', 'ux-studio' ) }
								</AnimatedCheckbox>
								<p className="uxs-tr-muted">{ __( 'Maintenance mode hides the site from visitors. An import never does that unless you tick this.', 'ux-studio' ) }</p>
							</div>
						) }
					</section>

					<section className="uxs-tr-card" aria-labelledby="uxs-tr-mods">
						<header className="uxs-tr-card__head">
							<h2 id="uxs-tr-mods">{ __( 'What it would change', 'ux-studio' ) }</h2>
							<span className="uxs-badge">
								{ sprintf(
									/* translators: 1: changes, 2: settings without change. */
									__( '%1$d changes, %2$d without change', 'ux-studio' ),
									preview.totals.changes,
									preview.totals.same
								) }
							</span>
						</header>
						{ busy && (
							<p className="uxs-tr-muted">
								<LoaderCircle size={ 14 } className="uxs-tr-spin" aria-hidden /> { __( 'Checking the file…', 'ux-studio' ) }
							</p>
						) }
						<ul className="uxs-tr-mods">
							{ preview.modules.map( ( m ) => (
								<ModuleRow
									key={ m.id }
									m={ m }
									checked={ selection.has( m.id ) }
									open={ open.has( m.id ) }
									confirmed={ confirmedOverlap.has( m.id ) }
									onCheck={ ( on ) => setSelection( toggleSet( selection, m.id, on ) ) }
									onOpen={ () => setOpen( toggleSet( open, m.id, ! open.has( m.id ) ) ) }
									onConfirm={ ( on ) => setConfirmedOverlap( toggleSet( confirmedOverlap, m.id, on ) ) }
								/>
							) ) }
						</ul>
						<div className="uxs-tr-final">
							<span className="uxs-tr-final__sum">
								{ sprintf(
									/* translators: 1: modules chosen, 2: changes in them. */
									__( 'Chosen: %1$d modules, %2$d changes.', 'ux-studio' ),
									chosen.length,
									changeCount
								) }
							</span>
							<button type="button" className="button button-primary" disabled={ 0 === chosen.length || busy || apply.isPending } onClick={ () => setAskApply( true ) }>
								{ __( 'Import', 'ux-studio' ) }
							</button>
						</div>
					</section>
				</>
			) }

			{ result && <ResultView result={ result } onAgain={ reset } onUndo={ () => setAskUndo( true ) } /> }

			{ askReplace && (
				<ConfirmModal
					id="uxs-tr-replace"
					title={ __( 'Replace the whole setting of the modules?', 'ux-studio' ) }
					confirmLabel={ __( 'Yes, replace', 'ux-studio' ) }
					onCancel={ () => setAskReplace( false ) }
					onConfirm={ () => {
						setOptions( { ...options, mode: 'replace' } );
						setAskReplace( false );
					} }
				>
					<p>{ __( 'In every module you import, the settings the file does not mention go back to their defaults. Connections and keys that never travel stay as they are. You can still undo the import for 30 days.', 'ux-studio' ) }</p>
				</ConfirmModal>
			) }
			{ askApply && (
				<ConfirmModal
					id="uxs-tr-apply"
					title={ __( 'Import now?', 'ux-studio' ) }
					confirmLabel={ apply.isPending ? __( 'Importing…', 'ux-studio' ) : __( 'Import', 'ux-studio' ) }
					busy={ apply.isPending }
					error={ apply.isError ? message( apply.error ) : null }
					onCancel={ () => setAskApply( false ) }
					onConfirm={ () => apply.mutate() }
				>
					<p>
						{ sprintf(
							/* translators: 1: modules, 2: changes. */
							__( '%1$d modules and %2$d changes will be saved.', 'ux-studio' ),
							chosen.length,
							changeCount
						) }{ ' ' }
						{ 'replace' === options.mode ? __( 'Mode: replace the whole setting.', 'ux-studio' ) : __( 'Mode: merge.', 'ux-studio' ) }
					</p>
					<ul className="uxs-tr-sumlist">
						{ chosen.map( ( m ) => (
							<li key={ m.id }>
								<strong>{ m.name }</strong>: { moduleSummary( m ) }
							</li>
						) ) }
					</ul>
					<p className="uxs-tr-muted">{ __( 'A snapshot is taken first, so you can undo the import for 30 days.', 'ux-studio' ) }</p>
				</ConfirmModal>
			) }
			{ askUndo && (
				<ConfirmModal
					id="uxs-tr-undo"
					title={ __( 'Undo the last import?', 'ux-studio' ) }
					confirmLabel={ undo.isPending ? __( 'Undoing…', 'ux-studio' ) : __( 'Undo the import', 'ux-studio' ) }
					busy={ undo.isPending }
					error={ undo.isError ? message( undo.error ) : null }
					onCancel={ () => setAskUndo( false ) }
					onConfirm={ () => undo.mutate() }
				>
					<p>{ __( 'The settings of the modules the import touched, the list of modules switched on, and the content the import created or changed go back to how they were just before it. Changes made since then in those modules are lost.', 'ux-studio' ) }</p>
				</ConfirmModal>
			) }
		</div>
	);
}

function PasteBox( { onUse, busy }: { onUse: ( text: string ) => void; busy: boolean } ): JSX.Element {
	const [ text, setText ] = useState( '' );
	return (
		<div className="uxs-tr-paste">
			<label htmlFor="uxs-tr-paste">{ __( 'Or paste the text of the file', 'ux-studio' ) }</label>
			<textarea id="uxs-tr-paste" rows={ 5 } value={ text } onChange={ ( e ) => setText( e.target.value ) } spellCheck={ false } placeholder='{ "format": "uxstudio-settings", … }' />
			<button type="button" className="button" disabled={ '' === text.trim() || busy } onClick={ () => onUse( text ) }>
				{ __( 'Check the text', 'ux-studio' ) }
			</button>
		</div>
	);
}

function ModuleRow( {
	m,
	checked,
	open,
	confirmed,
	onCheck,
	onOpen,
	onConfirm,
}: {
	m: PreviewModule;
	checked: boolean;
	open: boolean;
	confirmed: boolean;
	onCheck: ( on: boolean ) => void;
	onOpen: () => void;
	onConfirm: ( on: boolean ) => void;
} ): JSX.Element {
	const disabled = ! m.selectable || ! m.has_work;
	const notes = m.unknown.length + m.non_portable.length + m.rejected.length + m.skipped.length + m.dropped.length + m.media_missing.length + m.needs_manual.length + m.warnings.length + m.measures.length;
	const hasDetail = m.changes.length > 0 || m.secrets.length > 0 || !! m.data || notes > 0 || '' !== m.blocked;
	return (
		<li className={ `uxs-tr-m${ checked ? ' is-selected' : '' }${ disabled ? ' is-idle' : '' }` }>
			<div className="uxs-tr-m__head">
				<AnimatedCheckbox isSelected={ checked && ! disabled } onChange={ onCheck } isDisabled={ disabled }>
					<span className="uxs-tr-mod">
						<span className="uxs-tile__icon">
							<ModuleIcon name={ m.icon } size={ 18 } />
						</span>
						<span className="uxs-tr-mod__text">
							<strong>{ m.name }</strong>
							<span className="uxs-tr-muted">{ '' !== m.blocked ? m.blocked : moduleSummary( m ) }</span>
						</span>
					</span>
				</AnimatedCheckbox>
				<span className="uxs-tr-badges">
					{ ! m.known && <span className="uxs-badge is-danger">{ __( 'unknown', 'ux-studio' ) }</span> }
					{ 'enable' === m.enabled.change && <span className="uxs-badge is-success">{ __( 'will be switched on', 'ux-studio' ) }</span> }
					{ 'disable' === m.enabled.change && <span className="uxs-badge is-warning">{ __( 'will be switched off', 'ux-studio' ) }</span> }
					{ 'blocked' === m.enabled.overlap.status && <span className="uxs-badge is-danger">{ __( 'overlap', 'ux-studio' ) }</span> }
				</span>
				{ hasDetail && (
					<button type="button" className="uxs-tr-m__toggle" aria-expanded={ open } aria-label={ sprintf( /* translators: %s: module name. */ __( 'Details: %s', 'ux-studio' ), m.name ) } onClick={ onOpen }>
						{ open ? <ChevronDown size={ 16 } aria-hidden /> : <ChevronRight size={ 16 } aria-hidden /> }
					</button>
				) }
			</div>
			{ 'confirm' === m.enabled.overlap.status && (
				<div className="uxs-tr-opt">
					<AnimatedCheckbox isSelected={ confirmed } onChange={ onConfirm }>
						{ sprintf(
							/* translators: %s: names of plugins. */
							__( 'Switch it on although it overlaps with %s', 'ux-studio' ),
							m.enabled.overlap.plugins.join( ', ' )
						) }
					</AnimatedCheckbox>
				</div>
			) }
			{ open && <Detail m={ m } /> }
		</li>
	);
}

function Detail( { m }: { m: PreviewModule } ): JSX.Element {
	const list = ( items: { key: string; label: string }[] ): string => items.map( ( i ) => i.label ).join( ', ' );
	return (
		<div className="uxs-tr-detail">
			{ m.changes.length > 0 && (
				<table className="uxs-dns-diff">
					<thead>
						<tr>
							<th>{ __( 'Setting', 'ux-studio' ) }</th>
							<th>{ __( 'Now', 'ux-studio' ) }</th>
							<th aria-hidden />
							<th>{ __( 'After import', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ m.changes.map( ( c ) => (
							<tr key={ c.key } className="is-changed">
								<th scope="row">
									{ c.label }
									{ c.sensitive && <span className="uxs-badge">{ __( 'sensitive', 'ux-studio' ) }</span> }
								</th>
								<td className="is-old">{ c.old }</td>
								<td aria-hidden>
									<ArrowRight size={ 14 } />
								</td>
								<td className="is-new">{ c.new }</td>
							</tr>
						) ) }
						{ m.secrets.map( ( s ) => (
							<tr key={ `secret-${ s.key }` } className="is-changed">
								<th scope="row">
									{ s.label } <KeyRound size={ 12 } aria-hidden />
								</th>
								<td>{ s.has_current ? '••••••' : __( '(empty)', 'ux-studio' ) }</td>
								<td aria-hidden>
									<ArrowRight size={ 14 } />
								</td>
								<td className="is-new">{ __( 'a different secret value', 'ux-studio' ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ m.data && (
				<div className="uxs-tr-data">
					<h4>
						{ m.data.label }
						{ ' · ' }
						{ sprintf(
							/* translators: 1: new items, 2: updated items, 3: unchanged items. */
							__( '%1$d new, %2$d updated, %3$d unchanged', 'ux-studio' ),
							m.data.counts.create,
							m.data.counts.update,
							m.data.counts.same
						) }
					</h4>
					{ '' !== m.data.blocked && <p className="uxs-tr-warn">{ m.data.blocked }</p> }
					{ m.data.items.length > 0 && (
						<ul>
							{ m.data.items.slice( 0, 60 ).map( ( it ) => (
								<li key={ it.key }>
									<span className={ `uxs-badge ${ 'create' === it.action ? 'is-success' : 'update' === it.action ? 'is-warning' : '' }` }>
										{ 'create' === it.action ? __( 'new', 'ux-studio' ) : 'update' === it.action ? __( 'updated', 'ux-studio' ) : __( 'same', 'ux-studio' ) }
									</span>{ ' ' }
									{ it.label }
								</li>
							) ) }
							{ m.data.items.length > 60 && <li className="uxs-tr-muted">{ sprintf( /* translators: %d: number of items. */ __( '…and %d more', 'ux-studio' ), m.data.items.length - 60 ) }</li> }
						</ul>
					) }
					{ m.data.rejected.map( ( r, i ) => (
						<p key={ i } className="uxs-tr-warn">
							{ r.label }: { r.message }
						</p>
					) ) }
					<Warnings warnings={ m.data.warnings } />
				</div>
			) }
			<Warnings warnings={ [ ...m.warnings, ...m.skipped.map( ( s ) => `${ s.label }: ${ s.message }` ), ...m.rejected.map( ( r ) => `${ r.label }: ${ r.message }` ), ...m.media_missing.map( ( i ) => sprintf( /* translators: %s: name of a setting. */ __( '%s: the image is not in the media library of this site, so it was left unset.', 'ux-studio' ), i.label ) ) ] } />
			<ul className="uxs-tr-notes">
				{ m.dropped.length > 0 && <li>{ m.dropped.map( ( d ) => sprintf( /* translators: 1: setting name, 2: number of choices. */ __( '%1$s: %2$d choices do not exist on this site and were left out.', 'ux-studio' ), d.label, d.count ) ).join( ' ' ) }</li> }
				{ m.non_portable.length > 0 && <li>{ sprintf( /* translators: %s: names of settings. */ __( 'Not transferred (belongs to one site): %s.', 'ux-studio' ), list( m.non_portable ) ) }</li> }
				{ m.unknown.length > 0 && <li>{ sprintf( /* translators: %s: names of keys. */ __( 'Not known to this version, skipped: %s.', 'ux-studio' ), m.unknown.join( ', ' ) ) }</li> }
				{ m.needs_manual.length > 0 && <li>{ sprintf( /* translators: %s: names of settings. */ __( 'To fill in by hand: %s.', 'ux-studio' ), list( m.needs_manual ) ) }</li> }
				{ m.same > 0 && <li>{ sprintf( /* translators: %d: number of settings. */ _n( '%d setting is already the same.', '%d settings are already the same.', m.same, 'ux-studio' ), m.same ) }</li> }
			</ul>
		</div>
	);
}

function statusBadge( row: ApplyResultRow ): JSX.Element {
	if ( 'ok' === row.status ) {
		return <span className="uxs-badge is-success">{ __( 'done', 'ux-studio' ) }</span>;
	}
	if ( 'partial' === row.status ) {
		return <span className="uxs-badge is-warning">{ __( 'partly done', 'ux-studio' ) }</span>;
	}
	if ( 'failed' === row.status ) {
		return <span className="uxs-badge is-danger">{ __( 'failed', 'ux-studio' ) }</span>;
	}
	return <span className="uxs-badge">{ __( 'skipped', 'ux-studio' ) }</span>;
}

function LoginNote( { login }: { login: LoginState } ): JSX.Element | null {
	if ( ! login.touched ) {
		return null;
	}
	if ( login.reverted ) {
		return (
			<p className="uxs-tr-warn" role="alert">
				<TriangleAlert size={ 14 } aria-hidden /> { __( 'The new login address did not show the login form, so the Security settings were put back to what they were. Nobody was locked out.', 'ux-studio' ) }
			</p>
		);
	}
	if ( login.pending ) {
		return (
			<div className="uxs-callout">
				<TriangleAlert size={ 16 } aria-hidden />
				<p>
					{ sprintf(
						/* translators: %s: the new login address. */
						__( 'The new login address %s is saved, but wp-login.php stays open until you open the new address and confirm it.', 'ux-studio' ),
						login.url
					) }{ ' ' }
					<a href="#/module?id=security-optimization" onClick={ ( e ) => {
						e.preventDefault();
						navigate( 'module', { id: 'security-optimization' } );
					} }>
						{ __( 'Open the Security module', 'ux-studio' ) }
					</a>
				</p>
			</div>
		);
	}
	return (
		<p className="uxs-tr-ok">
			<Check size={ 14 } aria-hidden /> { __( 'Login check: wp-login.php is reachable.', 'ux-studio' ) }
		</p>
	);
}

function ResultView( { result, onAgain, onUndo }: { result: ApplyResponse; onAgain: () => void; onUndo: () => void } ): JSX.Element {
	return (
		<section className="uxs-tr-card" aria-labelledby="uxs-tr-result" role="status">
			<header className="uxs-tr-card__head">
				<h2 id="uxs-tr-result">{ __( 'Result of the import', 'ux-studio' ) }</h2>
				<span className="uxs-badge">
					{ sprintf(
						/* translators: 1: modules done, 2: skipped, 3: failed. */
						__( '%1$d done, %2$d skipped, %3$d failed', 'ux-studio' ),
						result.summary.ok,
						result.summary.skipped,
						result.summary.failed
					) }
				</span>
			</header>
			<LoginNote login={ result.login } />
			<ul className="uxs-tr-results">
				{ result.results.map( ( r ) => (
					<li key={ r.id }>
						<div className="uxs-tr-results__row">
							<strong>{ r.name }</strong>
							{ statusBadge( r ) }
							<span className="uxs-tr-muted">
								{ r.written > 0 && sprintf( /* translators: %d: number of settings saved. */ _n( '%d setting saved', '%d settings saved', r.written, 'ux-studio' ), r.written ) }
								{ r.data && ` ${ r.data.label }: ${ r.data.created } + ${ r.data.updated }` }
								{ 'enabled' === r.enabled && ` ${ __( 'switched on', 'ux-studio' ) }` }
								{ 'disabled' === r.enabled && ` ${ __( 'switched off', 'ux-studio' ) }` }
							</span>
						</div>
						{ '' !== r.reason && <p className="uxs-tr-muted">{ r.reason }</p> }
						<Warnings warnings={ [ ...r.warnings, ...Object.entries( r.skipped ).map( ( [ k, v ] ) => `${ k }: ${ v }` ), ...( r.data?.failed.map( ( f ) => `${ f.label }: ${ f.message }` ) ?? [] ) ] } />
					</li>
				) ) }
			</ul>
			<div className="uxs-tr-final">
				<span className="uxs-tr-muted">{ __( 'A snapshot was taken before the import. You can undo it for 30 days.', 'ux-studio' ) }</span>
				<div className="uxs-tr-final__actions">
					{ result.snapshot.available && (
						<button type="button" className="button" onClick={ onUndo }>
							<Undo2 size={ 14 } aria-hidden /> { __( 'Undo the import', 'ux-studio' ) }
						</button>
					) }
					<button type="button" className="button button-primary" onClick={ onAgain }>
						{ __( 'Import another file', 'ux-studio' ) }
					</button>
				</div>
			</div>
		</section>
	);
}
