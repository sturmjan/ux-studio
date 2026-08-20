import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'tasks' | 'settings';

interface Task {
	id: string;
	text: string;
	done: boolean;
	created_at: string;
}

interface TasksPayload {
	tasks: Task[];
	notes: string;
}

interface PageSpeedResult {
	configured: boolean;
	success?: boolean;
	error?: string;
	score?: number;
	url?: string;
	metrics?: {
		first_contentful_paint: string | null;
		largest_contentful_paint: string | null;
	};
}

interface ActivityRow {
	created_at: string;
	module: string;
	action: string;
	object_type: string;
	object_id: number;
}

function scoreBadgeClass( score: number ): string {
	if ( score >= 90 ) {
		return 'is-success';
	}
	if ( score >= 50 ) {
		return 'is-warning';
	}
	return 'is-danger';
}

function PageSpeedPanel(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'dashboard-widgets', 'pagespeed' ],
		queryFn: () => api< PageSpeedResult >( 'dashboard-widgets/pagespeed' ),
	} );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data.configured ) {
		return (
			<p>
				<span className="uxs-badge is-warning">{ __( 'Not configured', 'ux-studio' ) }</span>{ ' ' }
				{ __( 'Add a Google PageSpeed API key in Settings to see a score here.', 'ux-studio' ) }
			</p>
		);
	}

	if ( ! data.success ) {
		return (
			<p>
				<span className="uxs-badge is-danger">{ __( 'Error', 'ux-studio' ) }</span> { data.error }
			</p>
		);
	}

	return (
		<div className="uxs-form">
			<p>
				<span className={ `uxs-badge ${ scoreBadgeClass( data.score ?? 0 ) }` }>
					{ __( 'Performance score:', 'ux-studio' ) } { data.score }
				</span>
			</p>
			{ data.metrics && (
				<p>
					{ data.metrics.first_contentful_paint && (
						<>
							{ __( 'First Contentful Paint:', 'ux-studio' ) } { data.metrics.first_contentful_paint }
							{ ' · ' }
						</>
					) }
					{ data.metrics.largest_contentful_paint && (
						<>
							{ __( 'Largest Contentful Paint:', 'ux-studio' ) } { data.metrics.largest_contentful_paint }
						</>
					) }
				</p>
			) }
		</div>
	);
}

function ActivityList(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'dashboard-widgets', 'activity' ],
		queryFn: () => api< ActivityRow[] >( 'dashboard-widgets/activity' ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No recent activity.', 'ux-studio' ) }</p>;
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Date', 'ux-studio' ) }</th>
					<th>{ __( 'Module', 'ux-studio' ) }</th>
					<th>{ __( 'Action', 'ux-studio' ) }</th>
					<th>{ __( 'Object', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( row, i ) => (
					<tr key={ i }>
						<td>{ row.created_at }</td>
						<td>{ row.module }</td>
						<td>{ row.action }</td>
						<td>{ row.object_type ? `${ row.object_type } #${ row.object_id }` : '' }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function TasksAndNotes(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'dashboard-widgets', 'tasks' ],
		queryFn: () => api< TasksPayload >( 'dashboard-widgets/tasks' ),
	} );

	const [ tasks, setTasks ] = useState< Task[] >( [] );
	const [ notes, setNotes ] = useState( '' );
	const [ newTask, setNewTask ] = useState( '' );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		if ( data ) {
			setTasks( data.tasks );
			setNotes( data.notes );
		}
	}, [ data ] );

	const save = useMutation( {
		mutationFn: () =>
			api< TasksPayload >( 'dashboard-widgets/tasks', {
				method: 'POST',
				body: JSON.stringify( { tasks, notes } ),
			} ),
		onSuccess: ( payload ) => {
			setTasks( payload.tasks );
			setNotes( payload.notes );
			void queryClient.invalidateQueries( { queryKey: [ 'dashboard-widgets', 'tasks' ] } );
			setSaved( true );
			window.setTimeout( () => setSaved( false ), 2000 );
		},
	} );

	const addTask = (): void => {
		const text = newTask.trim();
		if ( '' === text ) {
			return;
		}
		setTasks( ( t ) => [
			...t,
			{ id: '', text, done: false, created_at: '' },
		] );
		setNewTask( '' );
	};

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<div className="uxs-form">
			<div className="uxs-form__row">
				<label>{ __( 'Quick tasks', 'ux-studio' ) }</label>
				<ul className="uxs-checklist">
					{ tasks.map( ( task, i ) => (
						<li key={ task.id || i } className="uxs-checklist__item">
							<input
								type="checkbox"
								checked={ task.done }
								onChange={ () =>
									setTasks( ( t ) =>
										t.map( ( x, idx ) => ( idx === i ? { ...x, done: ! x.done } : x ) )
									)
								}
							/>
							<span style={ { textDecoration: task.done ? 'line-through' : 'none', flex: 1 } }>
								{ task.text }
							</span>
							<button
								type="button"
								className="button-link"
								aria-label={ __( 'Delete task', 'ux-studio' ) }
								onClick={ () => setTasks( ( t ) => t.filter( ( _, idx ) => idx !== i ) ) }
							>
								<Trash2 size={ 14 } />
							</button>
						</li>
					) ) }
				</ul>
				<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)' } }>
					<input
						type="text"
						value={ newTask }
						placeholder={ __( 'New task…', 'ux-studio' ) }
						onChange={ ( e ) => setNewTask( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								addTask();
							}
						} }
					/>
					<button type="button" className="button" onClick={ addTask }>
						<Plus size={ 14 } /> { __( 'Add', 'ux-studio' ) }
					</button>
				</div>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-dashboard-notes">{ __( 'Notes', 'ux-studio' ) }</label>
				<textarea
					id="uxs-dashboard-notes"
					rows={ 5 }
					value={ notes }
					onChange={ ( e ) => setNotes( e.target.value ) }
				/>
			</div>
			<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
				{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
			</button>
		</div>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'tasks' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'dashboard-widgets' );

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
					{ __( 'Dashboard Widgets', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'tasks' ? 'is-active' : '' } onClick={ () => setTab( 'tasks' ) }>
					{ __( 'Tasks', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'tasks' && (
				<>
					<h2>{ __( 'PageSpeed score', 'ux-studio' ) }</h2>
					<PageSpeedPanel />
					<h2>{ __( 'Recent activity', 'ux-studio' ) }</h2>
					<ActivityList />
					<h2>{ __( 'Tasks & notes', 'ux-studio' ) }</h2>
					<TasksAndNotes />
				</>
			) }
			{ tab === 'settings' && ( isLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
					<p>
						{ draft.has_pagespeed_key
							? __( 'A PageSpeed API key is currently set.', 'ux-studio' )
							: __( 'No PageSpeed API key set yet.', 'ux-studio' ) }
						{ ' · ' }
						{ draft.has_ga_key
							? __( 'A GA service-account JSON is currently set.', 'ux-studio' )
							: __( 'No GA service-account JSON set yet.', 'ux-studio' ) }
					</p>
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
		</>
	);
}
