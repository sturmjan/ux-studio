/**
 * SEO score - block editor sidebar. Debounced live analysis via
 * uxstudio/v1/ai-assistant/seo/score (SeoScorePanel -> SeoAiClient ->
 * centrani-app SeoAnalyzer).
 */
( function () {
	'use strict';

	var cfg = window.uxStudioSeoScore || {};
	if ( ! cfg.restUrl ) {
		return;
	}
	if ( typeof wp === 'undefined' || ! wp.plugins || ! wp.element || ! wp.data ) {
		return;
	}

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginSidebar = ( wp.editor && wp.editor.PluginSidebar ) || ( wp.editPost && wp.editPost.PluginSidebar );
	var PluginSidebarMoreMenuItem = ( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem );
	var PanelBody = wp.components.PanelBody;
	var PanelRow = wp.components.PanelRow;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Spinner = wp.components.Spinner;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var select = wp.data.select;
	var subscribe = wp.data.subscribe;
	var dispatch = wp.data.dispatch;
	var i18n = cfg.i18n || {};

	if ( ! PluginSidebar ) {
		return;
	}

	var GRADE_COLORS = { good: '#10b981', warn: '#f59e0b', fail: '#ef4444' };
	var STATUS_LABEL = { ok: '✓', warn: '!', fail: '✗' };

	function getMeta( key ) {
		var meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		return meta[ key ] || '';
	}

	function setMeta( fields ) {
		dispatch( 'core/editor' ).editPost( { meta: fields } );
	}

	function analyze( fields ) {
		return fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify( fields ),
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || i18n.error || 'Error' );
				}
				return data;
			} );
		} );
	}

	function ScoreBadge( props ) {
		var score = props.score;
		var grade = props.grade;
		var color = GRADE_COLORS[ grade ] || '#9ca3af';
		if ( score === null ) {
			return el( 'div', { className: 'uxstudio-seo-score__badge uxstudio-seo-score__badge--empty' }, '—' );
		}
		return el(
			'div',
			{ className: 'uxstudio-seo-score__badge', style: { borderColor: color, color: color } },
			score
		);
	}

	function CheckList( props ) {
		var checks = props.checks || [];
		if ( ! checks.length ) {
			return null;
		}
		return el(
			'ul',
			{ className: 'uxstudio-seo-score__checks' },
			checks.map( function ( c ) {
				var color = GRADE_COLORS[ c.status ] || '#9ca3af';
				return el(
					'li',
					{ key: c.id, className: 'uxstudio-seo-score__check' },
					el( 'span', { className: 'uxstudio-seo-score__check-icon', style: { color: color } }, STATUS_LABEL[ c.status ] || '?' ),
					el(
						'span',
						{ className: 'uxstudio-seo-score__check-text' },
						el( 'strong', null, c.label ),
						el( 'br' ),
						c.message
					)
				);
			} )
		);
	}

	function Panel() {
		var postId = select( 'core/editor' ).getCurrentPostId();
		var focusState = useState( getMeta( '_uxstudio_ai_seo_focus_keyword' ) );
		var scoreState = useState( null );
		var gradeState = useState( 'fail' );
		var checksState = useState( [] );
		var loadingState = useState( false );
		var errorState = useState( '' );

		var focusKeyword = focusState[ 0 ];
		var setFocusKeyword = focusState[ 1 ];
		var score = scoreState[ 0 ];
		var setScore = scoreState[ 1 ];
		var grade = gradeState[ 0 ];
		var setGrade = gradeState[ 1 ];
		var checks = checksState[ 0 ];
		var setChecks = checksState[ 1 ];
		var loading = loadingState[ 0 ];
		var setLoading = loadingState[ 1 ];
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		var timerRef = useRef( null );

		function runAnalyze() {
			var editor = select( 'core/editor' );
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};
			setLoading( true );
			setError( '' );
			analyze( {
				title: editor.getEditedPostAttribute( 'title' ) || '',
				content: editor.getEditedPostContent() || '',
				meta_title: meta._uxstudio_ai_seo_title || '',
				meta_desc: meta._uxstudio_ai_seo_description || '',
				focus_keyword: meta._uxstudio_ai_seo_focus_keyword || '',
				slug: editor.getEditedPostAttribute( 'slug' ) || '',
			} )
				.then( function ( data ) {
					setLoading( false );
					if ( ! data.success ) {
						setError( data.error || i18n.notConfigured || i18n.error );
						return;
					}
					setScore( data.score );
					setGrade( data.grade );
					setChecks( data.checks || [] );
					setMeta( {
						_uxstudio_ai_seo_score: data.score,
						_uxstudio_ai_seo_grade: data.grade,
					} );
				} )
				.catch( function ( err ) {
					setLoading( false );
					setError( err.message || i18n.error );
				} );
		}

		function schedule() {
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
			}
			timerRef.current = setTimeout( runAnalyze, 700 );
		}

		useEffect( function () {
			schedule();
			var unsubscribe = subscribe( function () {
				schedule();
			} );
			return function () {
				if ( timerRef.current ) {
					clearTimeout( timerRef.current );
				}
				unsubscribe();
			};
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [ postId ] );

		return el(
			Fragment,
			null,
			el(
				PluginSidebarMoreMenuItem,
				{ target: 'uxstudio-seo-score' },
				i18n.panelTitle || 'SEO skóre'
			),
			el(
				PluginSidebar,
				{ name: 'uxstudio-seo-score', title: i18n.panelTitle || 'SEO skóre', icon: 'chart-line' },
				el(
					PanelBody,
					null,
					el(
						'div',
						{ className: 'uxstudio-seo-score__header' },
						el( ScoreBadge, { score: score, grade: grade } ),
						loading ? el( Spinner, null ) : null
					),
					error ? el( 'p', { className: 'uxstudio-seo-score__error' }, error ) : null,
					el( PanelRow, null, el( TextControl, {
						label: i18n.focusKeyword || 'Klíčové slovo',
						value: focusKeyword,
						onChange: function ( next ) {
							setFocusKeyword( next );
							setMeta( { _uxstudio_ai_seo_focus_keyword: next } );
						},
					} ) ),
					el( PanelRow, null, el( TextControl, {
						label: i18n.metaTitle || 'SEO titulek',
						value: getMeta( '_uxstudio_ai_seo_title' ),
						onChange: function ( next ) {
							setMeta( { _uxstudio_ai_seo_title: next } );
						},
					} ) ),
					el( PanelRow, null, el( TextareaControl, {
						label: i18n.metaDesc || 'SEO popis',
						value: getMeta( '_uxstudio_ai_seo_description' ),
						onChange: function ( next ) {
							setMeta( { _uxstudio_ai_seo_description: next } );
						},
					} ) )
				),
				el( PanelBody, { title: 'Checklist', initialOpen: true }, el( CheckList, { checks: checks } ) )
			)
		);
	}

	wp.domReady( function () {
		registerPlugin( 'uxstudio-seo-score', { render: Panel, icon: 'chart-line' } );
	} );
} )();
