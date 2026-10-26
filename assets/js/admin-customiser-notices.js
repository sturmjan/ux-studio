(function() {
    'use strict';

    // Success messages ("Post updated.") and form feedback stay where they are; only real notices move into the tray.
    // The UX Studio PHP version notice is critical and always stays on the page.
    var NOTICE_EXCLUDE_SELECTOR = ':not(#message):not(.below-h2):not(.notice-success):not(.settings-error):not(#uxstudio-php-version-notice)';
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

    function dismissButton(el) {
        return el.querySelector('.notice-dismiss');
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

    function buildCard(notice, key) {
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
                original.click();
            } else {
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

    function start() {
        refresh(false);
        setTimeout(function() { refresh(false); }, 300);
        setTimeout(function() { refresh(false); }, 1500);
        observeNotices();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
