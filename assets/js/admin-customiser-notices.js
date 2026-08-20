(function() {
    'use strict';

    var NOTICE_EXCLUDE_SELECTOR = ':not(#message):not(.below-h2):not(.notice-success):not(.settings-error)';
    var NOTICE_SELECTOR = [
        '.notice' + NOTICE_EXCLUDE_SELECTOR,
        '.error' + NOTICE_EXCLUDE_SELECTOR,
        '.updated' + NOTICE_EXCLUDE_SELECTOR,
        '.update-nag' + NOTICE_EXCLUDE_SELECTOR
    ].join(', ');

    var ACTIVE_BODY_CLASS = 'uxstudio-notices-active';
    var PANEL_ACTIVE_CLASS = 'uxstudio-notices-panel-active';
    var NEW_CLASS = 'has-new';

    var panel = document.getElementById('uxstudio-notices__panel');
    var panelWrap = document.getElementById('uxstudio-notices__panel-wrap');
    var toggleButton = document.getElementById('wp-admin-bar-uxstudio-notifications');

    if (!panel || !panelWrap || !toggleButton) {
        return;
    }

    var toggleLink = toggleButton.querySelector(':scope > .ab-item');
    var countBadge = toggleButton.querySelector('.uxstudio-notif-count');
    var lastCount = 0;
    var ringTimeout = null;
    var open = false;

    // Move the panel wrapper into the bell button's <li> so it anchors below it.
    toggleButton.appendChild(panelWrap);
    panel.classList.remove('hidden');

    function normalizeKey(el) {
        var text = (el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        return text.slice(0, 240);
    }

    function findUnclaimedNotices() {
        var all = document.querySelectorAll(NOTICE_SELECTOR);
        var out = [];
        all.forEach(function(el) {
            if (panel.contains(el)) return;
            if (el.id && /^setting-error-/.test(el.id)) return;
            if (el.closest('#wpadminbar')) return;
            var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
            if (!text) return;
            out.push(el);
        });
        return out;
    }

    function flattenInline(el) {
        var totalLen = (el.textContent || '').trim().length;
        if (totalLen > 300) {
            return;
        }

        var dismiss = el.querySelector('.notice-dismiss');
        if (dismiss) dismiss.parentNode.removeChild(dismiss);

        var html = el.innerHTML || '';
        html = html
            .replace(/<\/p\s*>\s*<p[^>]*>/gi, ' ')
            .replace(/<\/?p[^>]*>/gi, '')
            .replace(/<br\s*\/?>/gi, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        el.innerHTML = html;
        if (dismiss) el.appendChild(dismiss);
    }

    function cleanupPanel() {
        Array.prototype.slice.call(panel.children).forEach(function(el) {
            if (!normalizeKey(el)) el.remove();
        });
    }

    function captureNotices() {
        var notices = findUnclaimedNotices();
        if (!notices.length) return 0;

        var seen = {};
        Array.prototype.slice.call(panel.children).forEach(function(el) {
            seen[normalizeKey(el)] = true;
        });

        var moved = 0;
        notices.forEach(function(el) {
            var key = normalizeKey(el);
            if (!key || seen[key]) {
                el.remove();
                return;
            }
            seen[key] = true;
            flattenInline(el);
            el.remove();
            panel.appendChild(el);
            moved++;
        });

        if (moved > 0) {
            document.body.classList.add(ACTIVE_BODY_CLASS);
        }
        return moved;
    }

    function updateCount(count, animate) {
        if (countBadge) {
            countBadge.textContent = count > 99 ? '99+' : String(count);
        }
        toggleButton.classList.toggle('has-notices', count > 0);

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

    function runCapture(animate) {
        var moved = captureNotices();
        cleanupPanel();
        var total = panel.children.length;
        updateCount(total, animate && moved > 0);
    }

    function openPanel() {
        open = true;
        document.body.classList.add(PANEL_ACTIVE_CLASS);
        toggleButton.classList.add('active');
        panelWrap.classList.add('is-open');
    }

    function closePanel() {
        open = false;
        panelWrap.classList.remove('is-open');
        document.body.classList.remove(PANEL_ACTIVE_CLASS);
        toggleButton.classList.remove('active');
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
        if (e.key === 'Escape' && open) closePanel();
    });

    function observeNotices() {
        new MutationObserver(function(mutations) {
            var changed = mutations.some(function(m) {
                return m.addedNodes.length || m.removedNodes.length;
            });
            if (!changed) return;
            captureNotices();
            cleanupPanel();
            var total = panel.children.length;
            if (total !== lastCount) {
                updateCount(total, total > lastCount);
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    function start() {
        runCapture(false);
        setTimeout(function() { runCapture(false); }, 300);
        setTimeout(function() { runCapture(false); }, 1500);
        observeNotices();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
