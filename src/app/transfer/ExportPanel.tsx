/**
 * Export part: choose modules, choose what goes into the file, download or copy it.
 */
import { useEffect, useMemo, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Check, ClipboardCopy, Download, FileJson, Info, KeyRound, LoaderCircle, Search, ShieldAlert } from 'lucide-react';
import { AnimatedCheckbox } from '../../components/AnimatedCheckbox';
import { api } from '../api';
import { GROUPS } from '../ModuleGrid';
import { ModuleIcon } from '../moduleIcons';
import { pushToast } from '../notify';
import type { ExportOptions, ExportResult, ExportSummary, TransferModule, TransferStatus } from './types';

function groupLabel( id: string ): string {
	return GROUPS.find( ( g ) => g.id === id )?.label ?? __( 'Other', 'ux-studio' );
}

function useDebounced< T >( value: T, ms: number ): T {
	const [ out, setOut ] = useState( value );
	useEffect( () => {
		const t = window.setTimeout( () => setOut( value ), ms );
		return () => window.clearTimeout( t );
	}, [ value, ms ] );
	return out;
}

export function ExportPanel( { status, preselect }: { status: TransferStatus; preselect: string } ): JSX.Element {
	const known = useMemo( () => new Set( status.modules.map( ( m ) => m.id ) ), [ status.modules ] );
	const [ selected, setSelected ] = useState< Set< string > >( () => ( preselect && known.has( preselect ) ? new Set( [ preselect ] ) : new Set() ) );
	const [ search, setSearch ] = useState( '' );
	const [ includeEnabled, setIncludeEnabled ] = useState( true );
	const [ includeData, setIncludeData ] = useState( false );
	const [ includeSecrets, setIncludeSecrets ] = useState( false );
	const [ pass, setPass ] = useState( '' );
	const [ pass2, setPass2 ] = useState( '' );
	const [ summary, setSummary ] = useState< ExportSummary | null >( null );
	const [ done, setDone ] = useState< { name: string; size: number; copied: boolean } | null >( null );
	const [ showNever, setShowNever ] = useState( false );

	// Coming from a module's "Export this setting" link: bring the ticked module into view.
	useEffect( () => {
		if ( preselect && known.has( preselect ) ) {
			window.setTimeout( () => document.getElementById( `uxs-tr-${ preselect }` )?.scrollIntoView( { block: 'center' } ), 150 );
		}
	}, [ preselect, known ] );

	const needle = search.trim().toLocaleLowerCase();
	const visible = status.modules.filter( ( m ) => '' === needle || `${ m.name } ${ m.description } ${ m.id } ${ m.parent_name }`.toLocaleLowerCase().includes( needle ) );
	const groups = useMemo( () => {
		const order = [ ...GROUPS.map( ( g ) => g.id ), '__other' ];
		const map = new Map< string, TransferModule[] >();
		visible.forEach( ( m ) => {
			const key = GROUPS.some( ( g ) => g.id === m.group ) ? m.group : '__other';
			map.set( key, [ ...( map.get( key ) ?? [] ), m ] );
		} );
		return order
			.filter( ( id ) => map.has( id ) )
			.map( ( id ) => ( { id, label: '__other' === id ? __( 'Other', 'ux-studio' ) : groupLabel( id ), items: ( map.get( id ) ?? [] ).sort( ( a, b ) => a.name.localeCompare( b.name ) ) } ) );
	}, [ visible ] );

	const toggle = ( id: string, on: boolean ): void => {
		setDone( null );
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( on ) {
				next.add( id );
			} else {
				next.delete( id );
			}
			return next;
		} );
	};
	const chosen = status.modules.filter( ( m ) => selected.has( m.id ) );
	const withData = chosen.filter( ( m ) => m.data && m.data.allowed );
	const secretsAvailable = chosen.reduce( ( n, m ) => n + m.secrets, 0 );
	const blockedData = chosen.filter( ( m ) => m.data && ! m.data.allowed );

	const passProblem = ! includeSecrets
		? ''
		: pass.length < status.min_passphrase
		? sprintf(
				/* translators: %d: minimum length of the passphrase. */
				__( 'At least %d characters.', 'ux-studio' ),
				status.min_passphrase
		  )
		: pass !== pass2
		? __( 'The two passphrases are not the same.', 'ux-studio' )
		: '';
	const options: ExportOptions = {
		include_enabled: includeEnabled,
		include_data: includeData,
		include_secrets: includeSecrets,
		passphrase: pass,
		passphrase_confirm: pass2,
	};

	// What the file would hold, counted by the server (no file, nothing logged).
	const previewKey = useDebounced( JSON.stringify( { ids: [ ...selected ].sort(), includeEnabled, includeData, includeSecrets } ), 300 );
	useEffect( () => {
		const { ids } = JSON.parse( previewKey ) as { ids: string[] };
		if ( 0 === ids.length ) {
			setSummary( null );
			return;
		}
		let alive = true;
		api< ExportSummary >( 'transfer/export-preview', {
			method: 'POST',
			body: JSON.stringify( { modules: ids, options: { include_enabled: includeEnabled, include_data: includeData, include_secrets: includeSecrets } } ),
		} )
			.then( ( s ) => alive && setSummary( s ) )
			.catch( () => alive && setSummary( null ) );
		return () => {
			alive = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ previewKey ] );

	const exportNow = useMutation( {
		mutationFn: ( how: 'download' | 'copy' ) =>
			api< ExportResult >( 'transfer/export', { method: 'POST', body: JSON.stringify( { modules: [ ...selected ], options } ) } ).then( async ( r ) => {
				if ( 'copy' === how ) {
					if ( null === r.content ) {
						throw new Error( __( 'The file is too large to copy. Download it instead.', 'ux-studio' ) );
					}
					await navigator.clipboard.writeText( r.content );
				} else {
					window.location.assign( r.download_url );
				}
				return { r, how };
			} ),
		onSuccess: ( { r, how } ) => {
			setDone( { name: r.filename, size: r.size, copied: 'copy' === how } );
			if ( r.summary.warnings.length ) {
				pushToast( 'error', r.summary.warnings.join( ' ' ) );
			}
		},
	} );

	const canExport = selected.size > 0 && '' === passProblem && ! exportNow.isPending;
	const size = ( bytes: number ): string => ( bytes > 1024 ? `${ Math.round( bytes / 1024 ) } kB` : `${ bytes } B` );

	return (
		<div className="uxs-tr">
			<div className="uxs-callout uxs-tr-why">
				<FileJson size={ 16 } aria-hidden />
				<p>
					{ __( 'The file is JSON: plain text that keeps exact types (a switch is true or false, a number stays a number), has no hidden parts and cannot carry anything that runs. That is why there is no XML. You can open and read it in any text editor.', 'ux-studio' ) }
				</p>
			</div>

			<section className="uxs-tr-card" aria-labelledby="uxs-tr-modules">
				<header className="uxs-tr-card__head">
					<h2 id="uxs-tr-modules">{ __( 'Modules', 'ux-studio' ) }</h2>
					<span className="uxs-badge" aria-live="polite">
						{ sprintf(
							/* translators: 1: selected modules, 2: all modules. */
							__( '%1$d of %2$d selected', 'ux-studio' ),
							selected.size,
							status.modules.length
						) }
					</span>
				</header>
				<div className="uxs-tr-toolbar">
					<label className="uxs-tr-search">
						<Search size={ 16 } aria-hidden />
						<input type="search" value={ search } onChange={ ( e ) => setSearch( e.target.value ) } placeholder={ __( 'Search modules', 'ux-studio' ) } aria-label={ __( 'Search modules', 'ux-studio' ) } />
					</label>
					<button type="button" className="button" onClick={ () => setSelected( new Set( [ ...selected, ...visible.map( ( m ) => m.id ) ] ) ) }>
						{ __( 'Select all', 'ux-studio' ) }
					</button>
					<button type="button" className="button" onClick={ () => setSelected( new Set() ) } disabled={ 0 === selected.size }>
						{ __( 'Clear selection', 'ux-studio' ) }
					</button>
				</div>
				{ 0 === groups.length && <p className="uxs-tr-muted">{ __( 'No module matches the search.', 'ux-studio' ) }</p> }
				{ groups.map( ( g ) => (
					<div key={ g.id } className="uxs-tr-group">
						<h3>{ g.label }</h3>
						<ul className="uxs-tr-list">
							{ g.items.map( ( m ) => (
								<li key={ m.id } id={ `uxs-tr-${ m.id }` } className={ selected.has( m.id ) ? 'is-selected' : '' }>
									<AnimatedCheckbox isSelected={ selected.has( m.id ) } onChange={ ( on ) => toggle( m.id, on ) }>
										<span className="uxs-tr-mod">
											<span className="uxs-tile__icon">
												<ModuleIcon name={ m.icon } size={ 18 } />
											</span>
											<span className="uxs-tr-mod__text">
												<strong>{ m.name }</strong>
												{ m.parent_name && <span className="uxs-tr-muted">{ m.parent_name }</span> }
											</span>
										</span>
									</AnimatedCheckbox>
									<span className="uxs-tr-badges">
										{ ! m.enabled && <span className="uxs-badge is-warning">{ __( 'Off', 'ux-studio' ) }</span> }
										{ m.data && m.data.count > 0 && (
											<span className="uxs-badge is-info">
												{ m.data.label }: { m.data.count }
											</span>
										) }
										{ m.secrets > 0 && (
											<span className="uxs-badge">
												<KeyRound size={ 11 } aria-hidden /> { m.secrets }
											</span>
										) }
									</span>
								</li>
							) ) }
						</ul>
					</div>
				) ) }
				<p className="uxs-tr-muted">
					<Info size={ 13 } aria-hidden /> { __( 'A module that is turned off can be exported too: its stored settings are used.', 'ux-studio' ) }
				</p>
			</section>

			<section className="uxs-tr-card" aria-labelledby="uxs-tr-options">
				<header className="uxs-tr-card__head">
					<h2 id="uxs-tr-options">{ __( 'What goes into the file', 'ux-studio' ) }</h2>
				</header>
				<div className="uxs-tr-opt">
					<AnimatedCheckbox isSelected={ includeEnabled } onChange={ setIncludeEnabled }>
						{ __( 'Modules turned on or off', 'ux-studio' ) }
					</AnimatedCheckbox>
					<p className="uxs-tr-muted">{ __( 'The import can switch modules on and off the same way (you decide there).', 'ux-studio' ) }</p>
				</div>
				<div className="uxs-tr-opt">
					<AnimatedCheckbox isSelected={ includeData } onChange={ setIncludeData }>
						{ __( 'Module content (definitions)', 'ux-studio' ) }
					</AnimatedCheckbox>
					<p className="uxs-tr-muted">
						{ __( 'Form definitions, redirect rules, code snippets, popups, locations with opening hours and notice categories. Never submissions, logs, statistics or personal data.', 'ux-studio' ) }
						{ chosen.length > 0 && (
							<>
								{ ' ' }
								{ withData.length > 0
									? sprintf(
											/* translators: %s: names of the chosen modules that have content. */
											__( 'In your selection: %s.', 'ux-studio' ),
											withData.map( ( m ) => `${ m.name } (${ m.data?.count ?? 0 })` ).join( ', ' )
									  )
									: __( 'None of the chosen modules has content to export.', 'ux-studio' ) }
							</>
						) }
					</p>
					{ blockedData.length > 0 && (
						<p className="uxs-tr-warn">
							<ShieldAlert size={ 14 } aria-hidden />{ ' ' }
							{ sprintf(
								/* translators: %s: names of modules. */
								__( 'Code snippets can only be exported by a user who may add scripts to the site (unfiltered_html): %s will be left out.', 'ux-studio' ),
								blockedData.map( ( m ) => m.name ).join( ', ' )
							) }
						</p>
					) }
				</div>
				<div className="uxs-tr-opt">
					<AnimatedCheckbox isSelected={ includeSecrets } onChange={ setIncludeSecrets } isDisabled={ ! status.sodium }>
						{ __( 'Secret values (API keys, passwords), encrypted with a passphrase', 'ux-studio' ) }
					</AnimatedCheckbox>
					<p className="uxs-tr-muted">
						{ __( 'Off by default. Without it the file never contains a secret, and lists the fields you will have to fill in again on the other site.', 'ux-studio' ) }
						{ chosen.length > 0 && ` ${ sprintf(
							/* translators: %d: number of secret values set in the chosen modules. */
							_n( '%d secret value is set in your selection.', '%d secret values are set in your selection.', secretsAvailable, 'ux-studio' ),
							secretsAvailable
						) }` }
					</p>
					{ ! status.sodium && (
						<p className="uxs-tr-warn">
							<ShieldAlert size={ 14 } aria-hidden />{ ' ' }
							{ __( 'This server has no libsodium (the PHP sodium extension), so secrets cannot be encrypted and this option is off. Ask your host to enable the extension.', 'ux-studio' ) }
						</p>
					) }
					{ includeSecrets && status.sodium && (
						<div className="uxs-tr-pass">
							<label>
								<span>{ __( 'Passphrase', 'ux-studio' ) }</span>
								<input type="password" autoComplete="new-password" value={ pass } onChange={ ( e ) => setPass( e.target.value ) } />
							</label>
							<label>
								<span>{ __( 'Passphrase again', 'ux-studio' ) }</span>
								<input type="password" autoComplete="new-password" value={ pass2 } onChange={ ( e ) => setPass2( e.target.value ) } />
							</label>
							<p className={ passProblem ? 'uxs-tr-warn' : 'uxs-tr-muted' }>
								{ passProblem || __( 'Remember it: the secrets cannot be opened without it, and nobody can recover it for you.', 'ux-studio' ) }
							</p>
						</div>
					) }
				</div>

				<button type="button" className="button-link uxs-tr-never" aria-expanded={ showNever } onClick={ () => setShowNever( ! showNever ) }>
					{ showNever ? __( 'Hide what is never exported', 'ux-studio' ) : __( 'Show what is never exported', 'ux-studio' ) }
				</button>
				{ showNever && (
					<div className="uxs-tr-never__body">
						<p>{ __( 'Connections and identity of this site, ids that mean something only in this database, state and logs never travel, whatever the options say:', 'ux-studio' ) }</p>
						<ul>
							<li>{ __( 'The Central app connection: key, address, HMAC secret, allowed addresses, report secret.', 'ux-studio' ) }</li>
							<li>{ __( 'Licence and update tokens, OAuth connections (Gmail, Instagram), CAPTCHA keys of a host name.', 'ux-studio' ) }</li>
							<li>{ __( 'Ids of pages, posts, users and terms (an image travels with its address and is only used if the other site has it in its media library).', 'ux-studio' ) }</li>
							<li>{ __( 'Wizard markers, pending states, snapshots, caches, logs, statistics and database versions.', 'ux-studio' ) }</li>
							<li>
								{ __( 'Per module:', 'ux-studio' ) }{ ' ' }
								{ Object.entries( status.non_portable.keys )
									.map( ( [ id, keys ] ) => `${ id }: ${ keys.join( ', ' ) }` )
									.join( ' · ' ) }
							</li>
						</ul>
					</div>
				) }
			</section>

			<section className="uxs-tr-card uxs-tr-final" aria-live="polite">
				<div className="uxs-tr-final__sum">
					{ 0 === selected.size && <span className="uxs-tr-muted">{ __( 'Choose at least one module.', 'ux-studio' ) }</span> }
					{ summary && selected.size > 0 && (
						<span>
							{ sprintf(
								/* translators: 1: modules, 2: settings, 3: content items, 4: secrets. */
								__( '%1$d modules, %2$d settings, %3$d content items, %4$d secrets.', 'ux-studio' ),
								summary.modules,
								summary.settings,
								summary.items,
								summary.secrets
							) }
							{ summary.manual > 0 && (
								<>
									{ ' ' }
									{ sprintf(
										/* translators: %d: number of fields. */
										_n( '%d field has to be filled in by hand on the other site.', '%d fields have to be filled in by hand on the other site.', summary.manual, 'ux-studio' ),
										summary.manual
									) }
								</>
							) }
						</span>
					) }
				</div>
				<div className="uxs-tr-final__actions">
					<button type="button" className="button button-primary" disabled={ ! canExport } onClick={ () => exportNow.mutate( 'download' ) }>
						{ exportNow.isPending && exportNow.variables === 'download' ? <LoaderCircle size={ 14 } className="uxs-tr-spin" aria-hidden /> : <Download size={ 14 } aria-hidden /> }
						{ __( 'Download the file', 'ux-studio' ) }
					</button>
					<button type="button" className="button" disabled={ ! canExport } onClick={ () => exportNow.mutate( 'copy' ) }>
						<ClipboardCopy size={ 14 } aria-hidden />
						{ __( 'Copy to clipboard', 'ux-studio' ) }
					</button>
				</div>
				{ exportNow.isError && (
					<p className="uxs-tr-error" role="alert">
						{ ( exportNow.error as Error ).message }
					</p>
				) }
				{ done && (
					<p className="uxs-tr-ok" role="status">
						<Check size={ 14 } aria-hidden />{ ' ' }
						{ done.copied
							? sprintf(
									/* translators: %s: file size. */
									__( 'Copied to the clipboard (%s).', 'ux-studio' ),
									size( done.size )
							  )
							: sprintf(
									/* translators: 1: file name, 2: size. */
									__( 'The file %1$s (%2$s) is being downloaded.', 'ux-studio' ),
									done.name,
									size( done.size )
							  ) }
					</p>
				) }
			</section>
		</div>
	);
}
