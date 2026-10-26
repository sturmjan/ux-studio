/**
 * Quick Search: one merged search control in the admin bar.
 *
 * A magnifier on the right side of the bar. A click (or Ctrl+K / Cmd+K in wp-admin) expands an inline field, typing
 * shows a combobox dropdown with the results of the REST search, and a last entry opens the WordPress command palette
 * (which stays reachable, but its own admin-bar button is gone and its Ctrl+K shortcut is taken over here).
 */
(function() {
    'use strict';

    // On the front end WordPress prints the admin bar markup in wp_footer, AFTER this footer script has run, so the
    // control may not exist yet: wait for the DOM then.
    if (document.querySelector('.uxstudio-qs')) {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            if (document.querySelector('.uxstudio-qs')) {
                start();
            }
        });
    }

    function start() {

    // ---------------------------------------------------------------------------
    // Configuration & early exit
    // ---------------------------------------------------------------------------

    var cfg = window.uxstudioQuickSearch;
    if (!cfg || !cfg.restUrl) {
        return;
    }

    var root = document.querySelector('.uxstudio-qs');
    if (!root) {
        return;
    }

    var i18n     = window.uxstudioQuickSearchI18n || {};
    var toggle   = root.querySelector('.uxstudio-qs__toggle');
    var field    = root.querySelector('.uxstudio-qs__field');
    var input    = root.querySelector('.uxstudio-qs__input');
    var spinner  = root.querySelector('.uxstudio-qs__spinner');
    var panel    = root.querySelector('.uxstudio-qs__panel-container');
    var list     = root.querySelector('.uxstudio-qs__panel');
    var message  = root.querySelector('.uxstudio-qs__message');

    if (!toggle || !input || !list) {
        return;
    }

    var DEBOUNCE_MS = 250;
    var debounceTimer = null;
    var activeController = null;
    var options = [];
    var activeIndex = -1;
    var lastGroups = null;
    var optionSeq = 0;

    function text(key, fallback) {
        return i18n[key] || fallback;
    }

    // ---------------------------------------------------------------------------
    // Colours: follow the admin colour scheme (light, dark, custom) of the bar itself
    // ---------------------------------------------------------------------------

    function isOpaque(color) {
        if (!color || color === 'transparent') return false;
        var m = /^rgba\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*,\s*([\d.]+)\s*\)$/.exec(color);
        return !m || parseFloat(m[1]) >= 0.95;
    }

    function syncColors() {
        var bar = document.getElementById('wpadminbar');
        if (!bar) return;
        var bg = window.getComputedStyle(bar).backgroundColor;
        var item = bar.querySelector('.ab-top-menu > li > .ab-item') || bar;
        root.style.setProperty('--uxs-qs-bg', isOpaque(bg) ? bg : '#1d2327');
        root.style.setProperty('--uxs-qs-fg', window.getComputedStyle(item).color || '#f0f0f1');
    }

    syncColors();

    // ---------------------------------------------------------------------------
    // Keyboard shortcut label (the Apple key on Macs)
    // ---------------------------------------------------------------------------

    var isApple = /Macintosh|Mac OS X|Mac_PowerPC/i.test(navigator.userAgent);
    var shortcutLabel = isApple ? '⌘K' : 'Ctrl+K';
    var searchLabel = text('search', 'Search');
    toggle.setAttribute('title', cfg.isAdmin ? searchLabel + ' (' + shortcutLabel + ')' : searchLabel);

    // ---------------------------------------------------------------------------
    // WordPress command palette
    // ---------------------------------------------------------------------------

    function commandsStore() {
        try {
            var store = window.wp && window.wp.data && window.wp.data.dispatch && window.wp.data.dispatch('core/commands');
            return store && typeof store.open === 'function' ? store : null;
        } catch (err) {
            return null;
        }
    }

    function openPalette() {
        var store = commandsStore();
        if (store) {
            collapse(true);
            store.open();
        }
    }

    // ---------------------------------------------------------------------------
    // Collapse / expand
    // ---------------------------------------------------------------------------

    function isExpanded() {
        return root.getAttribute('data-collapsed') === 'false';
    }

    /**
     * The field sits in the flow of the bar, so it must fit into the gap between the left-hand items and the right-hand
     * group instead of covering them. When the gap is too small the server clock and then the user name (both purely
     * informative) step aside while the field is open; on a really narrow bar the field floats over it instead; whatever room is left becomes the field width.
     */
    function fitField() {
        var bar = document.getElementById('wpadminbar');
        root.style.removeProperty('--uxs-qs-w');
        if (bar) bar.classList.remove('uxs-qs-tight', 'uxs-qs-tighter', 'uxs-qs-overlay');
        if (!bar || !field || (window.matchMedia && window.matchMedia('(max-width: 782px)').matches)) return;
        var primary = document.getElementById('wp-admin-bar-root-default');
        var secondary = document.getElementById('wp-admin-bar-top-secondary');
        if (!primary || !secondary) return;
        var current = field.getBoundingClientRect().width; // the room the field itself already takes
        // The list itself may be stretched to the full bar width: its last visible item tells where the content ends.
        function primaryEnd() {
            var end = 0;
            for (var i = 0; i < primary.children.length; i++) {
                var box = primary.children[i].getBoundingClientRect();
                if (box.width > 0) end = Math.max(end, box.right);
            }
            return end;
        }
        function room() {
            return Math.floor(secondary.getBoundingClientRect().left + current - primaryEnd() - 12);
        }
        if (room() < 200) bar.classList.add('uxs-qs-tight');
        if (room() < 200) bar.classList.add('uxs-qs-tighter');
        if (room() < 140) {
            // A really narrow bar: no room to push anything aside, so the field floats over the bar like a popover
            // (pushing would drop the right-hand group below the bar).
            bar.classList.remove('uxs-qs-tight', 'uxs-qs-tighter');
            bar.classList.add('uxs-qs-overlay');
            return;
        }
        root.style.setProperty('--uxs-qs-w', Math.min(280, room()) + 'px');
    }

    function expand() {
        syncColors();
        fitField();
        root.setAttribute('data-collapsed', 'false');
        toggle.setAttribute('aria-expanded', 'true');
        input.focus();
        input.select();
        if (input.value.trim().length >= 2 && lastGroups) {
            renderGroups(lastGroups);
        } else {
            renderIdle();
        }
    }

    function collapse(keepFocus) {
        var hadFocus = root.contains(document.activeElement);
        clearTimeout(debounceTimer);
        if (activeController && typeof activeController.abort === 'function') {
            activeController.abort();
        }
        if (spinner) spinner.hidden = true;
        root.setAttribute('data-collapsed', 'true');
        toggle.setAttribute('aria-expanded', 'false');
        var bar = document.getElementById('wpadminbar');
        if (bar) bar.classList.remove('uxs-qs-tight', 'uxs-qs-tighter', 'uxs-qs-overlay');
        input.value = '';
        lastGroups = null;
        hidePanel();
        if (hadFocus && !keepFocus) {
            toggle.focus();
        }
    }

    toggle.addEventListener('click', function() {
        if (isExpanded()) {
            collapse();
        } else {
            expand();
        }
    });

    document.addEventListener('mousedown', function(e) {
        if (isExpanded() && !root.contains(e.target)) {
            collapse(true);
        }
    });

    // Keep the focus in the field while the pointer works on the dropdown (scrollbar, rows).
    if (panel) {
        panel.addEventListener('mousedown', function(e) {
            if (e.target.tagName !== 'A') {
                e.preventDefault();
            }
        });
    }

    // Ctrl+K / Cmd+K in wp-admin: capture phase, so the core shortcut (bound on the document) never sees it.
    window.addEventListener('keydown', function(e) {
        if (!cfg.isAdmin || !(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey) return;
        if ((e.key || '').toLowerCase() !== 'k') return;
        // Not where the bar is hidden (the block editor uses Ctrl+K for links and has no admin bar).
        if (!root.getClientRects().length || document.body.classList.contains('block-editor-page')) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        if (isExpanded()) {
            input.focus();
            input.select();
        } else {
            expand();
        }
    }, true);

    // ---------------------------------------------------------------------------
    // Panel + options
    // ---------------------------------------------------------------------------

    /** The dropdown is right-aligned to the control; when it would stick out on the left, slide it back in. */
    function fitPanel() {
        if (!panel) return;
        panel.style.right = '';
        // Small screens: the CSS stretches it across the whole bar.
        if (window.matchMedia && window.matchMedia('(max-width: 782px)').matches) return;
        var left = panel.getBoundingClientRect().left;
        if (left < 8) {
            panel.style.right = (left - 8) + 'px';
        }
    }

    window.addEventListener('resize', function() {
        if (isExpanded()) fitField();
        if (panel && panel.classList.contains('is-open')) fitPanel();
    });

    function showPanel() {
        if (panel) {
            panel.classList.add('is-open');
            fitPanel();
        }
        input.setAttribute('aria-expanded', 'true');
    }

    function hidePanel() {
        if (panel) panel.classList.remove('is-open');
        input.setAttribute('aria-expanded', 'false');
        setActive(-1);
    }

    function clearList() {
        list.innerHTML = '';
        options = [];
        activeIndex = -1;
        input.removeAttribute('aria-activedescendant');
    }

    function setMessage(msg) {
        if (!message) return;
        message.textContent = msg || '';
        message.classList.toggle('screen-reader-text', !msg);
    }

    function setActive(index) {
        if (activeIndex >= 0 && options[activeIndex]) {
            options[activeIndex].el.classList.remove('is-active');
            options[activeIndex].el.setAttribute('aria-selected', 'false');
        }
        activeIndex = index;
        if (index >= 0 && options[index]) {
            var el = options[index].el;
            el.classList.add('is-active');
            el.setAttribute('aria-selected', 'true');
            input.setAttribute('aria-activedescendant', el.id);
            if (el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function newOption(el, action) {
        el.id = 'uxstudio-qs-opt-' + (++optionSeq);
        el.setAttribute('role', 'option');
        el.setAttribute('aria-selected', 'false');
        var index = options.length;
        options.push({ el: el, action: action });
        el.addEventListener('mousemove', function() {
            if (activeIndex !== index) setActive(index);
        });
        el.addEventListener('click', function(e) {
            if (e.target.tagName === 'A') return; // a chip keeps its own link
            activate(index, e);
        });
        return el;
    }

    function addCommandsOption() {
        if (!commandsStore()) return;
        var item = document.createElement('li');
        item.className = 'uxstudio-qs__row uxstudio-qs__row--commands';
        var label = document.createElement('span');
        label.className = 'uxstudio-qs__row-label';
        label.textContent = text('commands', 'WordPress commands…');
        item.appendChild(label);
        if (cfg.isAdmin) {
            var kbd = document.createElement('kbd');
            kbd.className = 'uxstudio-qs__kbd';
            kbd.textContent = shortcutLabel;
            item.appendChild(kbd);
        }
        list.appendChild(newOption(item, { type: 'commands' }));
    }

    /** Nothing typed yet: only the way into the WordPress command palette, if there is one. */
    function renderIdle() {
        clearList();
        setMessage('');
        addCommandsOption();
        if (options.length) {
            showPanel();
            setActive(-1);
        } else {
            hidePanel();
        }
    }

    function renderGroups(groups) {
        lastGroups = groups;
        clearList();

        var keys = Object.keys(groups || {});
        if (!keys.length) {
            setMessage(text('noResults', 'No results.'));
        } else {
            setMessage('');
        }

        keys.forEach(function(groupLabel) {
            var heading = document.createElement('li');
            heading.className = 'uxstudio-qs__group-heading';
            heading.setAttribute('role', 'presentation');
            heading.textContent = groupLabel;
            list.appendChild(heading);

            (groups[groupLabel] || []).forEach(function(row) {
                var item = document.createElement('li');
                item.className = 'uxstudio-qs__row';

                var label = document.createElement('span');
                label.className = 'uxstudio-qs__row-label';
                label.textContent = row.label || '';
                item.appendChild(label);

                if (row.type) {
                    var type = document.createElement('span');
                    type.className = 'uxstudio-qs__row-type';
                    type.textContent = row.type;
                    item.appendChild(type);
                }

                var links = document.createElement('span');
                links.className = 'uxstudio-qs__row-links';
                (row.links || []).forEach(function(link) {
                    var a = document.createElement('a');
                    a.href = link.url || '#';
                    a.textContent = link.label || '';
                    a.tabIndex = -1;
                    links.appendChild(a);
                });
                item.appendChild(links);

                list.appendChild(newOption(item, { type: 'link', url: (row.links && row.links[0] && row.links[0].url) || '' }));
            });
        });

        addCommandsOption();

        if (!keys.length) {
            // The "no results" message needs a visible home even without a command entry.
            var empty = document.createElement('li');
            empty.className = 'uxstudio-qs__empty';
            empty.setAttribute('role', 'presentation');
            empty.textContent = text('noResults', 'No results.');
            list.insertBefore(empty, list.firstChild);
        }

        showPanel();
        setActive(options.length && keys.length ? 0 : -1);
    }

    function activate(index, event) {
        var opt = options[index];
        if (!opt) return;
        if (opt.action.type === 'commands') {
            openPalette();
            return;
        }
        if (!opt.action.url) return;
        if (event && (event.ctrlKey || event.metaKey)) {
            window.open(opt.action.url, '_blank', 'noopener');
            return;
        }
        window.location.href = opt.action.url;
    }

    // ---------------------------------------------------------------------------
    // Keyboard inside the field
    // ---------------------------------------------------------------------------

    input.addEventListener('keydown', function(e) {
        var count = options.length;
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (!panel.classList.contains('is-open')) {
                    if (lastGroups) { renderGroups(lastGroups); } else { renderIdle(); }
                    return;
                }
                if (count) setActive(activeIndex + 1 >= count ? 0 : activeIndex + 1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                if (count) setActive(activeIndex <= 0 ? count - 1 : activeIndex - 1);
                break;
            case 'Home':
                if (count && panel.classList.contains('is-open') && activeIndex >= 0) {
                    e.preventDefault();
                    setActive(0);
                }
                break;
            case 'End':
                if (count && panel.classList.contains('is-open') && activeIndex >= 0) {
                    e.preventDefault();
                    setActive(count - 1);
                }
                break;
            case 'Enter':
                e.preventDefault();
                if (activeIndex >= 0) {
                    activate(activeIndex, e);
                }
                break;
            case 'Escape':
                e.preventDefault();
                e.stopPropagation();
                collapse();
                break;
            case 'Tab':
                collapse(true);
                break;
        }
    });

    // Escape also works when the focus sits on the toggle or a row.
    root.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isExpanded() && e.target !== input) {
            e.preventDefault();
            collapse();
        }
    });

    // ---------------------------------------------------------------------------
    // Fetch (debounced, cancels the in-flight request on every new keystroke)
    // ---------------------------------------------------------------------------

    function runSearch(term) {
        if (activeController && typeof activeController.abort === 'function') {
            activeController.abort();
        }

        if (typeof AbortController !== 'undefined') {
            activeController = new AbortController();
        }

        if (spinner) spinner.hidden = false;

        var url = cfg.restUrl + (cfg.restUrl.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(term);

        fetch(url, {
            credentials: 'same-origin',
            signal: activeController ? activeController.signal : undefined,
            headers: { 'X-WP-Nonce': cfg.nonce || '' }
        })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error('quick-search request failed');
                }
                return res.json();
            })
            .then(function(json) {
                if (input.value.trim() !== term) return; // the user typed on meanwhile
                renderGroups(json && json.data ? json.data : {});
            })
            .catch(function(err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
                clearList();
                setMessage(text('error', 'Search failed.'));
                var failed = document.createElement('li');
                failed.className = 'uxstudio-qs__empty';
                failed.setAttribute('role', 'presentation');
                failed.textContent = text('error', 'Search failed.');
                list.appendChild(failed);
                addCommandsOption();
                showPanel();
            })
            .finally(function() {
                if (spinner) spinner.hidden = true;
            });
    }

    input.addEventListener('input', function() {
        var term = input.value.trim();

        clearTimeout(debounceTimer);

        if (term.length < 2) {
            lastGroups = null;
            renderIdle();
            return;
        }

        debounceTimer = setTimeout(function() {
            runSearch(term);
        }, DEBOUNCE_MS);
    });

    input.addEventListener('focus', function() {
        if (isExpanded() && !panel.classList.contains('is-open')) {
            if (input.value.trim().length >= 2 && lastGroups) {
                renderGroups(lastGroups);
            } else {
                renderIdle();
            }
        }
    });
    }
})();
