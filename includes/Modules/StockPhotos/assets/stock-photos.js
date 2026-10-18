/**
 * Stock Photos - "Stock photos" tab inside the WordPress media modal.
 * Searches the configured providers in parallel through the module REST
 * endpoint and imports the chosen image into the media library.
 * Ported from the legacy module (new REST contract: results carry
 * thumb_url / full_url / source_page_url, import takes the image URL).
 */
( function ( $ ) {
	'use strict';

	if ( typeof wp === 'undefined' || ! wp.media ) {
		return;
	}

	var config = window.uxsStockPhotos;
	if ( ! config ) {
		return;
	}

	var i18n = config.i18n;

	function esc( value ) {
		return String( value === null || value === undefined ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/** Only http(s) URLs are ever rendered into src / href. */
	function safeUrl( url ) {
		return /^https?:\/\//i.test( url || '' ) ? esc( url ) : '';
	}

	var StockPhotosBrowser = wp.media.View.extend( {
		className: 'uxs-sp-browser',

		events: {
			'submit .uxs-sp-search-form': 'onSearch',
			'click .uxs-sp-filter-btn': 'onFilterChange',
			'click .uxs-sp-card': 'onPhotoSelect',
			'click .uxs-sp-import-btn': 'onImport',
			'click .uxs-sp-more-btn': 'onShowMore',
			'click .uxs-sp-back-btn': 'onBackToResults',
		},

		initialize: function () {
			this.providers = config.providers;
			this.query = '';
			this.activeFilter = 'all';
			this.providerState = {};
			for ( var id in this.providers ) {
				if ( Object.prototype.hasOwnProperty.call( this.providers, id ) ) {
					this.providerState[ id ] = this.freshState();
				}
			}
			this.selectedPhoto = null;
			this.isImporting = false;
			this.importSuccess = false;
			this.searchDone = false;
		},

		freshState: function () {
			return { results: [], total: 0, page: 1, loading: false, error: '' };
		},

		render: function () {
			var focused = this.$( '.uxs-sp-search-input' ).is( ':focus' );
			var typed = this.$( '.uxs-sp-search-input' ).val();
			this.$el.html( this.buildHTML() );
			if ( typed !== undefined && typed !== this.query ) {
				this.$( '.uxs-sp-search-input' ).val( typed );
			}
			if ( focused || ! this.searchDone ) {
				var self = this;
				setTimeout( function () {
					self.$( '.uxs-sp-search-input' ).trigger( 'focus' );
				}, 50 );
			}
			return this;
		},

		buildHTML: function () {
			var html = '<div class="uxs-sp-header">';
			html += '<form class="uxs-sp-search-form">';
			html += '<input type="search" class="uxs-sp-search-input" placeholder="' + esc( i18n.searchPlaceholder ) + '" value="' + esc( this.query ) + '" />';
			html += '<button type="submit" class="button button-primary uxs-sp-search-btn">' + esc( i18n.search ) + '</button>';
			html += '</form>';
			if ( this.searchDone ) {
				html += this.renderFilters();
			}
			html += '</div><div class="uxs-sp-content">';
			if ( this.selectedPhoto ) {
				html += this.renderDetail();
			} else if ( ! this.searchDone ) {
				html += '<div class="uxs-sp-empty"><span class="dashicons dashicons-images-alt2"></span><p>' + esc( i18n.searchPlaceholder ) + '</p></div>';
			} else {
				html += this.renderSections();
			}
			return html + '</div>';
		},

		renderFilters: function () {
			var html = '<div class="uxs-sp-filters">';
			html += '<button type="button" class="uxs-sp-filter-btn' + ( this.activeFilter === 'all' ? ' active' : '' ) + '" data-filter="all">' + esc( i18n.allProviders ) + '</button>';
			for ( var id in this.providers ) {
				if ( ! Object.prototype.hasOwnProperty.call( this.providers, id ) ) {
					continue;
				}
				var ps = this.providerState[ id ];
				if ( ps.results.length === 0 && ! ps.loading ) {
					continue;
				}
				var count = ps.total > 0 ? ' (' + ps.total + ')' : '';
				html += '<button type="button" class="uxs-sp-filter-btn' + ( this.activeFilter === id ? ' active' : '' ) + '" data-filter="' + esc( id ) + '">' + esc( this.providers[ id ].name ) + count + '</button>';
			}
			return html + '</div>';
		},

		renderSections: function () {
			var html = '';
			var any = false;
			var visible = this.visibleProviders();
			for ( var i = 0; i < visible.length; i++ ) {
				var ps = this.providerState[ visible[ i ] ];
				if ( ps.results.length === 0 && ! ps.loading && ! ps.error ) {
					continue;
				}
				any = true;
				html += this.renderSection( visible[ i ] );
			}
			if ( ! any && ! this.isAnyLoading() ) {
				html += '<div class="uxs-sp-empty"><span class="dashicons dashicons-format-image"></span><p>' + esc( i18n.noResults ) + '</p></div>';
			}
			return html;
		},

		renderSection: function ( providerId ) {
			var ps = this.providerState[ providerId ];
			var html = '<div class="uxs-sp-section" data-provider="' + esc( providerId ) + '">';
			html += '<div class="uxs-sp-section-header"><h3 class="uxs-sp-section-title">' + esc( this.providers[ providerId ].name );
			if ( ps.total > 0 ) {
				html += ' <span class="uxs-sp-section-count">' + esc( ps.total.toLocaleString() ) + '</span>';
			}
			html += '</h3>';
			if ( ps.loading ) {
				html += '<div class="uxs-sp-spinner uxs-sp-spinner--small"></div>';
			}
			html += '</div>';

			if ( ps.error ) {
				html += '<div class="uxs-sp-section-error">' + esc( ps.error ) + '</div>';
			}

			if ( ps.results.length > 0 ) {
				html += '<div class="uxs-sp-grid">';
				for ( var i = 0; i < ps.results.length; i++ ) {
					var photo = ps.results[ i ];
					html += '<div class="uxs-sp-card" data-provider="' + esc( providerId ) + '" data-index="' + i + '">';
					html += '<img class="uxs-sp-card-img" src="' + safeUrl( photo.thumb_url ) + '" alt="" loading="lazy" />';
					html += '<div class="uxs-sp-card-overlay">' + esc( photo.author ) + '</div></div>';
				}
				html += '</div>';

				if ( ps.results.length < ps.total ) {
					html += '<div class="uxs-sp-section-footer">';
					html += ps.loading
						? '<div class="uxs-sp-spinner uxs-sp-spinner--small"></div>'
						: '<button type="button" class="button uxs-sp-more-btn" data-provider="' + esc( providerId ) + '">' + esc( i18n.loadMore ) + '</button>';
					html += '</div>';
				}
			}

			return html + '</div>';
		},

		renderDetail: function () {
			var p = this.selectedPhoto;
			var providerName = this.providers[ p.provider ] ? this.providers[ p.provider ].name : p.provider;
			var html = '<div class="uxs-sp-detail"><div class="uxs-sp-detail-preview"><img src="' + safeUrl( p.thumb_url ) + '" alt="" /></div>';
			html += '<div class="uxs-sp-detail-sidebar"><dl class="uxs-sp-detail-meta">';

			if ( p.author ) {
				html += '<dt>' + esc( i18n.author ) + '</dt><dd>' + esc( p.author ) + '</dd>';
			}
			if ( p.license ) {
				html += '<dt>' + esc( i18n.license ) + '</dt><dd>' + esc( p.license ) + '</dd>';
			}
			if ( p.width && p.height ) {
				html += '<dt>' + esc( i18n.dimensions ) + '</dt><dd>' + esc( p.width ) + ' &times; ' + esc( p.height ) + '</dd>';
			}
			if ( safeUrl( p.source_page_url ) ) {
				html += '<dt>' + esc( i18n.source ) + '</dt><dd><a href="' + safeUrl( p.source_page_url ) + '" target="_blank" rel="noopener noreferrer">' + esc( providerName ) + '</a></dd>';
			}
			html += '</dl><div class="uxs-sp-detail-actions">';

			if ( this.importSuccess ) {
				html += '<div class="uxs-sp-success"><span class="dashicons dashicons-yes-alt"></span>' + esc( i18n.imported ) + '</div>';
			} else {
				html += '<button type="button" class="button button-primary uxs-sp-import-btn"' + ( this.isImporting ? ' disabled' : '' ) + '>';
				html += esc( this.isImporting ? i18n.importing : i18n.importLabel ) + '</button>';
			}
			html += '<button type="button" class="button uxs-sp-back-btn">' + esc( i18n.backToResults ) + '</button>';
			return html + '</div></div></div>';
		},

		visibleProviders: function () {
			var out = [];
			for ( var id in this.providers ) {
				if ( Object.prototype.hasOwnProperty.call( this.providers, id ) && ( this.activeFilter === 'all' || this.activeFilter === id ) ) {
					out.push( id );
				}
			}
			return out;
		},

		isAnyLoading: function () {
			for ( var id in this.providerState ) {
				if ( this.providerState[ id ].loading ) {
					return true;
				}
			}
			return false;
		},

		onSearch: function ( e ) {
			e.preventDefault();
			var val = $.trim( this.$( '.uxs-sp-search-input' ).val() );
			if ( ! val ) {
				return;
			}
			this.query = val;
			this.selectedPhoto = null;
			this.importSuccess = false;
			this.searchDone = true;
			this.activeFilter = 'all';
			for ( var id in this.providerState ) {
				this.providerState[ id ] = this.freshState();
			}
			this.render();
			for ( var pid in this.providers ) {
				if ( Object.prototype.hasOwnProperty.call( this.providers, pid ) ) {
					this.doProviderSearch( pid, false );
				}
			}
		},

		onFilterChange: function ( e ) {
			var filter = $( e.currentTarget ).data( 'filter' );
			if ( filter !== this.activeFilter ) {
				this.activeFilter = filter;
				this.render();
			}
		},

		onPhotoSelect: function ( e ) {
			var el = $( e.currentTarget );
			var providerId = el.data( 'provider' );
			var photo = this.providerState[ providerId ].results[ parseInt( el.data( 'index' ), 10 ) ] || null;
			this.selectedPhoto = photo ? $.extend( {}, photo, { provider: providerId } ) : null;
			this.importSuccess = false;
			this.render();
		},

		onBackToResults: function () {
			this.selectedPhoto = null;
			this.importSuccess = false;
			this.render();
		},

		onShowMore: function ( e ) {
			var providerId = $( e.currentTarget ).data( 'provider' );
			var ps = this.providerState[ providerId ];
			if ( ps.results.length < ps.total && ! ps.loading ) {
				ps.page++;
				this.doProviderSearch( providerId, true );
			}
		},

		onImport: function () {
			if ( this.isImporting || ! this.selectedPhoto ) {
				return;
			}
			var self = this;
			var photo = this.selectedPhoto;
			this.isImporting = true;
			this.render();

			$.ajax( {
				url: config.restUrl + '/import',
				method: 'POST',
				data: {
					provider: photo.provider,
					image_url: photo.full_url || photo.thumb_url,
					title: '',
				},
				beforeSend: function ( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', config.nonce );
				},
			} ).done( function ( response ) {
				self.isImporting = false;
				self.importSuccess = true;

				var data = response && response.data ? response.data : response;
				if ( data && data.id ) {
					var attachment = wp.media.attachment( data.id );
					attachment.fetch().done( function () {
						var state = self.controller && self.controller.state ? self.controller.state() : null;
						var library = state && state.get ? state.get( 'library' ) : null;
						if ( library ) {
							library.add( attachment, { at: 0 } );
						}
					} );
				}
				self.render();
			} ).fail( function ( xhr ) {
				self.isImporting = false;
				window.alert( xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : i18n.error );
				self.render();
			} );
		},

		doProviderSearch: function ( providerId, append ) {
			var self = this;
			var ps = this.providerState[ providerId ];
			ps.loading = true;
			ps.error = '';
			if ( append ) {
				this.$( '.uxs-sp-section[data-provider="' + providerId + '"] .uxs-sp-section-footer' )
					.html( '<div class="uxs-sp-spinner uxs-sp-spinner--small"></div>' );
			}

			$.ajax( {
				url: config.restUrl + '/search',
				data: { provider: providerId, query: this.query, page: ps.page },
				beforeSend: function ( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', config.nonce );
				},
			} ).done( function ( response ) {
				var data = response && response.data ? response.data : response;
				ps.loading = false;
				ps.results = append ? ps.results.concat( data.results || [] ) : ( data.results || [] );
				ps.total = data.total || 0;
				self.render();
			} ).fail( function ( xhr ) {
				ps.loading = false;
				ps.error = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : i18n.error;
				self.render();
			} );
		},
	} );

	function extendFrame( Original ) {
		if ( ! Original ) {
			return null;
		}

		return Original.extend( {
			browseRouter: function ( routerView ) {
				Original.prototype.browseRouter.apply( this, arguments );
				routerView.set( 'uxs-stock-photos', {
					text: i18n.tabTitle,
					priority: 60,
				} );
			},

			bindHandlers: function () {
				Original.prototype.bindHandlers.apply( this, arguments );
				this.on( 'content:create:uxs-stock-photos', this.stockPhotosContent, this );
			},

			stockPhotosContent: function () {
				this.content.set( new StockPhotosBrowser( {
					controller: this,
					model: this.state(),
				} ) );
			},
		} );
	}

	var post = extendFrame( wp.media.view.MediaFrame.Post );
	if ( post ) {
		wp.media.view.MediaFrame.Post = post;
	}

	var select = extendFrame( wp.media.view.MediaFrame.Select );
	if ( select ) {
		wp.media.view.MediaFrame.Select = select;
	}
}( jQuery ) );
