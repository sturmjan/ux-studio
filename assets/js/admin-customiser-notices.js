(function() {
    'use strict';

    // Success messages ("Post updated.") and form feedback stay where they are; only real notices move into the tray.
    // A green notice counts as feedback only on a screen that is the RESULT of an action (the address carries the usual
    // result arguments: settings-updated, updated, message, activate, deleted ...). Without them a green notice is a
    // plugin's own message ("WP Rocket is good to go!", offers, tips) and moves into the tray like any other.
    // The UX Studio PHP version notice is critical and always stays on the page.
    var RESULT_ARG = /^(settings-)?updated$|saved|activate|deactivate|delete|trash|message|success|done|import|export|^ids$|locked|skipped|bulk_/i;
    function isResultScreen() {
        // PHP knows the original address (WordPress removes its result arguments from the address bar early).
        var fromPhp = window.uxstudioNoticesI18n && window.uxstudioNoticesI18n.resultScreen;
        if (fromPhp === true || fromPhp === '1') return true;
        try {
            var found = false;
            new URLSearchParams(window.location.search).forEach(function(value, key) {
                if (RESULT_ARG.test(key)) found = true;
            });
            return found;
        } catch (e) {
            return true;
        }
    }
    // `.inline` notices belong to the place where they are printed and are never moved: the "New version available"
    // line in a row of the Plugins/Themes list (`update-message notice inline`), messages inside forms, meta boxes and
    // list tables. Moving them into the tray emptied those rows (the update link vanished from the plugin list).
    var NOTICE_EXCLUDE_SELECTOR = ':not(#message):not(.below-h2):not(.inline):not(.update-message)' + (isResultScreen() ? ':not(.notice-success)' : '') + ':not(.settings-error):not(#uxstudio-php-version-notice)';
    // Containers whose notices stay where they are, whatever their classes.
    var KEEP_IN_PLACE = '.wp-list-table, .plugin-update-tr, .theme-overlay, .theme-browser, .postbox, .inside, form, #customize-controls, .media-modal, .edit-post-layout, .interface-interface-skeleton';
    var NOTICE_SELECTOR = [
        '.notice' + NOTICE_EXCLUDE_SELECTOR,
        '.error' + NOTICE_EXCLUDE_SELECTOR,
        '.updated' + NOTICE_EXCLUDE_SELECTOR,
        '.update-nag' + NOTICE_EXCLUDE_SELECTOR
    ].join(', ');

    var ACTIVE_BODY_CLASS = 'uxstudio-notices-active';
    var PANEL_ACTIVE_CLASS = 'uxstudio-notices-panel-active';
    var NEW_CLASS = 'has-new';

    var i18n = window.uxstudioNoticesI18n || {};
    // Present for administrators only: the REST address, the nonce, the admin screen and the notices the panel hides.
    var config = window.uxstudioNoticesConfig || null;
    var hiddenKeys = {};
    if (config && Array.isArray(config.hidden)) {
        config.hidden.forEach(function(k) { hiddenKeys[k] = true; });
    }

    var panel = document.getElementById('uxstudio-notices__panel');
    var panelWrap = document.getElementById('uxstudio-notices__panel-wrap');
    var toggleButton = document.getElementById('wp-admin-bar-uxstudio-notifications');

    if (!panel || !panelWrap || !toggleButton) {
        return;
    }

    var toggleLink = toggleButton.querySelector(':scope > .ab-item');
    var countBadge = toggleButton.querySelector('.uxstudio-notif-count');
    var headCount = panelWrap.querySelector('.uxstudio-nc-head__count');
    var lastCount = 0;
    var ringTimeout = null;
    var open = false;

    // The badge ring takes the colour of the bar, whatever the admin colour scheme is.
    var bar = document.getElementById('wpadminbar');
    if (bar) {
        toggleButton.style.setProperty('--uxs-bar-bg', window.getComputedStyle(bar).backgroundColor);
    }

    // The panel lives directly in <body> and is positioned under the bell: inside #wpadminbar every foreign notice
    // would inherit the bar's resets (floats, 32px line height, light-on-dark colours).
    document.body.appendChild(panelWrap);
    panel.classList.remove('hidden');
    if (toggleLink) {
        toggleLink.setAttribute('aria-haspopup', 'true');
        toggleLink.setAttribute('aria-expanded', 'false');
        toggleLink.setAttribute('aria-controls', 'uxstudio-notices__panel-wrap');
    }

    function text(key, fallback) {
        return i18n[key] || fallback;
    }

    function normalizeKey(el) {
        var text = (el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        return text.slice(0, 240);
    }

    function cards() {
        return Array.prototype.slice.call(panel.children);
    }

    function findUnclaimedNotices() {
        var all = document.querySelectorAll(NOTICE_SELECTOR);
        var out = [];
        all.forEach(function(el) {
            if (panelWrap.contains(el)) return;
            if (el.id && /^setting-error-/.test(el.id)) return;
            if (el.closest('#wpadminbar')) return;
            if (el.closest(KEEP_IN_PLACE)) return;
            // A notice inside another notice (a plugin's own sub-box) belongs to its parent card.
            if (el.parentElement && el.parentElement.closest(NOTICE_SELECTOR)) return;
            var content = (el.textContent || '').replace(/\s+/g, ' ').trim();
            if (!content) return;
            out.push(el);
        });
        return out;
    }

    /** error / warning / success / info, from the classes WordPress and plugins put on the original notice. */
    function noticeType(el) {
        var c = el.classList;
        if (c.contains('notice-error') || c.contains('error')) return 'error';
        if (c.contains('notice-warning') || c.contains('update-nag')) return 'warning';
        if (c.contains('notice-success') || c.contains('updated')) return 'success';
        return 'info';
    }

    // Core's button, Elementor's dismiss icon (an empty <i role="button">) and Freemius' "Dismiss" block (.fs-close,
    // used by many plugins that ship the Freemius SDK; its own script binds the AJAX dismissal to that element).
    var DISMISS = '.notice-dismiss, .e-notice__dismiss, .fs-close';
    // Any other plugin: a small button or link named like a dismissal ("Hide", "Dismiss", "Close" classes).
    var DISMISS_LIKE = /(^|[-_\s])(dismiss|close|hide)([-_\s]|$)/i;

    function dismissButton(el) {
        var known = el.querySelector(DISMISS);
        if (known) return known;
        var found = null;
        Array.prototype.some.call(el.querySelectorAll('a, button, [role="button"]'), function(node) {
            if (!DISMISS_LIKE.test(typeof node.className === 'string' ? node.className : '')) return false;
            if (node.textContent.trim().length > 25) return false;
            found = node;
            return true;
        });
        // Marked so the stylesheet can hide it: the card shows its own cross instead.
        if (found) found.setAttribute('data-uxs-dismiss', '1');
        return found;
    }

    function isDismissible(el) {
        return el.classList.contains('is-dismissible') || !!dismissButton(el);
    }

    function el(tag, className, label) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (label) node.textContent = label;
        return node;
    }

    var INTERACTIVE = 'a, button, input, select, textarea, [role="button"], br, hr';

    /**
     * Strip decoration and collapse what is left empty: logos and icons go, and so do the wrappers that held them
     * (they keep their height otherwise). A plugin's "dismiss" link that comes first (usually floated right) is moved
     * to the end, where it reads as an action instead of a stray line above the text.
     */
    function tidyNotice(notice) {
        Array.prototype.slice.call(notice.querySelectorAll('img, svg, picture, video, iframe, .dashicons, i:not([role])')).forEach(function(node) {
            if (!node.textContent.trim() || node.tagName === 'IMG' || node.tagName === 'SVG') node.remove();
        });

        var first = notice.firstElementChild;
        if (first && first.tagName !== 'P' && !/^H[1-6]$/.test(first.tagName) && !first.matches(DISMISS + ', [data-uxs-dismiss]')
            && first.querySelectorAll('a').length === 1 && first.textContent.trim().length < 60
            && (first.style.cssFloat === 'right' || /dismiss|hide|close/i.test(first.className))) {
            notice.appendChild(first);
        }

        // Bottom-up: an element with no text and nothing to click is dropped.
        var nodes = Array.prototype.slice.call(notice.querySelectorAll('*')).reverse();
        nodes.forEach(function(node) {
            if (!node.isConnected || node.matches(DISMISS + ', [data-uxs-dismiss]')) return;
            if (!node.textContent.trim() && !node.matches(INTERACTIVE) && !node.querySelector(INTERACTIVE)) node.remove();
        });
    }

    // ── History: what the panel captured is reported to the server (administrators only) ────────────────────────
    var DESCRIBE_DROP = DISMISS + ', [data-uxs-dismiss], script, style, noscript';
    var descByKey = {};
    var reported = {};
    var logSent = false;

    function plain(value, max) {
        var out = String(value || '').replace(/\s+/g, ' ').trim();
        return out.length > max ? out.slice(0, max) : out;
    }

    /** What the server needs to know about a notice: type, title, plain text and markup hints for the source guess. */
    function describe(notice, key) {
        var clone = notice.cloneNode(true);
        Array.prototype.forEach.call(clone.querySelectorAll(DESCRIBE_DROP), function(node) { node.remove(); });
        var body = plain(clone.textContent, 2000);

        var title = '';
        var titleNode = clone.querySelector('h1, h2, h3, h4, h5, h6, .fs-plugin-title, .notice-title, p:first-child > strong:first-child, strong:first-child');
        if (titleNode) {
            var candidate = plain(titleNode.textContent, 160);
            if (candidate && candidate.length < body.length && body.indexOf(candidate) === 0) {
                title = candidate;
                // The title is stored on its own: the text starts after it (the history would show it twice).
                body = body.slice(candidate.length).replace(/^[^\p{L}\p{N}]+/u, '') || body;
            }
        }

        var classes = {};
        var nodes = [notice].concat(Array.prototype.slice.call(notice.querySelectorAll('*'), 0, 40));
        nodes.forEach(function(node) {
            if (typeof node.className !== 'string') return;
            node.className.split(/\s+/).forEach(function(c) {
                if (c && c !== 'is-dismissible') classes[c] = true;
            });
        });

        var plugin = '';
        ['data-plugin', 'data-slug', 'data-plugin-slug'].some(function(attr) {
            var holder = notice.hasAttribute(attr) ? notice : notice.querySelector('[' + attr + ']');
            if (holder) plugin = holder.getAttribute(attr) || '';
            return !!plugin;
        });

        return {
            ckey: key,
            type: noticeType(notice),
            title: title,
            text: body,
            markup: Object.keys(classes).join(' ').slice(0, 1000),
            id: notice.id || '',
            plugin: plugin,
            screen: (config && config.screen) || '',
            dismissible: isDismissible(notice)
        };
    }

    function send(path, payload, keepalive) {
        if (!config || !config.restUrl || !window.fetch) return;
        var body = JSON.stringify(payload);
        try {
            window.fetch(config.restUrl + path, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: !!keepalive && body.length < 60000,
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: body
            }).catch(function() {});
        } catch (err) { /* history is best effort, the panel works without it */ }
    }

    /** One request per page load, a moment after the last capture pass; also when the page is left sooner. */
    function flushLog(keepalive) {
        if (logSent || !config) return;
        var items = [];
        cards().forEach(function(card) {
            var item = descByKey[card.getAttribute('data-key')];
            if (item && item.text) items.push(item);
        });
        if (!items.length) return;
        logSent = true;
        send('/log', { items: items.slice(0, 40) }, keepalive);
    }

    /** The user closed a notice (our cross, or the plugin's own control inside the card). */
    function reportDismiss(card, method) {
        if (!config) return;
        var key = card.getAttribute('data-key');
        var item = descByKey[key];
        if (!item || reported[key]) return;
        reported[key] = true;
        send('/dismiss', { item: item, method: method });
    }

    /**
     * Press the plugin's own dismiss control without leaving the page.
     *
     * Many plugins dismiss with a plain link (`?dismiss=...`) or a small form. A real click would navigate, i.e. reload
     * the screen. So the click is delivered (the plugin's script, if it listens to it, runs as usual), but the navigation
     * or form submission is cancelled here; when no script of the plugin handled the click (nothing called
     * preventDefault), the same address or form is sent in the background instead.
     */
    function runOriginalDismiss(original) {
        var handled = false;
        var guard = function(ev) {
            if (ev.target !== original && !(original.contains && original.contains(ev.target))) return;
            handled = ev.defaultPrevented;
            ev.preventDefault();
        };
        document.addEventListener('click', guard, false);
        try {
            original.click();
        } finally {
            document.removeEventListener('click', guard, false);
        }
        if (handled) return;

        var link = original.closest ? original.closest('a[href]') : null;
        if (link) {
            var href = link.getAttribute('href') || '';
            if (href.charAt(0) !== '#' && !/^\s*javascript:/i.test(href)) {
                backgroundRequest(link.href, { method: 'GET' });
            }
            return;
        }
        var form = original.form || (original.closest ? original.closest('form') : null);
        if (form && (original.tagName === 'BUTTON' || original.tagName === 'INPUT') && original.type !== 'button') {
            var method = (form.getAttribute('method') || 'GET').toUpperCase();
            var target = form.action || window.location.href;
            var data = null;
            try {
                data = new FormData(form, original);
            } catch (e) {
                data = new FormData(form);
            }
            if (method === 'GET') {
                var query = new URLSearchParams(data).toString();
                backgroundRequest(target + (target.indexOf('?') === -1 ? '?' : '&') + query, { method: 'GET' });
            } else {
                backgroundRequest(target, { method: 'POST', body: data });
            }
        }
    }

    /** Same-origin request in the background (cookies, no navigation); the answer is not needed. */
    function backgroundRequest(url, options) {
        try {
            var target = new URL(url, window.location.href);
            if (target.origin !== window.location.origin) return;
            var init = { method: options.method, credentials: 'same-origin', redirect: 'follow' };
            if (options.body) init.body = options.body;
            window.fetch(target.href, init).catch(function() {});
        } catch (e) {
            // Nothing to do: the card is removed anyway and the notice shows again if the plugin did not take it.
        }
    }

    function buildCard(notice, key) {
        if (config) descByKey[key] = describe(notice, key);
        tidyNotice(notice);
        var card = el('div', 'uxstudio-nc');
        card.setAttribute('role', 'listitem');
        card.setAttribute('data-type', noticeType(notice));
        card.setAttribute('data-key', key);

        var content = el('div', 'uxstudio-nc__content');
        content.appendChild(notice);
        card.appendChild(content);

        var more = el('button', 'uxstudio-nc__more', text('showMore', 'Show more'));
        more.type = 'button';
        more.hidden = true;
        more.setAttribute('aria-expanded', 'false');
        more.addEventListener('click', function() {
            var expanded = card.classList.toggle('is-expanded');
            more.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            more.textContent = expanded ? text('showLess', 'Show less') : text('showMore', 'Show more');
        });
        card.appendChild(more);

        var dismiss = el('button', 'uxstudio-nc__dismiss');
        dismiss.type = 'button';
        dismiss.setAttribute('aria-label', text('dismiss', 'Dismiss notice'));
        dismiss.title = text('dismiss', 'Dismiss notice');
        dismiss.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>';
        dismiss.hidden = !isDismissible(notice);
        dismiss.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            // Trigger the plugin's own dismiss button, so its AJAX call (remember the dismissal) still runs.
            var original = dismissButton(notice);
            if (original) {
                // The plugin keeps the dismissal itself: the history marks it as closed by the plugin.
                reportDismiss(card, 'plugin');
                runOriginalDismiss(original);
                // The plugin's script normally removes its notice (the card then goes away in cleanupPanel). If it does
                // not, or only calls the server, the card must not stay behind.
                window.setTimeout(function() {
                    if (card.isConnected) {
                        card.remove();
                        refresh(false);
                    }
                }, 1200);
            } else {
                // Only the panel hides it: the history remembers that and keeps it out of the panel until shown again.
                reportDismiss(card, 'panel');
                card.remove();
                refresh(false);
            }
        });
        card.appendChild(dismiss);

        return card;
    }

    /** Drop cards whose notice is gone (dismissed by the plugin's own script, or removed by the page). */
    function cleanupPanel() {
        cards().forEach(function(card) {
            var notice = card.querySelector('.uxstudio-nc__content > *');
            if (!notice || !normalizeKey(notice)) {
                card.remove();
                return;
            }
            // A notice that became dismissible after it was captured (core adds the button on DOM ready).
            var dismiss = card.querySelector('.uxstudio-nc__dismiss');
            if (dismiss) dismiss.hidden = !isDismissible(notice);
        });
    }

    function captureNotices() {
        var notices = findUnclaimedNotices();
        if (!notices.length) return 0;

        var byKey = {};
        cards().forEach(function(card) {
            byKey[card.getAttribute('data-key')] = card;
        });

        var moved = 0;
        notices.forEach(function(notice) {
            var key = normalizeKey(notice);
            if (!key) {
                notice.remove();
                return;
            }
            var card = byKey[key];
            if (card) {
                // Core and some plugins pull notices back into the page content after we took them (core moves every
                // notice below the page heading on DOM ready). A card that lost its notice gets it back; a real
                // duplicate is dropped.
                var content = card.querySelector('.uxstudio-nc__content');
                if (content && !content.firstElementChild) {
                    content.appendChild(notice);
                } else {
                    notice.remove();
                }
                return;
            }
            if (hiddenKeys[key]) {
                // Closed with the panel's cross earlier (see the history page): stays out until it is shown again.
                notice.remove();
                return;
            }
            card = buildCard(notice, key);
            byKey[key] = card;
            panel.appendChild(card);
            moved++;
        });

        if (moved > 0) {
            document.body.classList.add(ACTIVE_BODY_CLASS);
        }
        return moved;
    }

    /** Show the "Show more" toggle only on cards whose text really is longer than the three visible lines. */
    function measure() {
        if (!open) return;
        cards().forEach(function(card) {
            var content = card.querySelector('.uxstudio-nc__content');
            var more = card.querySelector('.uxstudio-nc__more');
            if (!content || !more) return;
            if (card.classList.contains('is-expanded')) {
                more.hidden = false;
                return;
            }
            more.hidden = content.scrollHeight <= content.clientHeight + 1;
        });
    }

    function updateCount(count, animate) {
        var label = count > 99 ? '99+' : String(count);
        if (countBadge) {
            countBadge.textContent = label;
        }
        if (headCount) {
            headCount.textContent = label;
        }
        toggleButton.classList.toggle('has-notices', count > 0);
        panelWrap.classList.toggle('is-empty', count === 0);

        if (animate && count > lastCount) {
            toggleButton.classList.remove(NEW_CLASS);
            void toggleButton.offsetWidth; // force reflow so the animation restarts.
            toggleButton.classList.add(NEW_CLASS);
            clearTimeout(ringTimeout);
            ringTimeout = setTimeout(function() {
                toggleButton.classList.remove(NEW_CLASS);
            }, 1300);
        }
        lastCount = count;
    }

    function refresh(animate) {
        var moved = captureNotices();
        cleanupPanel();
        updateCount(cards().length, animate && moved > 0);
        if (moved > 0) measure();
    }

    /** Right edge of the panel under the bell, top edge under the bar (the bar is taller on small screens). */
    function placePanel() {
        var bar = document.getElementById('wpadminbar');
        var barBottom = bar ? bar.getBoundingClientRect().bottom : 32;
        var rect = toggleButton.getBoundingClientRect();
        var right = Math.max(8, document.documentElement.clientWidth - rect.right);
        panelWrap.style.top = Math.round(barBottom + 6) + 'px';
        panelWrap.style.right = Math.round(right) + 'px';
    }

    function openPanel() {
        open = true;
        placePanel();
        document.body.classList.add(PANEL_ACTIVE_CLASS);
        toggleButton.classList.add('active');
        panelWrap.classList.add('is-open');
        if (toggleLink) toggleLink.setAttribute('aria-expanded', 'true');
        measure();
    }

    function closePanel() {
        open = false;
        panelWrap.classList.remove('is-open');
        document.body.classList.remove(PANEL_ACTIVE_CLASS);
        toggleButton.classList.remove('active');
        if (toggleLink) toggleLink.setAttribute('aria-expanded', 'false');
    }

    function togglePanel() {
        if (open) {
            closePanel();
        } else {
            openPanel();
        }
    }

    if (toggleLink) {
        toggleLink.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            togglePanel();
        });
    }

    document.addEventListener('click', function(e) {
        if (!open) return;
        if (e.target.closest('#uxstudio-notices__panel-wrap, #wp-admin-bar-uxstudio-notifications')) return;
        closePanel();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && open) {
            closePanel();
            if (toggleLink) toggleLink.focus();
        }
    });

    window.addEventListener('resize', function() {
        if (!open) return;
        placePanel();
        measure();
    });

    function observeNotices() {
        new MutationObserver(function(mutations) {
            var changed = mutations.some(function(m) {
                return m.addedNodes.length || m.removedNodes.length;
            });
            if (!changed) return;
            captureNotices();
            cleanupPanel();
            var total = cards().length;
            if (total !== lastCount) {
                updateCount(total, total > lastCount);
                measure();
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    // A click on a plugin's own dismiss control inside a card (not the synthetic one of our cross, which reports itself).
    panel.addEventListener('click', function(e) {
        if (!e.isTrusted || !config) return;
        var control = e.target.closest ? e.target.closest(DISMISS + ', [data-uxs-dismiss]') : null;
        if (!control) return;
        var card = control.closest('.uxstudio-nc');
        if (card) reportDismiss(card, 'plugin');
    });

    function start() {
        refresh(false);
        setTimeout(function() { refresh(false); }, 300);
        setTimeout(function() { refresh(false); }, 1500);
        setTimeout(function() { flushLog(false); }, 2200);
        window.addEventListener('pagehide', function() { flushLog(true); });
        observeNotices();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
