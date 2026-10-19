/**
 * Duplicate Post - element picker modal + REST call, shared by the classic
 * editor, the WooCommerce publish box and the block editor toolbar button.
 * Exposes window.uxStudioDuplicatePostApi.open( postId, triggerElement ).
 */
( function () {
	'use strict';

	var cfg = window.uxStudioDuplicatePost;
	if ( ! cfg ) {
		return;
	}

	var modal = null;
	var escHandler = null;

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function closeModal() {
		if ( modal ) {
			modal.remove();
			modal = null;
			document.body.style.overflow = '';
		}
		if ( escHandler ) {
			document.removeEventListener( 'keydown', escHandler );
			escHandler = null;
		}
	}

	function checkboxes() {
		return modal ? Array.prototype.slice.call( modal.querySelectorAll( '.uxstudio-duplicate-modal__element input[type="checkbox"]' ) ) : [];
	}

	function setLoading( trigger, loading ) {
		if ( ! trigger ) {
			return;
		}
		if ( loading ) {
			trigger.dataset.label = trigger.textContent;
			trigger.classList.add( 'updating-message', 'is-busy' );
			trigger.setAttribute( 'aria-disabled', 'true' );
			trigger.disabled = true;
			trigger.textContent = cfg.i18n.duplicating;
		} else {
			trigger.classList.remove( 'updating-message', 'is-busy' );
			trigger.removeAttribute( 'aria-disabled' );
			trigger.disabled = false;
			trigger.textContent = trigger.dataset.label || cfg.i18n.duplicate;
		}
	}

	function notify( message, isError ) {
		if ( cfg.isBlockEditor && window.wp && window.wp.data ) {
			var notices = window.wp.data.dispatch( 'core/notices' );
			if ( notices ) {
				( isError ? notices.createErrorNotice : notices.createSuccessNotice )( message, { type: 'snackbar', isDismissible: true } );
				return;
			}
		}
		var box = el( 'div', 'uxstudio-duplicate-notice' + ( isError ? ' is-error' : '' ), message );
		box.setAttribute( 'role', 'status' );
		document.body.appendChild( box );
		window.setTimeout( function () {
			box.remove();
		}, isError ? 5000 : 3000 );
	}

	function request( postId, elements ) {
		return fetch( cfg.restUrl + encodeURIComponent( postId ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( elements.length ? { elements: elements } : {} ),
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( body ) {
				if ( ! res.ok ) {
					throw new Error( body && body.message ? body.message : cfg.i18n.error );
				}
				var data = body && body.data;
				if ( ! data || ! data.id ) {
					throw new Error( cfg.i18n.errorInvalid );
				}
				return data;
			} );
		} );
	}

	function confirmModal( postId, trigger ) {
		var selected = checkboxes()
			.filter( function ( box ) {
				return box.checked;
			} )
			.map( function ( box ) {
				return box.value;
			} );
		closeModal();
		setLoading( trigger, true );

		request( postId, selected )
			.then( function ( data ) {
				notify( cfg.i18n.success, false );
				window.setTimeout( function () {
					if ( cfg.postRedirect ) {
						window.location.href = data.edit_url || data.list_url;
					} else {
						setLoading( trigger, false );
					}
				}, 500 );
			} )
			.catch( function ( err ) {
				setLoading( trigger, false );
				notify( err.message || cfg.i18n.error, true );
			} );
	}

	function openModal( postId, trigger ) {
		closeModal();

		var overlay = el( 'div', 'uxstudio-duplicate-modal-overlay' );
		var box = el( 'div', 'uxstudio-duplicate-modal' );
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-label', cfg.i18n.modalTitle );

		var header = el( 'div', 'uxstudio-duplicate-modal__header' );
		header.appendChild( el( 'h2', 'uxstudio-duplicate-modal__title', cfg.i18n.modalTitle ) );
		header.appendChild( el( 'p', 'uxstudio-duplicate-modal__description', cfg.i18n.modalDescription ) );

		var body = el( 'div', 'uxstudio-duplicate-modal__body' );
		var toggleWrap = el( 'div', 'uxstudio-duplicate-modal__toggle-all' );
		var toggle = el( 'button', 'uxstudio-duplicate-modal__select-toggle', cfg.i18n.deselectAll );
		toggle.type = 'button';
		toggleWrap.appendChild( toggle );
		body.appendChild( toggleWrap );

		var defaults = cfg.defaultElements || [];
		Object.keys( cfg.elements || {} ).forEach( function ( key ) {
			var row = el( 'div', 'uxstudio-duplicate-modal__element' );
			var input = document.createElement( 'input' );
			input.type = 'checkbox';
			input.id = 'uxstudio-dup-' + key;
			input.value = key;
			input.checked = defaults.indexOf( key ) !== -1;
			var label = el( 'label', '', cfg.elements[ key ] );
			label.setAttribute( 'for', input.id );
			row.appendChild( input );
			row.appendChild( label );
			body.appendChild( row );
		} );

		var footer = el( 'div', 'uxstudio-duplicate-modal__footer' );
		var cancel = el( 'button', 'button uxstudio-duplicate-modal__cancel', cfg.i18n.modalCancel );
		cancel.type = 'button';
		var confirm = el( 'button', 'button button-primary uxstudio-duplicate-modal__confirm', cfg.i18n.duplicate );
		confirm.type = 'button';
		footer.appendChild( cancel );
		footer.appendChild( confirm );

		box.appendChild( header );
		box.appendChild( body );
		box.appendChild( footer );
		overlay.appendChild( box );
		document.body.appendChild( overlay );
		document.body.style.overflow = 'hidden';
		modal = overlay;

		function syncToggleLabel() {
			var all = checkboxes().every( function ( c ) {
				return c.checked;
			} );
			toggle.textContent = all ? cfg.i18n.deselectAll : cfg.i18n.selectAll;
		}
		syncToggleLabel();

		toggle.addEventListener( 'click', function () {
			var boxes = checkboxes();
			var all = boxes.every( function ( c ) {
				return c.checked;
			} );
			boxes.forEach( function ( c ) {
				c.checked = ! all;
			} );
			syncToggleLabel();
		} );
		body.addEventListener( 'change', syncToggleLabel );
		cancel.addEventListener( 'click', closeModal );
		confirm.addEventListener( 'click', function () {
			confirmModal( postId, trigger );
		} );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				closeModal();
			}
		} );
		escHandler = function ( e ) {
			if ( 'Escape' === e.key ) {
				closeModal();
			}
		};
		document.addEventListener( 'keydown', escHandler );
		confirm.focus();
	}

	window.uxStudioDuplicatePostApi = { open: openModal };

	if ( cfg.isBlockEditor ) {
		return; // The block editor script adds its own toolbar button.
	}

	/* Classic editor + WooCommerce publish box. */
	function bind( node ) {
		node.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var id = node.dataset.id;
			if ( id ) {
				openModal( id, node );
			}
		} );
	}

	function init() {
		var existing = document.querySelectorAll( '.uxstudio-duplicate-post' );
		if ( existing.length ) {
			// WooCommerce product: the publish box button is already rendered.
			Array.prototype.forEach.call( existing, bind );
			return;
		}
		var addNew = document.querySelector( '.page-title-action' );
		if ( ! addNew || ! addNew.parentNode ) {
			return;
		}
		var link = el( 'a', 'page-title-action uxstudio-duplicate-post', cfg.i18n.duplicate );
		link.href = '#';
		link.dataset.id = String( cfg.postId );
		link.style.marginLeft = '4px';
		bind( link );
		addNew.parentNode.insertBefore( link, addNew.nextSibling );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
