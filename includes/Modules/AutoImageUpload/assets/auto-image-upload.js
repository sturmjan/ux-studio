/**
 * Auto Image Upload
 *
 * Detects images with external sources (other hosts, data: URIs) inserted into
 * the editor and automatically sideloads them to the media library, then
 * replaces their src with the local upload URL. Ported from the legacy module.
 */
( function () {
	'use strict';

	if ( typeof window.uxStudioAutoImageUpload === 'undefined' ) {
		return;
	}

	var cfg = window.uxStudioAutoImageUpload;
	var SITE_ORIGIN = cfg.siteOrigin || window.location.origin;

	// Cache of in-flight and resolved sideloads: originalUrl -> Promise<newUrl>
	var cache = {};
	var pending = 0;
	var batchFailed = false;
	var noticeTimer = null;

	function isExternal( src ) {
		if ( ! src ) {
			return false;
		}
		if ( src.indexOf( 'data:image/' ) === 0 ) {
			return true;
		}
		if ( src.indexOf( 'blob:' ) === 0 ) {
			return false;
		}
		if ( src.indexOf( 'file:' ) === 0 ) {
			return false;
		}
		if ( src.charAt( 0 ) === '/' ) {
			return false;
		}
		try {
			var u = new URL( src, SITE_ORIGIN );
			if ( u.protocol !== 'http:' && u.protocol !== 'https:' ) {
				return false;
			}
			return u.origin !== SITE_ORIGIN;
		} catch ( e ) {
			return false;
		}
	}

	function sideload( url ) {
		if ( cache[ url ] ) {
			return cache[ url ];
		}

		startPending();
		cache[ url ] = fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: JSON.stringify( { url: url } ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( body ) {
					var payload = body && body.data ? body.data : body;
					if ( ! res.ok || ! payload || ! payload.url ) {
						throw new Error( ( body && body.message ) || 'Upload failed' );
					}
					return payload.url;
				} );
			} )
			.catch( function ( err ) {
				batchFailed = true;
				delete cache[ url ];
				throw err;
			} )
			.finally( function () {
				endPending();
			} );

		return cache[ url ];
	}

	function startPending() {
		pending++;
		if ( pending === 1 ) {
			batchFailed = false;
			if ( cfg.showNotices ) {
				showEditorNotice( 'info', cfg.i18n.uploading, 'uxstudio-aiu-progress', false );
			}
		}
	}

	function endPending() {
		pending = Math.max( 0, pending - 1 );
		if ( pending === 0 && cfg.showNotices ) {
			clearTimeout( noticeTimer );
			noticeTimer = setTimeout( function () {
				removeEditorNotice( 'uxstudio-aiu-progress' );
				if ( batchFailed ) {
					showEditorNotice( 'error', cfg.i18n.failed, null, true );
				} else {
					showEditorNotice( 'success', cfg.i18n.uploaded, null, true );
				}
			}, 150 );
		}
	}

	function showEditorNotice( type, text, id, isDismissible ) {
		if ( ! window.wp || ! wp.data || ! wp.data.dispatch ) {
			return;
		}
		var store = wp.data.dispatch( 'core/notices' );
		if ( ! store ) {
			return;
		}
		store.createNotice( type, text, {
			id: id || undefined,
			isDismissible: isDismissible !== false,
			type: 'snackbar',
		} );
	}

	function removeEditorNotice( id ) {
		if ( ! id || ! window.wp || ! wp.data || ! wp.data.dispatch ) {
			return;
		}
		var store = wp.data.dispatch( 'core/notices' );
		if ( ! store || ! store.removeNotice ) {
			return;
		}
		store.removeNotice( id );
	}

	/* Gutenberg block editor */

	function processGutenberg() {
		if ( ! window.wp || ! wp.data || ! wp.data.select || ! wp.data.dispatch ) {
			return;
		}
		var be = wp.data.select( 'core/block-editor' );
		if ( ! be || ! be.getBlocks ) {
			return;
		}
		walkBlocks( be.getBlocks() );
	}

	function walkBlocks( blocks ) {
		for ( var i = 0; i < blocks.length; i++ ) {
			processBlock( blocks[ i ] );
			if ( blocks[ i ].innerBlocks && blocks[ i ].innerBlocks.length ) {
				walkBlocks( blocks[ i ].innerBlocks );
			}
		}
	}

	function processBlock( block ) {
		if ( ! block || ! block.name ) {
			return;
		}
		var attrs = block.attributes || {};

		if ( block.name === 'core/image' && isExternal( attrs.url ) ) {
			var originalUrl = attrs.url;
			sideload( originalUrl ).then( function ( newUrl ) {
				var latest = wp.data.select( 'core/block-editor' ).getBlock( block.clientId );
				if ( ! latest || latest.attributes.url !== originalUrl ) {
					return;
				}
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, {
					url: newUrl,
					id: undefined,
				} );
			} ).catch( function () {} );
		}

		var htmlFields = getHtmlFields( attrs );
		for ( var j = 0; j < htmlFields.length; j++ ) {
			var key = htmlFields[ j ];
			var html = attrs[ key ];
			if ( typeof html !== 'string' || html.indexOf( '<img' ) === -1 ) {
				continue;
			}
			replaceHtmlImages( block, key, html );
		}
	}

	function getHtmlFields( attrs ) {
		var candidates = [ 'content', 'values', 'citation', 'caption', 'text' ];
		var found = [];
		for ( var i = 0; i < candidates.length; i++ ) {
			if ( typeof attrs[ candidates[ i ] ] === 'string' ) {
				found.push( candidates[ i ] );
			}
		}
		return found;
	}

	function replaceHtmlImages( block, attrKey, html ) {
		var matches = [];
		var re = /<img\b[^>]*\bsrc=(["'])([^"']+)\1[^>]*>/gi;
		var m;
		while ( ( m = re.exec( html ) ) !== null ) {
			if ( isExternal( m[ 2 ] ) ) {
				matches.push( m[ 2 ] );
			}
		}
		if ( ! matches.length ) {
			return;
		}

		var unique = {};
		matches.forEach( function ( u ) {
			unique[ u ] = true;
		} );

		Object.keys( unique ).forEach( function ( src ) {
			sideload( src ).then( function ( newUrl ) {
				var latest = wp.data.select( 'core/block-editor' ).getBlock( block.clientId );
				if ( ! latest ) {
					return;
				}
				var current = latest.attributes[ attrKey ];
				if ( typeof current !== 'string' || current.indexOf( src ) === -1 ) {
					return;
				}
				var updated = current.split( src ).join( newUrl );
				var update = {};
				update[ attrKey ] = updated;
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, update );
			} ).catch( function () {} );
		} );
	}

	function subscribeGutenberg() {
		if ( ! window.wp || ! wp.data || ! wp.data.subscribe ) {
			return;
		}
		var scheduled = false;
		wp.data.subscribe( function () {
			if ( scheduled ) {
				return;
			}
			scheduled = true;
			setTimeout( function () {
				scheduled = false;
				processGutenberg();
			}, 250 );
		} );
	}

	/* Classic editor / TinyMCE */

	function hookTinyMce( editor ) {
		if ( ! editor || editor._uxStudioAiuHooked ) {
			return;
		}
		editor._uxStudioAiuHooked = true;

		editor.on( 'PastePostProcess', function ( ev ) {
			var node = ev.node;
			if ( ! node ) {
				return;
			}
			var imgs = node.querySelectorAll ? node.querySelectorAll( 'img' ) : [];
			for ( var i = 0; i < imgs.length; i++ ) {
				replaceTinyMceImage( editor, imgs[ i ] );
			}
		} );

		editor.on( 'Drop SetContent', function () {
			setTimeout( function () {
				var doc = editor.getDoc && editor.getDoc();
				if ( ! doc ) {
					return;
				}
				var imgs = doc.querySelectorAll( 'img' );
				for ( var i = 0; i < imgs.length; i++ ) {
					replaceTinyMceImage( editor, imgs[ i ] );
				}
			}, 50 );
		} );
	}

	function replaceTinyMceImage( editor, img ) {
		if ( ! img || img.getAttribute( 'data-uxstudio-aiu' ) === '1' ) {
			return;
		}
		var src = img.getAttribute( 'src' );
		if ( ! isExternal( src ) ) {
			return;
		}

		img.setAttribute( 'data-uxstudio-aiu', '1' );

		sideload( src ).then( function ( newUrl ) {
			img.setAttribute( 'src', newUrl );
			img.removeAttribute( 'srcset' );
			img.removeAttribute( 'data-uxstudio-aiu' );
			if ( editor.undoManager && editor.undoManager.add ) {
				editor.undoManager.add();
			}
		} ).catch( function () {
			img.removeAttribute( 'data-uxstudio-aiu' );
		} );
	}

	function subscribeTinyMce() {
		if ( typeof window.tinymce === 'undefined' ) {
			return;
		}

		if ( window.tinymce.activeEditor ) {
			hookTinyMce( window.tinymce.activeEditor );
		}
		if ( window.tinymce.editors ) {
			for ( var i = 0; i < window.tinymce.editors.length; i++ ) {
				hookTinyMce( window.tinymce.editors[ i ] );
			}
		}

		window.tinymce.on( 'AddEditor', function ( ev ) {
			hookTinyMce( ev.editor );
		} );
	}

	function boot() {
		subscribeGutenberg();
		subscribeTinyMce();

		if ( typeof window.tinymce === 'undefined' ) {
			var attempts = 0;
			var timer = setInterval( function () {
				attempts++;
				if ( typeof window.tinymce !== 'undefined' ) {
					clearInterval( timer );
					subscribeTinyMce();
				} else if ( attempts > 40 ) {
					clearInterval( timer );
				}
			}, 250 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
