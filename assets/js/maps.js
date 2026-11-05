/**
 * UX Studio Maps - front-end renderer for [uxstudio_map].
 *
 * Each .uxs-map[data-uxs-map] carries its config as JSON (see
 * includes/Modules/Maps/Frontend.php). OpenStreetMap, Mapy.com and Mapbox are
 * drawn with the locally bundled Leaflet (window.L); Google Maps loads the
 * Maps JavaScript API on demand. Maps are built when they scroll into view,
 * or after a click when "click to load" is on (no request to the provider
 * before that).
 */
( function () {
	'use strict';

	var GOOGLE_CALLBACK = '__uxsMapsGoogleReady';
	var googleQueue = null;

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function routeUrl( cfg, m ) {
		if ( cfg.provider === 'mapy' ) {
			return 'https://mapy.com/fnc/v1/route?end=' + m.lng + ',' + m.lat;
		}
		return 'https://www.google.com/maps/dir/?api=1&destination=' + m.lat + ',' + m.lng;
	}

	/* Popup HTML: title escaped here, text already sanitized on the server. */
	function popupHtml( cfg, m ) {
		var html = '<div class="uxs-map__popup">';
		if ( m.title ) {
			html += '<strong class="uxs-map__title">' + escapeHtml( m.title ) + '</strong>';
		}
		if ( m.text ) {
			var text = /<br\s*\/?>/i.test( m.text ) ? m.text : m.text.replace( /\n/g, '<br>' );
			html += '<div class="uxs-map__text">' + text + '</div>';
		}
		var links = [];
		if ( m.url ) {
			links.push( '<a href="' + escapeHtml( m.url ) + '">' + escapeHtml( cfg.i18n.more ) + '</a>' );
		}
		if ( cfg.routeLink ) {
			links.push( '<a href="' + escapeHtml( routeUrl( cfg, m ) ) + '" target="_blank" rel="noopener">' + escapeHtml( cfg.i18n.route ) + '</a>' );
		}
		if ( links.length ) {
			html += '<div class="uxs-map__links">' + links.join( '' ) + '</div>';
		}
		return html + '</div>';
	}

	function hasPopup( cfg, m ) {
		return !! ( m.title || m.text || m.url || cfg.routeLink );
	}

	function pinSvg( color ) {
		return (
			'<svg xmlns="http://www.w3.org/2000/svg" width="30" height="40" viewBox="0 0 30 40" aria-hidden="true">' +
			'<path d="M15 0C6.7 0 0 6.6 0 14.8 0 25.9 15 40 15 40s15-14.1 15-25.2C30 6.6 23.3 0 15 0z" fill="' + escapeHtml( color ) + '"/>' +
			'<circle cx="15" cy="14.5" r="5.5" fill="#fff"/></svg>'
		);
	}

	/* ---------------------------------------------------------------- Leaflet */

	function tileLayer( L, cfg ) {
		if ( cfg.provider === 'mapy' ) {
			return L.tileLayer( 'https://api.mapy.com/v1/maptiles/' + encodeURIComponent( cfg.style ) + '/256/{z}/{x}/{y}?apikey=' + encodeURIComponent( cfg.key ), {
				minZoom: 0,
				maxZoom: 19,
				attribution: '<a href="https://api.mapy.com/copyright" target="_blank" rel="noopener">&copy; Seznam.cz a.s. a další</a>',
			} );
		}
		if ( cfg.provider === 'mapbox' ) {
			return L.tileLayer( 'https://api.mapbox.com/styles/v1/' + cfg.style + '/tiles/512/{z}/{x}/{y}@2x?access_token=' + encodeURIComponent( cfg.key ), {
				tileSize: 512,
				zoomOffset: -1,
				maxZoom: 20,
				attribution: '&copy; <a href="https://www.mapbox.com/about/maps/" target="_blank" rel="noopener">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
			} );
		}
		return L.tileLayer( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
		} );
	}

	function initLeaflet( el, cfg ) {
		var L = window.L;
		if ( ! L ) {
			throw new Error( 'Leaflet missing' );
		}
		var map = L.map( el, { scrollWheelZoom: !! cfg.scrollZoom } );
		tileLayer( L, cfg ).addTo( map );

		if ( cfg.provider === 'mapy' ) {
			// Mapy.com terms require their logo on the map.
			var Logo = L.Control.extend( {
				options: { position: 'bottomleft' },
				onAdd: function () {
					var a = L.DomUtil.create( 'a', 'uxs-map__mapy-logo' );
					a.href = 'https://mapy.com/';
					a.target = '_blank';
					a.rel = 'noopener';
					a.innerHTML = '<img src="https://api.mapy.com/img/api/logo.svg" alt="Mapy.com" width="100" height="25">';
					L.DomEvent.disableClickPropagation( a );
					return a;
				},
			} );
			new Logo().addTo( map );
		}

		var points = [];
		( cfg.markers || [] ).forEach( function ( m ) {
			var icon = L.divIcon( { html: pinSvg( m.color ), className: 'uxs-map__pin', iconSize: [ 30, 40 ], iconAnchor: [ 15, 40 ], popupAnchor: [ 0, -36 ] } );
			var marker = L.marker( [ m.lat, m.lng ], { icon: icon, title: m.title || '', alt: m.title || '' } ).addTo( map );
			if ( hasPopup( cfg, m ) ) {
				marker.bindPopup( popupHtml( cfg, m ) );
			}
			points.push( [ m.lat, m.lng ] );
		} );

		if ( cfg.fit && points.length > 1 ) {
			map.fitBounds( points, { padding: [ 40, 40 ], maxZoom: 16 } );
		} else if ( cfg.fit && points.length === 1 ) {
			map.setView( points[ 0 ], cfg.zoom );
		} else {
			map.setView( [ cfg.center.lat, cfg.center.lng ], cfg.zoom );
		}
	}

	/* ----------------------------------------------------------------- Google */

	function loadGoogle( key ) {
		if ( window.google && window.google.maps && window.google.maps.Map ) {
			return Promise.resolve();
		}
		if ( googleQueue ) {
			return googleQueue;
		}
		googleQueue = new Promise( function ( resolve, reject ) {
			window[ GOOGLE_CALLBACK ] = resolve;
			var s = document.createElement( 'script' );
			s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent( key ) + '&v=weekly&loading=async&callback=' + GOOGLE_CALLBACK;
			s.async = true;
			s.onerror = reject;
			document.head.appendChild( s );
		} );
		return googleQueue;
	}

	function initGoogle( el, cfg ) {
		return loadGoogle( cfg.key ).then( function () {
			var g = window.google.maps;
			var opts = {
				center: { lat: cfg.center.lat, lng: cfg.center.lng },
				zoom: cfg.zoom,
				mapTypeId: cfg.style || 'roadmap',
				gestureHandling: cfg.scrollZoom ? 'greedy' : 'cooperative',
				streetViewControl: false,
			};
			if ( cfg.mapId ) {
				opts.mapId = cfg.mapId;
			}
			var map = new g.Map( el, opts );
			var info = new g.InfoWindow();
			var bounds = new g.LatLngBounds();
			var markerLib = cfg.mapId && g.importLibrary ? g.importLibrary( 'marker' ) : Promise.resolve( null );

			return markerLib.then( function ( lib ) {
				( cfg.markers || [] ).forEach( function ( m ) {
					var position = { lat: m.lat, lng: m.lng };
					var marker;
					if ( lib && lib.AdvancedMarkerElement ) {
						// Map ID present: modern marker with a colored pin.
						var pin = new lib.PinElement( { background: m.color, borderColor: m.color, glyphColor: '#ffffff' } );
						marker = new lib.AdvancedMarkerElement( { map: map, position: position, title: m.title || '', content: pin.element } );
					} else {
						marker = new g.Marker( {
							map: map,
							position: position,
							title: m.title || '',
							icon: { url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent( pinSvg( m.color ) ), scaledSize: new g.Size( 30, 40 ), anchor: new g.Point( 15, 40 ) },
						} );
					}
					if ( hasPopup( cfg, m ) ) {
						marker.addListener( 'click', function () {
							info.setContent( popupHtml( cfg, m ) );
							info.open( { map: map, anchor: marker } );
						} );
					}
					bounds.extend( position );
				} );

				var count = ( cfg.markers || [] ).length;
				if ( cfg.fit && count > 1 ) {
					map.fitBounds( bounds, 40 );
				} else if ( cfg.fit && count === 1 ) {
					map.setCenter( { lat: cfg.markers[ 0 ].lat, lng: cfg.markers[ 0 ].lng } );
				}
			} );
		} );
	}

	/* ------------------------------------------------------------------- Boot */

	function build( el ) {
		if ( el.getAttribute( 'data-uxs-map-ready' ) ) {
			return;
		}
		el.setAttribute( 'data-uxs-map-ready', '1' );
		var cfg;
		try {
			cfg = JSON.parse( el.getAttribute( 'data-uxs-map' ) || '{}' );
		} catch ( e ) {
			return;
		}
		el.innerHTML = '';
		var fail = function () {
			el.classList.add( 'uxs-map--error' );
			el.textContent = cfg.i18n ? cfg.i18n.error : '';
		};
		try {
			if ( cfg.provider === 'google' ) {
				initGoogle( el, cfg ).catch( fail );
			} else {
				initLeaflet( el, cfg );
			}
		} catch ( e ) {
			fail();
		}
	}

	function placeholder( el ) {
		var cfg = JSON.parse( el.getAttribute( 'data-uxs-map' ) || '{}' );
		el.classList.add( 'uxs-map--placeholder' );
		var box = document.createElement( 'div' );
		box.className = 'uxs-map__consent';
		var note = document.createElement( 'p' );
		note.textContent = cfg.i18n.note;
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'uxs-map__load';
		button.textContent = cfg.i18n.load;
		button.addEventListener( 'click', function () {
			el.classList.remove( 'uxs-map--placeholder' );
			build( el );
		} );
		box.appendChild( note );
		box.appendChild( button );
		el.appendChild( box );
	}

	function boot( root ) {
		var maps = Array.prototype.slice.call( ( root || document ).querySelectorAll( '.uxs-map[data-uxs-map]' ) );
		var lazy = [];
		maps.forEach( function ( el ) {
			var cfg;
			if ( el.getAttribute( 'data-uxs-map-ready' ) || el.classList.contains( 'uxs-map--placeholder' ) ) {
				return;
			}
			try {
				cfg = JSON.parse( el.getAttribute( 'data-uxs-map' ) || '{}' );
			} catch ( e ) {
				return;
			}
			if ( cfg.clickToLoad ) {
				placeholder( el );
			} else {
				lazy.push( el );
			}
		} );
		if ( ! lazy.length ) {
			return;
		}
		if ( ! ( 'IntersectionObserver' in window ) ) {
			lazy.forEach( build );
			return;
		}
		var io = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						io.unobserve( entry.target );
						build( entry.target );
					}
				} );
			},
			{ rootMargin: '200px' }
		);
		lazy.forEach( function ( el ) {
			io.observe( el );
		} );
	}

	/**
	 * Build the maps inside one element (used for markup injected after load,
	 * e.g. the Elementor editor preview).
	 */
	window.uxsMaps = {
		init: function ( root ) {
			boot( root || document );
		},
	};

	// Elementor editor/preview: the widget HTML is injected after DOMContentLoaded.
	function hookElementor() {
		if ( ! window.elementorFrontend || ! window.elementorFrontend.hooks ) {
			return;
		}
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/uxstudio_map.default', function ( $scope ) {
			var node = $scope && $scope[ 0 ] ? $scope[ 0 ] : $scope;
			if ( node && node.querySelectorAll ) {
				window.uxsMaps.init( node );
			}
		} );
	}
	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		hookElementor();
	} else if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', hookElementor );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			boot( document );
		} );
	} else {
		boot( document );
	}
} )();
