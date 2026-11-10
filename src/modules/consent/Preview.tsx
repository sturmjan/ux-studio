/**
 * Live preview of the cookie banner next to the settings form (PLAN 42.18).
 *
 * It shows the REAL banner: the same consent.min.js and consent.min.css as on the site, started by a config block that
 * the server builds from the unsaved values (POST consent/preview-config runs Module::frontend_config() with them), so
 * the mapping of a setting to the banner is never written twice. The page is a sandboxed iframe WITHOUT allow-same-origin,
 * so the banner script runs in an opaque origin: it cannot touch the cookies or storage of the admin, call the REST API with
 * its session or reload it. The server leaves out consent records, the region check and the cookie list, and the script
 * itself ignores every decision in preview mode (cfg.preview).
 *
 * No inline script is used (only the JSON config block, the two files and one inline style), so a content security policy
 * that forbids inline scripts would not break it either.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { api } from '../../app/api';
import type { Value } from '../../app/SettingsForm';

export type PreviewView = 'main' | 'detail' | 'fab' | 'ph';
export type PreviewWidth = 'desktop' | 'mobile';

interface PreviewData {
	config: Record< string, unknown >;
	css: string;
	assets: { js: string; css: string; js_ver: string; css_ver: string; tcss: string; tcss_ver: string };
}

/** The size of the page inside the iframe; the iframe is scaled down to the width of the panel. */
const SIZE: Record< PreviewWidth, { w: number; h: number } > = {
	desktop: { w: 900, h: 560 },
	mobile: { w: 390, h: 640 },
};

function useDebounced< T >( value: T, ms: number ): T {
	const [ out, setOut ] = useState( value );
	useEffect( () => {
		const t = window.setTimeout( () => setOut( value ), ms );
		return () => window.clearTimeout( t );
	}, [ value, ms ] );
	return out;
}

/** Text for an HTML attribute or element. */
function esc( s: string ): string {
	return s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
}

/** The document of the iframe. Exported for the browser test; it only builds a string. */
export function previewDocument( data: PreviewData, view: PreviewView ): string {
	// Data in a script element must not be able to close it ("<") or break the line (U+2028/2029); the JSON stays valid.
	const config = JSON.stringify( data.config )
		.replace( /</g, '\\u003c' )
		.replace( new RegExp( String.fromCharCode( 0x2028 ), 'g' ), '\\u2028' )
		.replace( new RegExp( String.fromCharCode( 0x2029 ), 'g' ), '\\u2029' );
	const vars = data.css.replace( /[<>]/g, '' );
	// A grey sketch of a page behind the banner; the video view gets a held-back embed like the ones the site rewrites.
	const embed =
		view === 'ph'
			? '<iframe data-uxc="marketing" data-uxc-src="about:blank" data-uxc-service="YouTube" width="560" height="315"></iframe>'
			: '<div class="pg-line"></div>';
	return (
		'<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
		'<base target="_blank">' +
		`<link rel="stylesheet" href="${ esc( `${ data.assets.css }?ver=${ data.assets.css_ver }` ) }">` +
		// The style of the chosen template, on top of the base one (none for the classic template).
		( data.assets.tcss ? `<link rel="stylesheet" href="${ esc( `${ data.assets.tcss }?ver=${ data.assets.tcss_ver }` ) }">` : '' ) +
		'<style>html,body{margin:0;min-height:100%;background:#fff;font:16px/1.5 system-ui,sans-serif}' +
		'.pg{padding:24px;max-width:760px;margin:0 auto}.pg-bar{height:44px;background:#e8eaed}' +
		'.pg-h{height:28px;width:55%;background:#d5d9de;margin:8px 0 18px}.pg-line{height:12px;background:#eceef1;margin:10px 0}' +
		'.pg-line:nth-child(3n){width:80%}.uxc-ph{margin:10px 0}' +
		`${ vars }</style></head><body>` +
		'<div class="pg-bar"></div><div class="pg"><div class="pg-h"></div>' +
		'<div class="pg-line"></div><div class="pg-line"></div><div class="pg-line"></div>' +
		embed +
		'<div class="pg-line"></div><div class="pg-line"></div><div class="pg-line"></div><div class="pg-line"></div></div>' +
		`<script type="application/json" id="uxc-config">${ config }</script>` +
		`<script src="${ esc( `${ data.assets.js }?ver=${ data.assets.js_ver }` ) }"></script>` +
		'</body></html>'
	);
}

