/**
 * Marketing consent of the current visitor, read from the cookie banner that is on the page.
 *
 * Source of truth for the Forms conversion tracking (Sklik needs an explicit consent flag). PublicRenderer inlines
 * this file into the one public runtime script, so it is also what the CSP hash covers; tests/forms-consent-detect.mjs
 * loads the same file in Node with stubbed globals. Keep it free of dependencies and of syntax newer than ES5.
 *
 * Returns 1 (marketing allowed), 0 (a consent tool is present and marketing is not allowed, or still undecided) or
 * null (no consent tool recognised). Order: Complianz, CookieYes, Cookiebot, Google Consent Mode (dataLayer),
 * window.uxstudioConsent. The first tool that answers wins; every probe is guarded, so a broken banner script can
 * never stop the form tracking.
 *
 * Verified public APIs:
 *  - Complianz: cmplz_has_consent( category ) and the cookie cmplz_marketing = allow | deny
 *    https://complianz.io/developers-guide-for-third-party-integrations/
 *  - CookieYes: getCkyConsent().categories.advertisement
 *    https://www.cookieyes.com/documentation/retrieving-consent-data-using-api-getckyconsent/
 *  - Cookiebot: Cookiebot.consent.marketing
 *    https://usercentrics.com/docs/web/cookiebot/cookiebot-sdk
 *  - Google Consent Mode v2: gtag( 'consent', 'default' | 'update', { ad_storage: 'granted' | 'denied' } )
 *
 * @param {Object} win Window-like object (the real window in the browser).
 * @return {?number}
 */
function uxsDetectMarketingConsent( win ) {
	function attempt( fn ) {
		try { return fn(); } catch ( err ) { return null; }
	}

	function cookie( name ) {
		var jar = win.document && typeof win.document.cookie === 'string' ? win.document.cookie : '';
		var parts = jar.split( ';' );
		for ( var i = 0; i < parts.length; i++ ) {
			var pair = parts[ i ].replace( /^\s+/, '' );
			if ( pair.indexOf( name + '=' ) === 0 ) { return decodeURIComponent( pair.slice( name.length + 1 ) ); }
		}
		return null;
	}

	var probes = [
		function complianz() {
			if ( typeof win.cmplz_has_consent === 'function' ) {
				return win.cmplz_has_consent( 'marketing' ) ? 1 : 0;
			}
			var c = cookie( 'cmplz_marketing' );
			if ( c === 'allow' ) { return 1; }
			if ( c === 'deny' ) { return 0; }
			return null;
		},
		function cookieyes() {
			if ( typeof win.getCkyConsent !== 'function' ) { return null; }
			var state = win.getCkyConsent();
			if ( ! state || ! state.categories ) { return null; }
			return state.categories.advertisement ? 1 : 0;
		},
		function cookiebot() {
			var cb = win.Cookiebot;
			if ( ! cb || ! cb.consent || typeof cb.consent.marketing !== 'boolean' ) { return null; }
			return cb.consent.marketing ? 1 : 0;
		},
		function consentMode() {
			// gtag() pushes its Arguments object: [ 'consent', 'default' | 'update', { ad_storage: ... } ]. Replay in
			// order, so a later update overrides the default. GTM-style objects ({ event: ... }) are ignored.
			var layer = win.dataLayer;
			if ( ! layer || typeof layer.length !== 'number' ) { return null; }
			var state = null;
			for ( var i = 0; i < layer.length; i++ ) {
				var item = layer[ i ];
				if ( ! item || item[ 0 ] !== 'consent' || ( item[ 1 ] !== 'default' && item[ 1 ] !== 'update' ) ) { continue; }
				var settings = item[ 2 ];
				if ( settings && ( settings.ad_storage === 'granted' || settings.ad_storage === 'denied' ) ) {
					state = settings.ad_storage === 'granted' ? 1 : 0;
				}
			}
			return state;
		},
		function uxstudio() {
			var own = win.uxstudioConsent;
			if ( ! own || typeof own.marketing !== 'boolean' ) { return null; }
			return own.marketing ? 1 : 0;
		}
	];

	for ( var i = 0; i < probes.length; i++ ) {
		var answer = attempt( probes[ i ] );
		if ( answer === 0 || answer === 1 ) { return answer; }
	}
	return null;
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = uxsDetectMarketingConsent;
}
