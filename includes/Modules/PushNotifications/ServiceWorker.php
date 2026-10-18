<?php
/**
 * Service worker script served for Web Push.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PushNotifications;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the push service worker at the SAME URL the legacy ux1 module used
 * (`<home>/ux1-push-sw.js`, scope = home path). Browsers that subscribed
 * through the legacy plugin already have a worker registered there; on their
 * next visit / daily update check they fetch this script from the same URL and
 * transparently switch to the new one (skipWaiting + clients.claim), so no
 * visitor has to opt in again.
 */
final class ServiceWorker {

	/** Legacy-compatible route (relative to the home URL). */
	public const ROUTE = 'ux1-push-sw.js';

	/**
	 * Whether the current request is for the service worker script.
	 */
	public static function is_request(): bool {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared to a constant.
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$base = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $base ) {
			$path = (string) preg_replace( '#^' . preg_quote( $base, '#' ) . '/#', '', $path );
		}
		return self::ROUTE === $path;
	}

	/**
	 * Absolute URL of the worker script.
	 */
	public static function url(): string {
		return home_url( '/' . self::ROUTE );
	}

	/**
	 * Scope path (home path with trailing slash).
	 */
	public static function scope(): string {
		return rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) . '/';
	}

	/**
	 * Send the worker with the right headers and stop.
	 *
	 * @param string $public_key Public VAPID key (used to re-subscribe on subscription change).
	 */
	public static function serve( string $public_key ): void {
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::scope() );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Content-Type-Options: nosniff' );

		$rest = rest_url( 'uxstudio/v1/push-notifications/' );

		// JSON-encoded values are valid JS literals; hex-escaped so nothing can
		// break out of the script context.
		$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
		$js    = strtr(
			self::template(),
			array(
				'__EVENT_URL__'     => (string) wp_json_encode( $rest . 'event', $flags ),
				'__SUBSCRIBE_URL__' => (string) wp_json_encode( $rest . 'subscribe', $flags ),
				'__UNSUBSCRIBE_URL__' => (string) wp_json_encode( $rest . 'unsubscribe', $flags ),
				'__VAPID_KEY__'     => (string) wp_json_encode( $public_key, $flags ),
				'__DEFAULT_TITLE__' => (string) wp_json_encode( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), $flags ),
			)
		);

		echo $js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript, values are JSON-encoded above.
	}

	/**
	 * Worker source with placeholders.
	 */
	private static function template(): string {
		return <<<'JS'
'use strict';

var EVENT_URL = __EVENT_URL__;
var SUBSCRIBE_URL = __SUBSCRIBE_URL__;
var UNSUBSCRIBE_URL = __UNSUBSCRIBE_URL__;
var VAPID_KEY = __VAPID_KEY__;
var DEFAULT_TITLE = __DEFAULT_TITLE__;

// Take over from an older worker (e.g. the legacy ux1 one) immediately.
self.addEventListener('install', function () {
	self.skipWaiting();
});
self.addEventListener('activate', function (event) {
	event.waitUntil(self.clients.claim());
});

function b64uToBytes(str) {
	var pad = '='.repeat((4 - str.length % 4) % 4);
	var raw = atob((str + pad).replace(/-/g, '+').replace(/_/g, '/'));
	var out = new Uint8Array(raw.length);
	for (var i = 0; i < raw.length; i++) {
		out[i] = raw.charCodeAt(i);
	}
	return out;
}

function bytesToB64u(buffer) {
	var bytes = new Uint8Array(buffer);
	var str = '';
	for (var i = 0; i < bytes.length; i++) {
		str += String.fromCharCode(bytes[i]);
	}
	return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function postJson(url, payload) {
	return fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(payload),
		keepalive: true
	}).catch(function () {
		// Tracking is best effort.
	});
}

// Delivery is recorded server-side when the push service accepts the message,
// so the worker only reports clicks.
self.addEventListener('push', function (event) {
	var data = {};
	if (event.data) {
		try {
			data = event.data.json();
		} catch (e) {
			data = { title: event.data.text() };
		}
	}

	var options = {
		body: data.body || '',
		data: {
			url: data.url || '/',
			notification_id: data.notification_id || 0
		},
		tag: 'uxs-push-' + (data.notification_id || Date.now()),
		renotify: true
	};
	if (data.icon) {
		options.icon = data.icon;
	}
	if (data.image) {
		options.image = data.image;
	}
	if (Array.isArray(data.actions) && data.actions.length) {
		options.actions = data.actions.map(function (a) {
			return { action: a.action || 'open', title: a.title || '' };
		});
	}

	event.waitUntil(self.registration.showNotification(data.title || DEFAULT_TITLE, options));
});

self.addEventListener('notificationclick', function (event) {
	event.notification.close();

	var data = event.notification.data || {};
	var target;
	try {
		target = new URL(data.url || '/', self.location.origin);
	} catch (e) {
		target = new URL('/', self.location.origin);
	}
	if (target.protocol !== 'https:' && target.protocol !== 'http:') {
		target = new URL('/', self.location.origin);
	}
	var targetUrl = target.href;

	var track = self.registration.pushManager.getSubscription().then(function (sub) {
		if (!sub || !data.notification_id) {
			return null;
		}
		return postJson(EVENT_URL, {
			endpoint: sub.endpoint,
			notification_id: data.notification_id,
			event: 'clicked'
		});
	}).catch(function () {
		return null;
	});

	event.waitUntil(
		track.then(function () {
			return self.clients.matchAll({ type: 'window', includeUncontrolled: true });
		}).then(function (windowClients) {
			for (var i = 0; i < windowClients.length; i++) {
				if (windowClients[i].url === targetUrl && 'focus' in windowClients[i]) {
					return windowClients[i].focus();
				}
			}
			if (self.clients.openWindow) {
				return self.clients.openWindow(targetUrl);
			}
			return null;
		})
	);
});

// The browser rotated the subscription: register the new one, drop the old.
self.addEventListener('pushsubscriptionchange', function (event) {
	if (!VAPID_KEY) {
		return;
	}
	event.waitUntil(
		self.registration.pushManager.subscribe({
			userVisibleOnly: true,
			applicationServerKey: b64uToBytes(VAPID_KEY)
		}).then(function (sub) {
			var tasks = [postJson(SUBSCRIBE_URL, {
				endpoint: sub.endpoint,
				p256dh: bytesToB64u(sub.getKey('p256dh')),
				auth: bytesToB64u(sub.getKey('auth'))
			})];
			if (event.oldSubscription && event.oldSubscription.endpoint) {
				tasks.push(postJson(UNSUBSCRIBE_URL, { endpoint: event.oldSubscription.endpoint }));
			}
			return Promise.all(tasks);
		}).catch(function () {
			return null;
		})
	);
});
JS;
	}
}
