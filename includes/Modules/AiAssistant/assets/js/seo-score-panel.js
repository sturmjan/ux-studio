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

	function postJson( url, payload ) {
		return fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify( payload ),
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || i18n.error || 'Error' );
				}
				return data;
			} );
		} );
	}

	function analyze( fields ) {
		return postJson( cfg.restUrl, fields );
	}

	function topicResearch( seedKeyword ) {
		return postJson( cfg.restUrlTopics, { seed_keyword: seedKeyword } );
	}

	function fetchSchema( fields ) {
		return postJson( cfg.restUrlSchema, fields );
	}

	function fetchLinkSuggestions( content, excludeUrl ) {
		return postJson( cfg.restUrlLinks, { content: content, exclude_url: excludeUrl || '' } );
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

		var topicsState = useState( null );
		var topics = topicsState[ 0 ];
		var setTopics = topicsState[ 1 ];
		var topicsLoadingState = useState( false );
		var topicsLoading = topicsLoadingState[ 0 ];
		var setTopicsLoading = topicsLoadingState[ 1 ];
		var topicsErrorState = useState( '' );
		var topicsError = topicsErrorState[ 0 ];
		var setTopicsError = topicsErrorState[ 1 ];

		var schemaState = useState( null );
		var schema = schemaState[ 0 ];
		var setSchema = schemaState[ 1 ];
		var schemaLoadingState = useState( false );
		var schemaLoading = schemaLoadingState[ 0 ];
		var setSchemaLoading = schemaLoadingState[ 1 ];
		var schemaErrorState = useState( '' );
		var schemaError = schemaErrorState[ 0 ];
		var setSchemaError = schemaErrorState[ 1 ];
		var schemaCopiedState = useState( false );
		var schemaCopied = schemaCopiedState[ 0 ];
		var setSchemaCopied = schemaCopiedState[ 1 ];

		var linksState = useState( null );
		var links = linksState[ 0 ];
		var setLinks = linksState[ 1 ];
		var linksLoadingState = useState( false );
		var linksLoading = linksLoadingState[ 0 ];
		var setLinksLoading = linksLoadingState[ 1 ];
		var linksErrorState = useState( '' );
		var linksError = linksErrorState[ 0 ];
		var setLinksError = linksErrorState[ 1 ];

		var timerRef = useRef( null );
		var lastSignatureRef = useRef( null );

		function runSchema() {
			var editor = select( 'core/editor' );
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};
			setSchemaLoading( true );
			setSchemaError( '' );
			setSchemaCopied( false );
			fetchSchema( {
				title: editor.getEditedPostAttribute( 'title' ) || '',
				content: editor.getEditedPostContent() || '',
				meta_title: meta._uxstudio_ai_seo_title || '',
				meta_desc: meta._uxstudio_ai_seo_description || '',
				slug: editor.getEditedPostAttribute( 'slug' ) || '',
			} )
				.then( function ( data ) {
					setSchemaLoading( false );
					if ( ! data.success ) {
						setSchemaError( data.error || i18n.error );
						return;
					}
					setSchema( data.schema || [] );
				} )
				.catch( function ( err ) {
					setSchemaLoading( false );
					setSchemaError( err.message || i18n.error );
				} );
		}

		function copySchema() {
			var text = JSON.stringify( schema, null, 2 );
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( function () {
					setSchemaCopied( true );
				} );
			}
		}

		function runLinkSuggestions() {
			var editor = select( 'core/editor' );
			setLinksLoading( true );
			setLinksError( '' );
			var link = editor.getCurrentPost() ? editor.getCurrentPost().link : '';
			fetchLinkSuggestions( editor.getEditedPostContent() || '', link || '' )
				.then( function ( data ) {
					setLinksLoading( false );
					if ( ! data.success ) {
						setLinksError( data.error || i18n.error );
						return;
					}
					setLinks( data.suggestions || [] );
				} )
				.catch( function ( err ) {
					setLinksLoading( false );
					setLinksError( err.message || i18n.error );
				} );
		}

		function runTopicResearch() {
			if ( ! focusKeyword ) {
				return;
			}
			setTopicsLoading( true );
			setTopicsError( '' );
			topicResearch( focusKeyword )
				.then( function ( data ) {
					setTopicsLoading( false );
					if ( ! data.success ) {
						setTopicsError( data.error || i18n.error );
						return;
					}
					setTopics( data );
				} )
				.catch( function ( err ) {
					setTopicsLoading( false );
					setTopicsError( err.message || i18n.error );
				} );
		}

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

		// Otisk toho, z čeho se skóre počítá. `subscribe()` se spouští při
		// JAKÉKOLI změně storu (i cizí), takže bez téhle kontroly panel posílá
		// požadavky i když se obsah vůbec nezměnil - a rychle vyčerpá limit.
		function contentSignature() {
			var editor = select( 'core/editor' );
			if ( ! editor ) {
				return '';
			}
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};
			return [
				editor.getEditedPostAttribute( 'title' ) || '',
				editor.getEditedPostContent() || '',
				meta._uxstudio_ai_seo_title || '',
				meta._uxstudio_ai_seo_description || '',
				meta._uxstudio_ai_seo_focus_keyword || '',
				editor.getEditedPostAttribute( 'slug' ) || '',
			].join( ' ' );
		}

		function schedule( force ) {
			var signature = contentSignature();
			if ( ! force && signature === lastSignatureRef.current ) {
				return;
			}
			lastSignatureRef.current = signature;

			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
			}
			timerRef.current = setTimeout( runAnalyze, 700 );
		}

		useEffect( function () {
			schedule( true );
			var unsubscribe = subscribe( function () {
				schedule( false );
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
				el( PanelBody, { title: 'Checklist', initialOpen: true }, el( CheckList, { checks: checks } ) ),
				el(
					PanelBody,
					{ title: i18n.topicResearch || 'Návrh klíčových slov', initialOpen: false },
					el(
						'p',
						{ className: 'uxstudio-seo-score__hint' },
						i18n.topicResearchHint || 'AI odhad z kontextu, ne reálná data o vyhledávanosti.'
					),
					el(
						wp.components.Button,
						{
							variant: 'secondary',
							disabled: ! focusKeyword || topicsLoading,
							onClick: runTopicResearch,
						},
						topicsLoading ? ( i18n.researching || 'Hledám…' ) : ( i18n.topicResearch || 'Návrh klíčových slov' )
					),
					topicsError ? el( 'p', { className: 'uxstudio-seo-score__error' }, topicsError ) : null,
					topics ? el(
						'div',
						{ className: 'uxstudio-seo-score__topics' },
						el( 'strong', null, i18n.relatedKeywords || 'Související klíčová slova' ),
						el(
							'div',
							{ className: 'uxstudio-seo-score__chips' },
							( topics.related_keywords || [] ).map( function ( kw, i ) {
								return el( 'span', { key: i, className: 'uxstudio-seo-score__chip' }, kw );
							} )
						),
						el( 'strong', null, i18n.subtopics || 'Podtémata k pokrytí' ),
						el(
							'ul',
							{ className: 'uxstudio-seo-score__subtopics' },
							( topics.subtopics || [] ).map( function ( st, i ) {
								return el( 'li', { key: i }, st );
							} )
						)
					) : null
				),
				el(
					PanelBody,
					{ title: i18n.schema || 'Schema markup (JSON-LD)', initialOpen: false },
					el(
						wp.components.Button,
						{ variant: 'secondary', disabled: schemaLoading, onClick: runSchema },
						schemaLoading ? ( i18n.analyzing || 'Analyzuji…' ) : ( i18n.schemaGenerate || 'Vygenerovat' )
					),
					schemaError ? el( 'p', { className: 'uxstudio-seo-score__error' }, schemaError ) : null,
					schema ? el(
						'div',
						null,
						el(
							wp.components.Button,
							{ variant: 'link', onClick: copySchema },
							schemaCopied ? ( i18n.schemaCopied || 'Zkopírováno!' ) : ( i18n.schemaCopy || 'Zkopírovat' )
						),
						el( 'pre', { className: 'uxstudio-seo-score__schema' }, JSON.stringify( schema, null, 2 ) )
					) : null
				),
				el(
					PanelBody,
					{ title: i18n.linkSuggestions || 'Interní odkazy', initialOpen: false },
					el(
						wp.components.Button,
						{ variant: 'secondary', disabled: linksLoading, onClick: runLinkSuggestions },
						linksLoading ? ( i18n.analyzing || 'Analyzuji…' ) : ( i18n.linkSuggestionsFind || 'Najít návrhy' )
					),
					linksError ? el( 'p', { className: 'uxstudio-seo-score__error' }, linksError ) : null,
					links && 0 === links.length ? el( 'p', { className: 'uxstudio-seo-score__hint' }, i18n.linkSuggestionsEmpty || 'Žádné návrhy.' ) : null,
					links && links.length ? el(
						'ul',
						{ className: 'uxstudio-seo-score__links' },
						links.map( function ( link, i ) {
							return el(
								'li',
								{ key: i },
								el( 'a', { href: link.url, target: '_blank', rel: 'noreferrer' }, link.title )
							);
						} )
					) : null
				)
			)
		);
	}

	wp.domReady( function () {
		registerPlugin( 'uxstudio-seo-score', { render: Panel, icon: 'chart-line' } );
	} );
} )();
