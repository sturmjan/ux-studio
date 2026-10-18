/**
 * Folder Manager - folder tree for the Media Library (list, grid, media modal)
 * and for the post / page / CPT lists, folder filter, drag & drop move,
 * "Move to folder" bulk action and "upload into the selected folder".
 * Plain JS (no build step); data comes from the global `uxsFolders`.
 */
( function () {
	'use strict';

	var cfg = window.uxsFolders;
	if ( ! cfg ) {
		return;
	}

	var i18n = cfg.i18n || {};
	var MIME = 'application/x-uxs-items';

	var state = {
		folders: cfg.folders || [],
		uncategorized: cfg.uncategorized || 0,
		total: cfg.total || 0,
		collapsed: {},
	};
	// Folder selected last in any panel (null = all). Used for uploads.
	var activeFolder = typeof cfg.current === 'number' ? cfg.current : null;
	var panels = [];
	var browsers = [];

	// ---------------------------------------------------------------- helpers

	function h( tag, attrs, children ) {
		var el = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				el.textContent = attrs[ key ];
			} else if ( key === 'class' ) {
				el.className = attrs[ key ];
			} else {
				el.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				el.appendChild( child );
			}
		} );
		return el;
	}

	function icon( name ) {
		return h( 'span', { class: 'dashicons dashicons-' + name, 'aria-hidden': 'true' } );
	}

	function api( path, method, body ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					throw new Error( ( json && json.message ) || i18n.error );
				}
				return json && json.data !== undefined ? json.data : json;
			} );
		} );
	}

	function childrenOf( parent ) {
		return state.folders.filter( function ( folder ) {
			return folder.parent === parent;
		} );
	}

	/** Flat, depth-first list of { folder, depth } for select options. */
	function flatten( parent, depth, out ) {
		childrenOf( parent ).forEach( function ( folder ) {
			out.push( { folder: folder, depth: depth } );
			if ( depth < 20 ) {
				flatten( folder.id, depth + 1, out );
			}
		} );
		return out;
	}

	function fail( error ) {
		window.alert( error && error.message ? error.message : i18n.error );
	}

	// ----------------------------------------------------------------- data

	function reloadAll() {
		return api( '/overview?post_type=' + encodeURIComponent( cfg.postType ) ).then( function ( data ) {
			state.folders = data.folders || [];
			state.uncategorized = data.uncategorized || 0;
			state.total = data.total || 0;
			panels.forEach( function ( panel ) {
				panel.render();
			} );
			refreshBulkSelects();
		} );
	}

	function refreshMedia() {
		browsers.forEach( function ( browser ) {
			if ( browser.collection && browser.collection.props ) {
				browser.collection.props.set( { ignore: +new Date() } );
			}
		} );
	}

	function moveItems( ids, folderId ) {
		if ( ! ids.length ) {
			return Promise.resolve();
		}
		return api( '/items/move', 'POST', { attachment_ids: ids, folder_id: folderId } )
			.then( function () {
				if ( cfg.mode === 'list' ) {
					window.location.reload();
					return null;
				}
				return reloadAll().then( refreshMedia );
			} )
			.catch( fail );
	}

	function createFolder( parent ) {
		var name = window.prompt( i18n.folderName, '' );
		if ( ! name || ! name.trim() ) {
			return;
		}
		api( '/folders', 'POST', { name: name.trim(), parent: parent, post_type: cfg.postType } )
			.then( reloadAll )
			.catch( fail );
	}

	function renameFolder( folder ) {
		var name = window.prompt( i18n.folderName, folder.name );
		if ( ! name || ! name.trim() || name.trim() === folder.name ) {
			return;
		}
		api( '/folders/' + folder.id, 'PUT', { name: name.trim() } ).then( reloadAll ).catch( fail );
	}

	function deleteFolder( folder, panel ) {
		if ( ! window.confirm( i18n.confirmDelete ) ) {
			return;
		}
		api( '/folders/' + folder.id, 'DELETE' )
			.then( function () {
				if ( panel.selected === folder.id ) {
					panel.select( null );
				}
				return reloadAll();
			} )
			.catch( fail );
	}

	// ---------------------------------------------------------------- panel

	/**
	 * @param {Object}   opts          Options.
	 * @param {Function} opts.onSelect Called with null | 0 | folder id.
	 * @param {?number}  opts.selected Initially selected folder.
	 */
	function Panel( opts ) {
		var self = this;
		this.selected = opts.selected === undefined ? null : opts.selected;
		this.onSelect = opts.onSelect;
		this.tree = h( 'div', { class: 'uxs-fm-tree', role: 'tree', 'aria-label': i18n.folders } );
		this.el = h( 'div', { class: 'uxs-fm-panel' }, [
			h( 'div', { class: 'uxs-fm-head' }, [
				h( 'h3', { text: i18n.folders } ),
				h( 'span', { class: 'uxs-fm-head-actions' }, [ this.button( 'plus-alt', i18n.newFolder, function () {
					createFolder( 0 );
				} ), this.button( 'update', i18n.refresh, function () {
					reloadAll().catch( fail );
				} ) ] ),
			] ),
			this.tree,
			cfg.canManage && cfg.manageUrl
				? h( 'a', { class: 'uxs-fm-manage', href: cfg.manageUrl, text: i18n.manage } )
				: null,
		] );

		this.el.addEventListener( 'dragover', function ( event ) {
			var item = event.target.closest ? event.target.closest( '.uxs-fm-item[data-drop]' ) : null;
			if ( item && event.dataTransfer && Array.prototype.indexOf.call( event.dataTransfer.types, MIME ) !== -1 ) {
				event.preventDefault();
				event.dataTransfer.dropEffect = 'move';
				item.classList.add( 'is-drop' );
			}
		} );
		this.el.addEventListener( 'dragleave', function ( event ) {
			var item = event.target.closest ? event.target.closest( '.uxs-fm-item' ) : null;
			if ( item ) {
				item.classList.remove( 'is-drop' );
			}
		} );
		this.el.addEventListener( 'drop', function ( event ) {
			var item = event.target.closest ? event.target.closest( '.uxs-fm-item[data-drop]' ) : null;
			if ( ! item || ! event.dataTransfer ) {
				return;
			}
			var raw = event.dataTransfer.getData( MIME );
			item.classList.remove( 'is-drop' );
			if ( ! raw ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			try {
				moveItems( JSON.parse( raw ), parseInt( item.getAttribute( 'data-drop' ), 10 ) || 0 );
			} catch ( e ) {
				fail( e );
			}
		} );

		panels.push( this );
		this.render();
		self.el.addEventListener( 'dragend', function () {
			Array.prototype.forEach.call( self.el.querySelectorAll( '.is-drop' ), function ( node ) {
				node.classList.remove( 'is-drop' );
			} );
		} );
	}

	Panel.prototype.button = function ( dashicon, label, handler ) {
		var btn = h( 'button', { type: 'button', class: 'uxs-fm-btn', 'aria-label': label, title: label }, [ icon( dashicon ) ] );
		btn.addEventListener( 'click', handler );
		return btn;
	};

	Panel.prototype.select = function ( id ) {
		this.selected = id;
		activeFolder = id;
		this.render();
		if ( this.onSelect ) {
			this.onSelect( id );
		}
	};

	Panel.prototype.row = function ( folder, depth ) {
		var self = this;
		var kids = folder ? childrenOf( folder.id ) : [];
		var id = folder ? folder.id : null;
		var active = this.selected === id;
		var label = folder ? folder.name : null;
		var count = folder ? folder.count : null;
		var item = h( 'div', {
			class: 'uxs-fm-item' + ( active ? ' is-active' : '' ),
			role: 'treeitem',
			tabindex: '0',
			'aria-selected': active ? 'true' : 'false',
			style: 'padding-left:' + ( 6 + depth * 14 ) + 'px',
		} );

		if ( kids.length ) {
			var collapsed = !! state.collapsed[ folder.id ];
			var toggle = h( 'button', {
				type: 'button',
				class: 'uxs-fm-toggle',
				'aria-label': collapsed ? i18n.expand : i18n.collapse,
				'aria-expanded': collapsed ? 'false' : 'true',
			}, [ icon( collapsed ? 'arrow-right' : 'arrow-down' ) ] );
			toggle.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
				state.collapsed[ folder.id ] = ! collapsed;
				self.render();
			} );
			item.appendChild( toggle );
		} else {
			item.appendChild( h( 'span', { class: 'uxs-fm-toggle-space' } ) );
		}

		item.appendChild( icon( folder ? 'portfolio' : 'category' ) );
		item.appendChild( h( 'span', { class: 'uxs-fm-name', text: label } ) );
		if ( cfg.showCounts && count !== null ) {
			item.appendChild( h( 'span', { class: 'uxs-fm-count', text: String( count ) } ) );
		}

		if ( folder ) {
			item.setAttribute( 'data-drop', String( folder.id ) );
			if ( cfg.canManage ) {
				item.appendChild( h( 'span', { class: 'uxs-fm-actions' }, [
					this.button( 'plus', i18n.addChildFolder, function ( event ) {
						event.stopPropagation();
						createFolder( folder.id );
					} ),
					this.button( 'edit', i18n.renameFolder, function ( event ) {
						event.stopPropagation();
						renameFolder( folder );
					} ),
					this.button( 'trash', i18n.deleteFolder, function ( event ) {
						event.stopPropagation();
						deleteFolder( folder, self );
					} ),
				] ) );
			}
		}

		item.addEventListener( 'click', function () {
			self.select( id );
		} );
		item.addEventListener( 'keydown', function ( event ) {
			if ( ( event.key === 'Enter' || event.key === ' ' ) && event.target === item ) {
				event.preventDefault();
				self.select( id );
			}
		} );

		return item;
	};

	Panel.prototype.render = function () {
		var self = this;
		this.tree.textContent = '';

		this.tree.appendChild( this.rowFor( 'all' ) );
		this.tree.appendChild( this.rowFor( 'none' ) );

		var walk = function ( parent, depth ) {
			childrenOf( parent ).forEach( function ( folder ) {
				self.tree.appendChild( self.row( folder, depth ) );
				if ( ! state.collapsed[ folder.id ] ) {
					walk( folder.id, depth + 1 );
				}
			} );
		};
		walk( 0, 0 );
	};

	/** "All" and "Uncategorized" pseudo folders. */
	Panel.prototype.rowFor = function ( kind ) {
		var self = this;
		var id = kind === 'all' ? null : 0;
		var active = this.selected === id;
		var item = h( 'div', {
			class: 'uxs-fm-item' + ( active ? ' is-active' : '' ),
			role: 'treeitem',
			tabindex: '0',
			'aria-selected': active ? 'true' : 'false',
			style: 'padding-left:6px',
		}, [
			h( 'span', { class: 'uxs-fm-toggle-space' } ),
			icon( kind === 'all' ? 'category' : 'archive' ),
			h( 'span', { class: 'uxs-fm-name', text: kind === 'all' ? i18n.allFolders : i18n.uncategorized } ),
			cfg.showCounts ? h( 'span', { class: 'uxs-fm-count', text: String( kind === 'all' ? state.total : state.uncategorized ) } ) : null,
		] );
		if ( kind === 'none' ) {
			item.setAttribute( 'data-drop', '0' );
		}
		item.addEventListener( 'click', function () {
			self.select( id );
		} );
		item.addEventListener( 'keydown', function ( event ) {
			if ( ( event.key === 'Enter' || event.key === ' ' ) && event.target === item ) {
				event.preventDefault();
				self.select( id );
			}
		} );
		return item;
	};

	// ------------------------------------------------------ list pages (PHP)

	function listUrl( id ) {
		var url = new URL( window.location.href );
		url.searchParams.delete( 'paged' );
		if ( id === null ) {
			url.searchParams.delete( cfg.filterVar );
		} else {
			url.searchParams.set( cfg.filterVar, String( id ) );
		}
		return url.toString();
	}

	function selectedListIds( row ) {
		var ids = [];
		var table = document.querySelector( '.wp-list-table' );
		if ( table ) {
			Array.prototype.forEach.call( table.querySelectorAll( 'tbody th.check-column input[type="checkbox"]:checked' ), function ( box ) {
				var value = parseInt( box.value, 10 );
				if ( value > 0 ) {
					ids.push( value );
				}
			} );
		}
		var own = parseInt( ( row.id || '' ).replace( /^post-/, '' ), 10 );
		if ( own > 0 && ids.indexOf( own ) === -1 ) {
			ids = [ own ];
		}
		return ids;
	}

	function initListPage() {
		var table = document.querySelector( '.wp-list-table' );
		var wrap = document.querySelector( '#wpbody-content > .wrap' );
		if ( ! table || ! wrap || ! wrap.parentNode ) {
			return false;
		}

		cfg.mode = 'list';
		activeFolder = typeof cfg.current === 'number' ? cfg.current : null;
		var panel = new Panel( {
			selected: activeFolder,
			onSelect: function ( id ) {
				window.location.href = listUrl( id );
			},
		} );

		var layout = h( 'div', { class: 'uxs-fm-layout' } );
		var main = h( 'div', { class: 'uxs-fm-main' } );
		wrap.parentNode.insertBefore( layout, wrap );
		layout.appendChild( panel.el );
		layout.appendChild( main );
		main.appendChild( wrap );

		// Drag rows onto a folder.
		var body = table.querySelector( 'tbody' );
		var markDraggable = function () {
			Array.prototype.forEach.call( body.querySelectorAll( 'tr[id^="post-"]' ), function ( row ) {
				row.setAttribute( 'draggable', 'true' );
			} );
		};
		markDraggable();
		body.addEventListener( 'dragstart', function ( event ) {
			var row = event.target.closest ? event.target.closest( 'tr[id^="post-"]' ) : null;
			if ( ! row || ! event.dataTransfer ) {
				return;
			}
			event.dataTransfer.setData( MIME, JSON.stringify( selectedListIds( row ) ) );
			event.dataTransfer.setData( 'text/plain', row.id );
			event.dataTransfer.effectAllowed = 'move';
		} );

		setupBulk();
		return true;
	}

	// ------------------------------------------------------------ bulk action

	function bulkOptions( select ) {
		select.textContent = '';
		select.appendChild( h( 'option', { value: '0', text: i18n.uncategorized } ) );
		flatten( 0, 0, [] ).forEach( function ( entry ) {
			var prefix = new Array( entry.depth + 1 ).join( '— ' );
			select.appendChild( h( 'option', { value: String( entry.folder.id ), text: prefix + entry.folder.name } ) );
		} );
	}

	var bulkSelects = [];

	function refreshBulkSelects() {
		bulkSelects.forEach( function ( select ) {
			var value = select.value;
			bulkOptions( select );
			select.value = value;
		} );
	}

	function setupBulk() {
		var form = document.getElementById( 'posts-filter' );
		if ( ! form ) {
			return;
		}
		var actionSelects = form.querySelectorAll( 'select[name="action"], select[name="action2"]' );
		var sync = function () {
			Array.prototype.forEach.call( actionSelects, function ( actionSelect, index ) {
				var folderSelect = bulkSelects[ index ];
				if ( folderSelect ) {
					folderSelect.hidden = actionSelect.value !== cfg.bulkAction;
				}
			} );
		};
		Array.prototype.forEach.call( actionSelects, function ( actionSelect ) {
			var folderSelect = h( 'select', {
				name: 'uxstudio_bulk_folder',
				class: 'uxs-fm-bulk-select',
				'aria-label': i18n.moveFolderLabel,
				hidden: 'hidden',
			} );
			bulkOptions( folderSelect );
			actionSelect.parentNode.insertBefore( folderSelect, actionSelect.nextSibling );
			bulkSelects.push( folderSelect );
			folderSelect.addEventListener( 'change', function () {
				bulkSelects.forEach( function ( other ) {
					other.value = folderSelect.value;
				} );
			} );
			actionSelect.addEventListener( 'change', sync );
		} );
		sync();
	}

	// ------------------------------------------------- media (grid + modal)

	function patchUploader() {
		var wp = window.wp;
		if ( ! wp || ! wp.Uploader || ! wp.Uploader.prototype ) {
			return;
		}
		var original = wp.Uploader.prototype.init;
		wp.Uploader.prototype.init = function () {
			if ( this.uploader && this.uploader.bind ) {
				this.uploader.bind( 'BeforeUpload', function ( up ) {
					var params = up.settings.multipart_params || {};
					if ( typeof activeFolder === 'number' && activeFolder > 0 ) {
						params.uxstudio_folder = activeFolder;
					} else {
						delete params.uxstudio_folder;
					}
					up.settings.multipart_params = params;
				} );
			}
			if ( original ) {
				return original.apply( this, arguments );
			}
			return undefined;
		};
	}

	function mediaProps( id ) {
		var props = {};
		props[ cfg.filterVar ] = id;
		return props;
	}

	function attachToBrowser( browser ) {
		if ( browser._uxsFolders || ! browser.$el || ! browser.collection ) {
			return;
		}
		browser._uxsFolders = true;
		browsers.push( browser );

		var inModal = browser.$el.closest( '.media-modal' ).length > 0;
		var el = browser.el;
		var initial = inModal ? null : ( typeof cfg.current === 'number' ? cfg.current : null );
		var panel = new Panel( {
			selected: initial,
			onSelect: function ( id ) {
				if ( browser.collection && browser.collection.props ) {
					browser.collection.props.set( mediaProps( id ) );
				}
			},
		} );

		var content = h( 'div', { class: 'uxs-fm-content' } );
		Array.prototype.slice.call( el.childNodes ).forEach( function ( node ) {
			content.appendChild( node );
		} );
		el.appendChild( panel.el );
		el.appendChild( content );
		el.classList.add( 'uxs-fm-has-panel', inModal ? 'uxs-fm-modal' : 'uxs-fm-flow' );

		if ( initial !== null ) {
			activeFolder = initial;
			browser.collection.props.set( mediaProps( initial ) );
		}

		// Drag attachments onto a folder.
		var mark = function () {
			Array.prototype.forEach.call( el.querySelectorAll( '.attachments .attachment:not([draggable])' ), function ( node ) {
				node.setAttribute( 'draggable', 'true' );
			} );
		};
		mark();
		if ( window.MutationObserver ) {
			new window.MutationObserver( mark ).observe( content, { childList: true, subtree: true } );
		}
		el.addEventListener( 'dragstart', function ( event ) {
			var node = event.target.closest ? event.target.closest( '.attachments .attachment' ) : null;
			if ( ! node || ! event.dataTransfer ) {
				return;
			}
			var id = parseInt( node.getAttribute( 'data-id' ), 10 );
			if ( ! id ) {
				return;
			}
			var ids = [ id ];
			var selection = browser.options && browser.options.selection;
			if ( selection && selection.get && selection.get( id ) && selection.length > 1 ) {
				ids = selection.map( function ( model ) {
					return model.get( 'id' );
				} );
			}
			event.dataTransfer.setData( MIME, JSON.stringify( ids ) );
			event.dataTransfer.setData( 'text/plain', String( id ) );
			event.dataTransfer.effectAllowed = 'move';
		} );
	}

	function initMedia() {
		var media = window.wp && window.wp.media;
		if ( ! media || ! media.view || ! media.view.AttachmentsBrowser ) {
			return false;
		}

		cfg.mode = cfg.mode || 'media';
		patchUploader();

		var proto = media.view.AttachmentsBrowser.prototype;
		var original = proto.initialize;
		proto.initialize = function () {
			var result = original.apply( this, arguments );
			var browser = this;
			this.on( 'ready', function () {
				attachToBrowser( browser );
			} );
			return result;
		};

		return true;
	}

	// ------------------------------------------------------------------ boot

	// The media patch must be in place before wp.media builds its first view,
	// so patch immediately; the list sidebar needs the DOM.
	initMedia();
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			if ( document.querySelector( '.wp-list-table' ) ) {
				initListPage();
			}
		} );
	} else if ( document.querySelector( '.wp-list-table' ) ) {
		initListPage();
	}

}() );
