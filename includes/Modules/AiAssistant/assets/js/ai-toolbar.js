/**
 * AI akce v toolbaru bloku nad označeným textem (Rozvinout / Přepsat /
 * Shrnout / Opravit gramatiku). Volá jediný endpoint
 * uxstudio/v1/ai-assistant/content/tool a označený úsek nahradí výsledkem.
 */
( function () {
	'use strict';

	var cfg = window.uxStudioAiToolbar || {};
	if ( ! cfg.restUrl || ! cfg.actions || ! cfg.actions.length ) {
		return;
	}
	if ( typeof wp === 'undefined' || ! wp.richText || ! wp.blockEditor || ! wp.element ) {
		return;
	}

	var registerFormatType = wp.richText.registerFormatType;
	var richTextCreate = wp.richText.create;
	var richTextInsert = wp.richText.insert;
	var BlockControls = wp.blockEditor.BlockControls;
	var ToolbarGroup = wp.components.ToolbarGroup;
	var ToolbarDropdownMenu = wp.components.ToolbarDropdownMenu;
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var i18n = cfg.i18n || {};

	if ( ! registerFormatType || ! ToolbarDropdownMenu ) {
		return;
	}

	function runTool( tool, sourceContent ) {
		return fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify( { tool: tool, source_content: sourceContent } ),
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || 'HTTP ' + res.status );
				}
				return data;
			} );
		} );
	}

	function notice( message, type ) {
		var store = wp.data.dispatch( 'core/notices' );
		if ( store ) {
			store.createNotice( type || 'error', message, { isDismissible: true, type: 'snackbar' } );
		}
	}

	function Edit( props ) {
		var value = props.value;
		var onChange = props.onChange;
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var hasSelection = value && typeof value.start === 'number' && value.start !== value.end;

		function apply( tool ) {
			if ( ! hasSelection ) {
				notice( i18n.noSelection || 'Označ text.', 'warning' );
				return;
			}
			// Pozice se zapamatují PŘED voláním: uživatel může mezitím kliknout
			// jinam a value ve chvíli odpovědi už míří jinam, než na co si řekl.
			var start = value.start;
			var end = value.end;
			var selected = value.text.slice( start, end );

			setBusy( true );
			runTool( tool, selected )
				.then( function ( payload ) {
					setBusy( false );
					var data = ( payload && payload.data ) || {};
					var text = data.text || '';
					if ( ! text ) {
						notice( i18n.emptyResult || 'AI nevrátila text.', 'warning' );
						return;
					}
					onChange( richTextInsert( value, richTextCreate( { text: text } ), start, end ) );
				} )
				.catch( function ( err ) {
					setBusy( false );
					notice( ( i18n.error || 'Chyba:' ) + ' ' + ( err.message || '' ), 'error' );
				} );
		}

		return el(
			BlockControls,
			{ group: 'other' },
			el(
				ToolbarGroup,
				null,
				el( ToolbarDropdownMenu, {
					icon: 'admin-customizer',
					label: busy ? ( i18n.working || 'Pracuji…' ) : ( i18n.menuLabel || 'AI nástroje' ),
					controls: cfg.actions.map( function ( action ) {
						return {
							title: action.label,
							isDisabled: busy || ! hasSelection,
							onClick: function () {
								apply( action.tool );
							},
						};
					} ),
				} )
			)
		);
	}

	wp.domReady( function () {
		registerFormatType( 'uxstudio/ai-toolbar', {
			// Formát se nikdy neaplikuje (nikde nevoláme toggleFormat) - jde
			// čistě o nosič tlačítka v toolbaru nad označeným textem.
			title: i18n.menuLabel || 'AI nástroje',
			tagName: 'span',
			className: 'uxstudio-ai-toolbar-noop',
			edit: Edit,
		} );
	} );
} )();