export function BannerPreview( {
	values,
	view,
	setView,
	width,
	setWidth,
}: {
	values: Record< string, Value >;
	view: PreviewView;
	setView: ( v: PreviewView ) => void;
	width: PreviewWidth;
	setWidth: ( w: PreviewWidth ) => void;
} ): JSX.Element {
	const body = useDebounced( JSON.stringify( { values, view } ), 350 );
	const query = useQuery( {
		queryKey: [ 'consent-preview', body ],
		queryFn: () => api< PreviewData >( 'consent/preview-config', { method: 'POST', body } ),
		placeholderData: keepPreviousData,
		staleTime: 0,
		gcTime: 30_000,
		retry: false,
	} );
	const html = useMemo( () => ( query.data ? previewDocument( query.data, view ) : '' ), [ query.data, view ] );

	// The page inside the iframe has a fixed logical width (a real desktop or phone), scaled to what the panel allows.
	const box = useRef< HTMLDivElement >( null );
	const [ avail, setAvail ] = useState( 0 );
	useEffect( () => {
		const node = box.current;
		if ( ! node ) {
			return;
		}
		const measure = () => setAvail( node.clientWidth );
		measure();
		if ( typeof ResizeObserver === 'undefined' ) {
			return;
		}
		const ro = new ResizeObserver( measure );
		ro.observe( node );
		return () => ro.disconnect();
	}, [] );
	const size = SIZE[ width ];
	const scale = avail > 0 ? Math.min( 1, avail / size.w ) : 1;

	const views: { id: PreviewView; label: string }[] = [
		{ id: 'main', label: __( 'First layer', 'ux-studio' ) },
		{ id: 'detail', label: __( 'Category settings', 'ux-studio' ) },
		{ id: 'fab', label: __( 'Floating button', 'ux-studio' ) },
		{ id: 'ph', label: __( 'Embedded video', 'ux-studio' ) },
	];
	const widths: { id: PreviewWidth; label: string }[] = [
		{ id: 'desktop', label: __( 'Desktop', 'ux-studio' ) },
		{ id: 'mobile', label: __( 'Mobile (390 px)', 'ux-studio' ) },
	];
	const fabOff = view === 'fab' && query.data !== undefined && query.data.config.fab === false;

	return (
		<section className="uxs-uxcp" aria-labelledby="uxs-uxcp-title">
			<h3 id="uxs-uxcp-title" className="uxs-uxcp__title">
				{ __( 'Preview', 'ux-studio' ) }
			</h3>
			<div className="uxs-uxcp__bar">
				<div className="uxs-uxcp__group" role="group" aria-label={ __( 'What to show', 'ux-studio' ) }>
					{ views.map( ( v ) => (
						<button key={ v.id } type="button" className={ view === v.id ? 'is-active' : '' } aria-pressed={ view === v.id } onClick={ () => setView( v.id ) }>
							{ v.label }
						</button>
					) ) }
				</div>
				<div className="uxs-uxcp__group" role="group" aria-label={ __( 'Screen width', 'ux-studio' ) }>
					{ widths.map( ( w ) => (
						<button key={ w.id } type="button" className={ width === w.id ? 'is-active' : '' } aria-pressed={ width === w.id } onClick={ () => setWidth( w.id ) }>
							{ w.label }
						</button>
					) ) }
				</div>
			</div>
			<div className="uxs-uxcp__stage" ref={ box } style={ { height: Math.round( size.h * scale ) } }>
				{ html !== '' && (
					<iframe
						title={ __( 'Preview of the cookie banner', 'ux-studio' ) }
						sandbox="allow-scripts"
						referrerPolicy="no-referrer"
						srcDoc={ html }
						style={ { width: size.w, height: size.h, transform: `scale(${ scale })` } }
					/>
				) }
				{ query.isError && (
					<p className="uxs-uxcp__note" role="alert">
						{ __( 'The preview could not be loaded.', 'ux-studio' ) }
					</p>
				) }
			</div>
			{ fabOff && (
				<p className="uxs-form__help" role="status">
					{ __( 'The small button to change the choice later is switched off in the Display settings, so visitors do not see it.', 'ux-studio' ) }
				</p>
			) }
			<p className="uxs-form__help">
				{ __( 'This is the real banner with the values in the form, also the ones you have not saved yet. Nothing is stored or sent from the preview, and its buttons do nothing.', 'ux-studio' ) }
			</p>
		</section>
	);
}
