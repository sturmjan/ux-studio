/**
 * Maps: list of maps + editor with a live Leaflet preview where pins are
 * added by clicking the map or searching an address, and moved by dragging.
 * Route: #/module?id=maps
 */
import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import L from 'leaflet';
import { ArrowLeft, Check, Copy, Crosshair, LoaderCircle, MapPin, MapPinPlus, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { pushToast } from '../../app/notify';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { TabsNav } from '../../app/SubNav';

type Provider = 'osm' | 'mapy' | 'mapbox' | 'google';

interface Marker {
	lat: number;
	lng: number;
	title: string;
	text: string;
	color: string;
	url: string;
}

interface MapConfig {
	provider: Provider | 'default';
	style: string;
	height: number;
	zoom: number;
	center: { lat: number; lng: number };
	fit_markers: boolean;
	scroll_zoom: boolean;
	route_link: boolean;
	click_to_load: boolean;
	markers: Marker[];
}

interface MapItem {
	id: number;
	title: string;
	config: MapConfig;
	legacy_id: number;
}

interface EditorConfig {
	providers: Record< Provider, string >;
	available: Record< Provider, boolean >;
	default_provider: Provider;
	styles: Record< Provider, string[] >;
	keys: Record< Provider, string >;
	defaults: MapConfig;
	leaflet_css: string;
}

interface GeocodeResult {
	lat: number;
	lng: number;
	label: string;
}

type Draft = { id: number; title: string; config: MapConfig };

const DEFAULT_COLOR = '#d63638';

function useEditorConfig() {
	return useQuery( { queryKey: [ 'maps', 'editor-config' ], queryFn: () => api< EditorConfig >( 'maps/editor-config' ) } );
}

function shortcode( id: number ): string {
	return `[uxstudio_map id="${ id }"]`;
}

function copyShortcode( id: number ): void {
	void navigator.clipboard.writeText( shortcode( id ) ).then( () => pushToast( 'success', __( 'Shortcode copied.', 'ux-studio' ) ) );
}

/** Leaflet CSS lives in assets/vendor (shared with the front end); load it once. */
function useLeafletCss( href: string | undefined ): void {
	useEffect( () => {
		if ( ! href || document.getElementById( 'uxs-leaflet-css' ) ) {
			return;
		}
		const link = document.createElement( 'link' );
		link.id = 'uxs-leaflet-css';
		link.rel = 'stylesheet';
		link.href = href;
		document.head.appendChild( link );
	}, [ href ] );
}

function pinIcon( color: string, active: boolean ): L.DivIcon {
	const size = active ? 1.2 : 1;
	return L.divIcon( {
		className: 'uxs-maps-pin',
		html: `<svg xmlns="http://www.w3.org/2000/svg" width="${ 30 * size }" height="${ 40 * size }" viewBox="0 0 30 40"><path d="M15 0C6.7 0 0 6.6 0 14.8 0 25.9 15 40 15 40s15-14.1 15-25.2C30 6.6 23.3 0 15 0z" fill="${ color }"/><circle cx="15" cy="14.5" r="5.5" fill="#fff"/></svg>`,
		iconSize: [ 30 * size, 40 * size ],
		iconAnchor: [ 15 * size, 40 * size ],
	} );
}

/** Tile layer for the editor preview. Google has no Leaflet tiles - OSM stands in. */
function previewTiles( provider: Provider, style: string, ec: EditorConfig ): L.TileLayer {
	if ( provider === 'mapy' && ec.keys.mapy ) {
		return L.tileLayer( `https://api.mapy.com/v1/maptiles/${ encodeURIComponent( style || 'basic' ) }/256/{z}/{x}/{y}?apikey=${ encodeURIComponent( ec.keys.mapy ) }`, {
			maxZoom: 19,
			attribution: '&copy; Seznam.cz a.s. a další',
		} );
	}
	if ( provider === 'mapbox' && ec.keys.mapbox ) {
		return L.tileLayer( `https://api.mapbox.com/styles/v1/${ style || 'mapbox/streets-v12' }/tiles/512/{z}/{x}/{y}@2x?access_token=${ encodeURIComponent( ec.keys.mapbox ) }`, {
			tileSize: 512,
			zoomOffset: -1,
			maxZoom: 20,
			attribution: '&copy; Mapbox &copy; OpenStreetMap',
		} );
	}
	return L.tileLayer( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' } );
}

/* ------------------------------------------------------------------ Preview */

interface PreviewProps {
	ec: EditorConfig;
	provider: Provider;
	style: string;
	config: MapConfig;
	active: number | null;
	addMode: boolean;
	onAdd: ( lat: number, lng: number ) => void;
	onMove: ( index: number, lat: number, lng: number ) => void;
	onSelect: ( index: number ) => void;
	onView: ( view: { lat: number; lng: number; zoom: number } ) => void;
	focus: { lat: number; lng: number; seq: number } | null;
}

function MapPreview( { ec, provider, style, config, active, addMode, onAdd, onMove, onSelect, onView, focus }: PreviewProps ): JSX.Element {
	const el = useRef< HTMLDivElement >( null );
	const map = useRef< L.Map | null >( null );
	const tiles = useRef< L.TileLayer | null >( null );
	const layer = useRef< L.LayerGroup | null >( null );
	// Latest callbacks for Leaflet handlers registered once.
	const handlers = useRef( { onAdd, onMove, onSelect, onView, addMode } );
	handlers.current = { onAdd, onMove, onSelect, onView, addMode };

	useEffect( () => {
		if ( ! el.current || map.current ) {
			return undefined;
		}
		const m = L.map( el.current, { scrollWheelZoom: true } );
		map.current = m;
		layer.current = L.layerGroup().addTo( m );
		const points = config.markers.map( ( p ) => L.latLng( p.lat, p.lng ) );
		if ( config.fit_markers && points.length > 1 ) {
			m.fitBounds( L.latLngBounds( points ), { padding: [ 40, 40 ], maxZoom: 16 } );
		} else if ( config.fit_markers && points[ 0 ] ) {
			m.setView( points[ 0 ], config.zoom );
		} else {
			m.setView( [ config.center.lat, config.center.lng ], config.zoom );
		}
		m.on( 'click', ( e: L.LeafletMouseEvent ) => {
			if ( handlers.current.addMode ) {
				handlers.current.onAdd( e.latlng.lat, e.latlng.lng );
			}
		} );
		m.on( 'moveend', () => {
			const c = m.getCenter();
			handlers.current.onView( { lat: c.lat, lng: c.lng, zoom: m.getZoom() } );
		} );
		// The tab may mount hidden/resized - recompute the size once laid out.
		window.setTimeout( () => m.invalidateSize(), 50 );
		return () => {
			m.remove();
			map.current = null;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- create once; updates below.
	}, [] );

	useEffect( () => {
		if ( ! map.current ) {
			return;
		}
		tiles.current?.remove();
		tiles.current = previewTiles( provider, style, ec ).addTo( map.current );
	}, [ provider, style, ec ] );

	useEffect( () => {
		const group = layer.current;
		if ( ! group ) {
			return;
		}
		group.clearLayers();
		config.markers.forEach( ( p, i ) => {
			const marker = L.marker( [ p.lat, p.lng ], { draggable: true, icon: pinIcon( p.color || DEFAULT_COLOR, i === active ), title: p.title, zIndexOffset: i === active ? 1000 : 0 } );
			marker.on( 'dragend', () => {
				const pos = marker.getLatLng();
				handlers.current.onMove( i, pos.lat, pos.lng );
			} );
			marker.on( 'click', () => handlers.current.onSelect( i ) );
			marker.addTo( group );
		} );
	}, [ config.markers, active ] );

	useEffect( () => {
		if ( focus && map.current ) {
			map.current.setView( [ focus.lat, focus.lng ], Math.max( map.current.getZoom(), 15 ) );
		}
	}, [ focus ] );

	useEffect( () => {
		if ( map.current ) {
			map.current.getContainer().style.cursor = addMode ? 'crosshair' : '';
		}
	}, [ addMode ] );

	return <div ref={ el } className="uxs-maps-preview" style={ { height: Math.min( config.height, 600 ) } } />;
}

/* ------------------------------------------------------------------- Editor */

function MapEditor( { initial, ec, onClose }: { initial: Draft; ec: EditorConfig; onClose: () => void } ): JSX.Element {
	const [ draft, setDraft ] = useState< Draft >( initial );
	const [ active, setActive ] = useState< number | null >( initial.config.markers.length ? 0 : null );
	const [ addMode, setAddMode ] = useState( initial.config.markers.length === 0 );
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState< GeocodeResult[] >( [] );
	const [ focus, setFocus ] = useState< { lat: number; lng: number; seq: number } | null >( null );
	const view = useRef( { lat: initial.config.center.lat, lng: initial.config.center.lng, zoom: initial.config.zoom } );

	const cfg = draft.config;
	const provider: Provider = cfg.provider === 'default' ? ec.default_provider : cfg.provider;
	const styles = ec.styles[ provider ] ?? [];
	const style = provider === cfg.provider && cfg.style ? cfg.style : styles[ 0 ] ?? '';

	const setConfig = ( patch: Partial< MapConfig > ) => setDraft( ( d ) => ( { ...d, config: { ...d.config, ...patch } } ) );
	const setMarker = ( i: number, patch: Partial< Marker > ) =>
		setConfig( { markers: cfg.markers.map( ( m, idx ) => ( idx === i ? { ...m, ...patch } : m ) ) } );
	const addMarker = ( lat: number, lng: number, title = '' ) => {
		setConfig( { markers: [ ...cfg.markers, { lat, lng, title, text: '', color: cfg.markers[ cfg.markers.length - 1 ]?.color ?? DEFAULT_COLOR, url: '' } ] } );
		setActive( cfg.markers.length );
		setAddMode( false );
	};
	const removeMarker = ( i: number ) => {
		setConfig( { markers: cfg.markers.filter( ( _, idx ) => idx !== i ) } );
		setActive( null );
	};

	const save = useMutation( {
		mutationFn: () =>
			api< MapItem >( draft.id ? `maps/${ draft.id }` : 'maps', {
				method: 'POST',
				body: JSON.stringify( { title: draft.title, config: draft.config } ),
			} ),
		onSuccess: ( saved ) => {
			setDraft( { id: saved.id, title: saved.title, config: saved.config } );
			void queryClient.invalidateQueries( { queryKey: [ 'maps', 'list' ] } );
			pushToast( 'success', __( 'Map saved.', 'ux-studio' ) );
		},
		onError: ( e ) => pushToast( 'error', e instanceof Error ? e.message : String( e ) ),
	} );

	const search = useMutation( {
		mutationFn: () => api< GeocodeResult[] >( `maps/geocode?q=${ encodeURIComponent( query ) }` ),
		onSuccess: ( found ) => {
			setResults( found );
			if ( ! found.length ) {
				pushToast( 'error', __( 'Nothing found for this address.', 'ux-studio' ) );
			}
		},
		onError: ( e ) => pushToast( 'error', e instanceof Error ? e.message : String( e ) ),
	} );

	return (
		<>
			<div className="uxs-maps-toolbar">
				<button type="button" className="button" onClick={ onClose }>
					<ArrowLeft size={ 14 } /> { __( 'All maps', 'ux-studio' ) }
				</button>
				<input
					type="text"
					className="uxs-maps-title"
					value={ draft.title }
					placeholder={ __( 'Map name', 'ux-studio' ) }
					aria-label={ __( 'Map name', 'ux-studio' ) }
					onChange={ ( e ) => setDraft( ( d ) => ( { ...d, title: e.target.value } ) ) }
				/>
				{ draft.id ? (
					<button type="button" className="button" onClick={ () => copyShortcode( draft.id ) }>
						<Copy size={ 14 } /> <code>{ shortcode( draft.id ) }</code>
					</button>
				) : null }
				<button type="button" className="button button-primary" disabled={ save.isPending } onClick={ () => save.mutate() }>
					{ save.isPending ? <LoaderCircle size={ 14 } /> : <Check size={ 14 } /> } { __( 'Save map', 'ux-studio' ) }
				</button>
			</div>

			<div className="uxs-maps-editor">
				<div className="uxs-maps-editor__map">
					<div className="uxs-maps-search">
						<input
							type="search"
							value={ query }
							placeholder={ __( 'Search an address…', 'ux-studio' ) }
							aria-label={ __( 'Search an address', 'ux-studio' ) }
							onChange={ ( e ) => setQuery( e.target.value ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' && query.trim() ) {
									e.preventDefault();
									search.mutate();
								}
							} }
						/>
						<button type="button" className="button" disabled={ ! query.trim() || search.isPending } onClick={ () => search.mutate() }>
							{ search.isPending ? <LoaderCircle size={ 14 } /> : <Search size={ 14 } /> } { __( 'Search', 'ux-studio' ) }
						</button>
						<button type="button" className={ addMode ? 'button button-primary' : 'button' } aria-pressed={ addMode } onClick={ () => setAddMode( ! addMode ) }>
							<MapPinPlus size={ 14 } /> { addMode ? __( 'Click the map…', 'ux-studio' ) : __( 'Add pin', 'ux-studio' ) }
						</button>
					</div>
					{ results.length ? (
						<ul className="uxs-maps-results">
							{ results.map( ( r, i ) => (
								<li key={ `${ r.lat }-${ r.lng }-${ i }` }>
									<span>{ r.label }</span>
									<button type="button" className="button button-small" onClick={ () => {
										// The typed query names the place better than the geocoder label (often starts with a house number).
										addMarker( r.lat, r.lng, query.trim() );
										setFocus( { lat: r.lat, lng: r.lng, seq: Date.now() } );
										setResults( [] );
									} }>
										<Plus size={ 12 } /> { __( 'Add as pin', 'ux-studio' ) }
									</button>
								</li>
							) ) }
						</ul>
					) : null }
					<MapPreview
						ec={ ec }
						provider={ provider }
						style={ style }
						config={ cfg }
						active={ active }
						addMode={ addMode }
						focus={ focus }
						onAdd={ ( lat, lng ) => addMarker( lat, lng ) }
						onMove={ ( i, lat, lng ) => setMarker( i, { lat: Number( lat.toFixed( 7 ) ), lng: Number( lng.toFixed( 7 ) ) } ) }
						onSelect={ ( i ) => setActive( i ) }
						onView={ ( v ) => { view.current = v; } }
					/>
					<p className="uxs-form__help">
						{ __( 'Drag a pin to move it. Click a pin to edit its text.', 'ux-studio' ) }
						{ provider === 'google' ? ' ' + __( 'The editor preview uses OpenStreetMap; on the website the map is drawn by Google Maps with the same pin positions.', 'ux-studio' ) : '' }
						{ ! ec.available[ provider ] ? ' ' + __( 'This provider has no key yet (Settings tab) - the website shows OpenStreetMap until you add one.', 'ux-studio' ) : '' }
					</p>
				</div>

				<div className="uxs-maps-editor__side">
					<h3>{ sprintf( /* translators: %d: number of pins */ _n( '%d pin', '%d pins', cfg.markers.length, 'ux-studio' ), cfg.markers.length ) }</h3>
					{ ! cfg.markers.length ? <p className="uxs-form__help">{ __( 'Add the first pin by searching an address or clicking the map.', 'ux-studio' ) }</p> : null }
					<ul className="uxs-maps-pins">
						{ cfg.markers.map( ( m, i ) => (
							<li key={ i } className={ i === active ? 'is-active' : '' }>
								<div className="uxs-maps-pins__head">
									<button type="button" className="button-link" onClick={ () => { setActive( i === active ? null : i ); setFocus( { lat: m.lat, lng: m.lng, seq: Date.now() } ); } }>
										<MapPin size={ 14 } color={ m.color } /> { m.title || sprintf( /* translators: %d: pin number */ __( 'Pin %d', 'ux-studio' ), i + 1 ) }
									</button>
									<button type="button" className="button-link" aria-label={ __( 'Delete pin', 'ux-studio' ) } onClick={ () => removeMarker( i ) } style={ { color: 'var(--uxs-danger)' } }>
										<Trash2 size={ 14 } />
									</button>
								</div>
								{ i === active ? (
									<div className="uxs-maps-pins__body">
										<label>
											{ __( 'Title', 'ux-studio' ) }
											<input type="text" value={ m.title } onChange={ ( e ) => setMarker( i, { title: e.target.value } ) } />
										</label>
										<label>
											{ __( 'Popup text', 'ux-studio' ) }
											<textarea rows={ 4 } value={ m.text } onChange={ ( e ) => setMarker( i, { text: e.target.value } ) } />
										</label>
										<label>
											{ __( 'Link (optional)', 'ux-studio' ) }
											<input type="url" value={ m.url } placeholder="https://" onChange={ ( e ) => setMarker( i, { url: e.target.value } ) } />
										</label>
										<div className="uxs-maps-pins__row">
											<label>
												{ __( 'Color', 'ux-studio' ) }
												<input type="color" value={ m.color || DEFAULT_COLOR } onChange={ ( e ) => setMarker( i, { color: e.target.value } ) } />
											</label>
											<label>
												{ __( 'Latitude', 'ux-studio' ) }
												<input type="number" step="0.000001" value={ m.lat } onChange={ ( e ) => setMarker( i, { lat: Number( e.target.value ) } ) } />
											</label>
											<label>
												{ __( 'Longitude', 'ux-studio' ) }
												<input type="number" step="0.000001" value={ m.lng } onChange={ ( e ) => setMarker( i, { lng: Number( e.target.value ) } ) } />
											</label>
										</div>
									</div>
								) : null }
							</li>
						) ) }
					</ul>

					<h3>{ __( 'Map', 'ux-studio' ) }</h3>
					<div className="uxs-maps-fields">
						<label>
							{ __( 'Provider', 'ux-studio' ) }
							<select value={ cfg.provider } onChange={ ( e ) => setConfig( { provider: e.target.value as MapConfig[ 'provider' ], style: '' } ) }>
								<option value="default">{ sprintf( /* translators: %s: provider name */ __( 'Default (%s)', 'ux-studio' ), ec.providers[ ec.default_provider ] ) }</option>
								{ ( Object.keys( ec.providers ) as Provider[] ).map( ( p ) => (
									<option key={ p } value={ p }>
										{ ec.providers[ p ] }{ ec.available[ p ] ? '' : ' - ' + __( 'no key', 'ux-studio' ) }
									</option>
								) ) }
							</select>
						</label>
						{ cfg.provider !== 'default' && provider === 'mapbox' ? (
							<label>
								{ __( 'Mapbox style', 'ux-studio' ) }
								<input type="text" list="uxs-maps-mapbox-styles" value={ style } onChange={ ( e ) => setConfig( { style: e.target.value } ) } />
								<datalist id="uxs-maps-mapbox-styles">
									{ styles.map( ( s ) => <option key={ s } value={ s } /> ) }
								</datalist>
							</label>
						) : null }
						{ cfg.provider !== 'default' && provider !== 'mapbox' && styles.length > 1 ? (
							<label>
								{ __( 'Map style', 'ux-studio' ) }
								<select value={ style } onChange={ ( e ) => setConfig( { style: e.target.value } ) }>
									{ styles.map( ( s ) => <option key={ s } value={ s }>{ s }</option> ) }
								</select>
							</label>
						) : null }
						<label>
							{ __( 'Height (px)', 'ux-studio' ) }
							<input type="number" min={ 150 } max={ 2000 } value={ cfg.height } onChange={ ( e ) => setConfig( { height: Number( e.target.value ) } ) } />
						</label>
						<label className="uxs-maps-check">
							<input type="checkbox" checked={ cfg.fit_markers } onChange={ ( e ) => setConfig( { fit_markers: e.target.checked } ) } />
							{ __( 'Fit the view to all pins', 'ux-studio' ) }
						</label>
						{ ! cfg.fit_markers ? (
							<div>
								<button type="button" className="button" onClick={ () => {
									setConfig( { center: { lat: Number( view.current.lat.toFixed( 7 ) ), lng: Number( view.current.lng.toFixed( 7 ) ) }, zoom: view.current.zoom } );
									pushToast( 'success', __( 'Current view will be used.', 'ux-studio' ) );
								} }>
									<Crosshair size={ 14 } /> { __( 'Use the current view of the preview', 'ux-studio' ) }
								</button>
								<p className="uxs-form__help">{ sprintf( /* translators: 1: latitude, 2: longitude, 3: zoom */ __( 'Center %1$s, %2$s, zoom %3$d', 'ux-studio' ), cfg.center.lat.toFixed( 5 ), cfg.center.lng.toFixed( 5 ), cfg.zoom ) }</p>
							</div>
						) : (
							<label>
								{ __( 'Zoom for a single pin', 'ux-studio' ) }
								<input type="number" min={ 1 } max={ 20 } value={ cfg.zoom } onChange={ ( e ) => setConfig( { zoom: Number( e.target.value ) } ) } />
							</label>
						) }
						<label className="uxs-maps-check">
							<input type="checkbox" checked={ cfg.scroll_zoom } onChange={ ( e ) => setConfig( { scroll_zoom: e.target.checked } ) } />
							{ __( 'Zoom with the mouse wheel', 'ux-studio' ) }
						</label>
						<label className="uxs-maps-check">
							<input type="checkbox" checked={ cfg.route_link } onChange={ ( e ) => setConfig( { route_link: e.target.checked } ) } />
							{ __( 'Show "Get directions" in the popup', 'ux-studio' ) }
						</label>
						<label className="uxs-maps-check">
							<input type="checkbox" checked={ cfg.click_to_load } onChange={ ( e ) => setConfig( { click_to_load: e.target.checked } ) } />
							{ __( 'Load the map only after a click (privacy, no request to the provider before)', 'ux-studio' ) }
						</label>
					</div>
				</div>
			</div>
		</>
	);
}

/* --------------------------------------------------------------------- List */

function MapsTab(): JSX.Element {
	const ec = useEditorConfig();
	useLeafletCss( ec.data?.leaflet_css );
	const [ editing, setEditing ] = useState< Draft | null >( null );
	const maps = useQuery( { queryKey: [ 'maps', 'list' ], queryFn: () => api< MapItem[] >( 'maps' ) } );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `maps/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'maps', 'list' ] } ),
	} );
	const fromLocations = useMutation( {
		mutationFn: () => api< Array< Omit< Marker, 'color' | 'url' > > >( 'maps/location-markers' ),
		onSuccess: ( markers ) => {
			if ( ! markers.length || ! ec.data ) {
				pushToast( 'error', __( 'No Opening Hours location has coordinates yet.', 'ux-studio' ) );
				return;
			}
			setEditing( {
				id: 0,
				title: __( 'Our branches', 'ux-studio' ),
				config: { ...ec.data.defaults, markers: markers.map( ( m ) => ( { ...m, color: DEFAULT_COLOR, url: '' } ) ) },
			} );
		},
	} );

	if ( ec.isLoading || maps.isLoading || ! ec.data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( editing ) {
		return <MapEditor key={ editing.id || 'new' } initial={ editing } ec={ ec.data } onClose={ () => setEditing( null ) } />;
	}

	const list = maps.data ?? [];
	const defaults = ec.data.defaults;

	return (
		<>
			<div className="uxs-maps-toolbar">
				<button type="button" className="button button-primary" onClick={ () => setEditing( { id: 0, title: '', config: defaults } ) }>
					<Plus size={ 14 } /> { __( 'New map', 'ux-studio' ) }
				</button>
				<button type="button" className="button" disabled={ fromLocations.isPending } onClick={ () => fromLocations.mutate() }>
					<MapPin size={ 14 } /> { __( 'New map from Opening Hours locations', 'ux-studio' ) }
				</button>
			</div>
			{ list.length ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'Shortcode', 'ux-studio' ) }</th>
							<th>{ __( 'Pins', 'ux-studio' ) }</th>
							<th>{ __( 'Provider', 'ux-studio' ) }</th>
							<th aria-label={ __( 'Actions', 'ux-studio' ) } />
						</tr>
					</thead>
					<tbody>
						{ list.map( ( m ) => (
							<tr key={ m.id }>
								<td>
									<button type="button" className="button-link" onClick={ () => setEditing( { id: m.id, title: m.title, config: m.config } ) }>
										{ m.title }
									</button>
									{ m.legacy_id ? <span className="uxs-badge" title={ `[mapster_wp_map id="${ m.legacy_id }"]` }>Mapster { m.legacy_id }</span> : null }
								</td>
								<td>
									<button type="button" className="button-link" onClick={ () => copyShortcode( m.id ) } title={ __( 'Copy', 'ux-studio' ) }>
										<code>{ shortcode( m.id ) }</code> <Copy size={ 12 } />
									</button>
								</td>
								<td>{ m.config.markers.length }</td>
								<td>{ m.config.provider === 'default' ? __( 'Default', 'ux-studio' ) : ec.data.providers[ m.config.provider ] }</td>
								<td style={ { textAlign: 'right', whiteSpace: 'nowrap' } }>
									<button type="button" className="button-link" aria-label={ __( 'Edit map', 'ux-studio' ) } onClick={ () => setEditing( { id: m.id, title: m.title, config: m.config } ) }>
										<Pencil size={ 16 } />
									</button>{ ' ' }
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Delete map', 'ux-studio' ) }
										style={ { color: 'var(--uxs-danger)' } }
										onClick={ () => {
											// eslint-disable-next-line no-alert -- simple confirm, same as other module lists.
											if ( window.confirm( __( 'Delete this map? Pages using its shortcode will show nothing.', 'ux-studio' ) ) ) {
												remove.mutate( m.id );
											}
										} }
									>
										<Trash2 size={ 16 } />
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : (
				<p>{ __( 'No maps yet. Create one and paste its shortcode into a page (Elementor: Shortcode widget).', 'ux-studio' ) }</p>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< 'maps' | 'settings' >( 'maps' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'maps' );

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
					{ __( 'Maps', 'ux-studio' ) }
				</h1>
				{ tab === 'settings' && (
					<button
						type="button"
						className="button button-primary"
						disabled={ save.isPending }
						onClick={ () =>
							save.mutate( undefined, {
								onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'maps', 'editor-config' ] } ),
							} )
						}
					>
						{ saved ? <Check size={ 14 } /> : null }
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				) }
			</header>
			<TabsNav>
				<button className={ tab === 'maps' ? 'is-active' : '' } onClick={ () => setTab( 'maps' ) }>
					{ __( 'Maps', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</TabsNav>
			{ tab === 'maps' && <MapsTab /> }
			{ tab === 'settings' &&
				( isLoading || ! data ? (
					<div className="uxs-loading">
						<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
					</div>
				) : (
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
				) ) }
		</>
	);
}
