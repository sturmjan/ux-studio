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
    var lastMeta = {};
    var lastTerm = '';
    var optionSeq = 0;

    function text(key, fallback) {
        return i18n[key] || fallback;
    }

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
        fitField();
        root.setAttribute('data-collapsed', 'false');
        toggle.setAttribute('aria-expanded', 'true');
        input.focus();
        input.select();
        if (input.value.trim().length >= 2 && lastGroups) {
            renderGroups(lastGroups, lastMeta, lastTerm);
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
        lastMeta = {};
        lastTerm = '';
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
            if (!e.target.closest('a')) {
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
        // The footer lives outside the scrolling list (a real row below it), so it is removed separately.
        var footer = panel && panel.querySelector('.uxstudio-qs__row--commands');
        if (footer) footer.remove();
        list.removeAttribute('aria-owns');
        options = [];
        activeIndex = -1;
        input.removeAttribute('aria-activedescendant');
    }

    function setMessage(msg) {
        if (!message) return;
        message.textContent = msg || '';
        // Announced to screen readers only: the visible "no results" text is the row in the list.
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

    function newOption(el, action, label) {
        el.id = 'uxstudio-qs-opt-' + (++optionSeq);
        el.setAttribute('role', 'option');
        el.setAttribute('aria-selected', 'false');
        if (label) el.setAttribute('aria-label', label);
        var index = options.length;
        options.push({ el: el, action: action });
        el.addEventListener('mousemove', function() {
            if (activeIndex !== index) setActive(index);
        });
        el.addEventListener('click', function(e) {
            if (e.target.closest('a')) return; // an icon button keeps its own link
            activate(index, e);
        });
        return el;
    }

    function addCommandsOption() {
        if (!commandsStore()) return;
        var item = document.createElement('div');
        item.className = 'uxstudio-qs__row uxstudio-qs__row--commands wp-exclude-emoji';
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
        newOption(item, { type: 'commands' }, text('commands', 'WordPress commands…'));
        // Outside the <ul> so nothing scrolls under it; aria-owns keeps it a logical option of the listbox.
        (panel || list).appendChild(item);
        list.setAttribute('aria-owns', item.id);
    }

    /** Nothing typed yet: only the way into the WordPress command palette, if there is one. */
    function renderIdle() {
        clearList();
        list.removeAttribute('aria-busy');
        setMessage('');
        addCommandsOption();
        if (options.length) {
            showPanel();
            setActive(-1);
        } else {
            hidePanel();
        }
    }

    // Small inline icons (24px grid, stroke = currentColor). Static strings, never built from search results.
    var ICONS = {
        post:    '<path d="M6 3h9l4 4v14H6z"/><path d="M9 11h7M9 15h7M9 7h3"/>',
        page:    '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/>',
        product: '<path d="M5 8h14l-1 12H6z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
        cpt:     '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        media:   '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m21 16-5-5-8 8"/>',
        user:    '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        term:    '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1"/>',
        module:  '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        link:    '<path d="M5 12h14M13 6l6 6-6 6"/>',
        search:  '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        edit:    '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        view:    '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        open:    '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>'
    };

    function icon(name, size) {
        var wrap = document.createElement('span');
        wrap.innerHTML = '<svg viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + (ICONS[name] || ICONS.link) + '</svg>';
        return wrap.firstChild;
    }

    /** Title as text nodes with the query wrapped in <mark>; nothing is ever parsed as HTML. */
    function highlight(target, str, term) {
        target.textContent = '';
        var needle = (term || '').toLowerCase();
        if (!needle) {
            target.textContent = str;
            return;
        }
        var hay = str.toLowerCase();
        var pos = 0;
        var at;
        while ((at = hay.indexOf(needle, pos)) !== -1) {
            if (at > pos) target.appendChild(document.createTextNode(str.slice(pos, at)));
            var mark = document.createElement('mark');
            mark.textContent = str.slice(at, at + needle.length);
            target.appendChild(mark);
            pos = at + needle.length;
        }
        if (pos < str.length) target.appendChild(document.createTextNode(str.slice(pos)));
    }

    function actionLabel(action) {
        if (action === 'edit') return text('edit', 'Edit');
        if (action === 'view') return text('view', 'View');
        return text('open', 'Open');
    }

    function renderRow(row, term) {
        var links = (row.links || []).filter(function(l) { return l && l.url; });
        var edit = links.filter(function(l) { return l.action === 'edit'; })[0];
        var primary = edit || links[0];
        var secondary = links.filter(function(l) { return l !== primary; })[0];

        var item = document.createElement('li');
        item.className = 'uxstudio-qs__row wp-exclude-emoji';

        var lead = document.createElement('span');
        lead.className = 'uxstudio-qs__lead';
        if (row.thumb) {
            var img = document.createElement('img');
            img.className = 'uxstudio-qs__thumb' + (row.kind === 'user' ? ' is-round' : '');
            img.src = row.thumb;
            img.alt = '';
            img.loading = 'lazy';
            img.addEventListener('error', function() {
                lead.textContent = '';
                lead.appendChild(icon(row.kind, 16));
            });
            lead.appendChild(img);
        } else {
            lead.appendChild(icon(row.kind, 16));
        }
        item.appendChild(lead);

        var title = document.createElement('span');
        title.className = 'uxstudio-qs__row-label wp-exclude-emoji';
        highlight(title, row.label || '', term);
        item.appendChild(title);

        if (row.meta) {
            var meta = document.createElement('span');
            meta.className = 'uxstudio-qs__row-meta';
            meta.textContent = row.meta;
            item.appendChild(meta);
        }

        var actions = document.createElement('span');
        actions.className = 'uxstudio-qs__actions';
        links.forEach(function(link) {
            var a = document.createElement('a');
            var kind = link.action || 'open';
            a.className = 'uxstudio-qs__act';
            a.href = link.url;
            a.tabIndex = -1;
            a.setAttribute('aria-label', link.label || actionLabel(kind));
            a.title = link.label || actionLabel(kind);
            a.appendChild(icon(kind, 15));
            actions.appendChild(a);
        });
        item.appendChild(actions);

        var label = (row.label || '') + (row.meta ? ' (' + row.meta + ')' : '');
        list.appendChild(newOption(item, {
            type: 'link',
            url: primary ? primary.url : '',
            alt: secondary ? secondary.url : ''
        }, label));
    }

    function renderGroups(groups, meta, term) {
        lastGroups = groups;
        lastMeta = meta || {};
        lastTerm = term || '';
        clearList();
        list.removeAttribute('aria-busy');

        var keys = Object.keys(groups || {});
        if (!keys.length) {
            setMessage(text('noResults', 'No results.'));
        } else {
            setMessage('');
        }

        keys.forEach(function(groupLabel) {
            var rows = groups[groupLabel] || [];
            var info = lastMeta[groupLabel] || null;
            var total = info && info.total ? info.total : rows.length;

            var heading = document.createElement('li');
            heading.className = 'uxstudio-qs__group-heading wp-exclude-emoji';
            heading.setAttribute('role', 'presentation');
            var name = document.createElement('span');
            name.textContent = groupLabel;
            var count = document.createElement('span');
            count.className = 'uxstudio-qs__group-count';
            count.textContent = String(total);
            heading.appendChild(name);
            heading.appendChild(count);
            list.appendChild(heading);

            rows.forEach(function(row) { renderRow(row, lastTerm); });

            if (info && info.url && info.total > rows.length) {
                var more = document.createElement('li');
                more.className = 'uxstudio-qs__row uxstudio-qs__row--more';
                var moreLabel = document.createElement('span');
                moreLabel.className = 'uxstudio-qs__row-label';
                moreLabel.textContent = text('showAll', 'Show all (%d)').replace('%d', String(info.total));
                more.appendChild(moreLabel);
                more.appendChild(icon('link', 14));
                list.appendChild(newOption(more, { type: 'link', url: info.url }, moreLabel.textContent + ' - ' + groupLabel));
            }
        });

        if (!keys.length) {
            // The "no results" message needs a visible home even without a command entry.
            var empty = document.createElement('li');
            empty.className = 'uxstudio-qs__empty';
            empty.setAttribute('role', 'presentation');
            var emptyIcon = icon('search', 22);
            emptyIcon.setAttribute('class', 'uxstudio-qs__empty-icon');
            empty.appendChild(emptyIcon);
            empty.appendChild(document.createTextNode(text('noResults', 'No results.')));
            list.appendChild(empty);
        }

        addCommandsOption();

        showPanel();
        setActive(options.length && keys.length ? 0 : -1);
    }

    /** Skeleton rows while the first answer is on its way. */
    function renderLoading() {
        clearList();
        list.setAttribute('aria-busy', 'true');
        setMessage(text('searching', 'Searching...'));
        for (var i = 0; i < 4; i++) {
            var skel = document.createElement('li');
            skel.className = 'uxstudio-qs__skeleton';
            skel.setAttribute('role', 'presentation');
            var dot = document.createElement('span');
            dot.className = 'uxstudio-qs__skeleton-dot';
            var bar = document.createElement('span');
            bar.className = 'uxstudio-qs__skeleton-bar';
            bar.style.width = (38 + ((i * 23) % 40)) + '%';
            skel.appendChild(dot);
            skel.appendChild(bar);
            list.appendChild(skel);
        }
        addCommandsOption();
        showPanel();
        setActive(-1);
    }

    function activate(index, event) {
        var opt = options[index];
        if (!opt) return;
        if (opt.action.type === 'commands') {
            openPalette();
            return;
        }
        // Shift+Enter: the second action of the row (View when the primary one is Edit).
        var url = event && event.shiftKey && opt.action.alt ? opt.action.alt : opt.action.url;
        if (!url) return;
        if (event && (event.ctrlKey || event.metaKey)) {
            window.open(url, '_blank', 'noopener');
            return;
        }
        window.location.href = url;
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
                    if (lastGroups) { renderGroups(lastGroups, lastMeta, lastTerm); } else { renderIdle(); }
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
        // No results on screen yet: skeleton rows; otherwise the old ones stay until the new answer replaces them.
        if (!list.querySelector('.uxstudio-qs__row:not(.uxstudio-qs__row--commands)')) {
            renderLoading();
        }

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
                renderGroups(json && json.data ? json.data : {}, json && json.meta && json.meta.groups ? json.meta.groups : {}, term);
            })
            .catch(function(err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
                clearList();
                list.removeAttribute('aria-busy');
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
                renderGroups(lastGroups, lastMeta, lastTerm);
            } else {
                renderIdle();
            }
        }
    });
    }
})();
