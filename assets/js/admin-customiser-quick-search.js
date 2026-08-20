(function() {
    'use strict';

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

    var toggle   = root.querySelector('.uxstudio-qs__toggle');
    var wrapper  = root.querySelector('.uxstudio-qs__wrapper');
    var input    = root.querySelector('.uxstudio-qs__input');
    var spinner  = root.querySelector('.uxstudio-qs__spinner');
    var panel    = root.querySelector('.uxstudio-qs__panel-container');
    var list     = root.querySelector('.uxstudio-qs__panel');
    var message  = root.querySelector('.uxstudio-qs__message');

    if (!input || !list) {
        return;
    }

    var DEBOUNCE_MS = 250;
    var debounceTimer = null;
    var activeController = null;

    // ---------------------------------------------------------------------------
    // Collapse / expand (small screens use the toggle button)
    // ---------------------------------------------------------------------------

    function expand() {
        root.setAttribute('data-collapsed', 'false');
        if (wrapper) wrapper.setAttribute('data-expanded', 'true');
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
        input.focus();
    }

    function collapse() {
        root.setAttribute('data-collapsed', 'true');
        if (wrapper) wrapper.setAttribute('data-expanded', 'false');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        hidePanel();
    }

    if (toggle) {
        toggle.addEventListener('click', function() {
            var collapsed = root.getAttribute('data-collapsed') !== 'false';
            if (collapsed) {
                expand();
            } else {
                collapse();
            }
        });
    }

    document.addEventListener('click', function(e) {
        if (!root.contains(e.target)) {
            hidePanel();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            hidePanel();
        }
    });

    // ---------------------------------------------------------------------------
    // Panel rendering
    // ---------------------------------------------------------------------------

    function showPanel() {
        if (panel) panel.classList.add('is-open');
    }

    function hidePanel() {
        if (panel) panel.classList.remove('is-open');
    }

    function clearList() {
        list.innerHTML = '';
    }

    function setMessage(text) {
        if (!message) return;
        message.textContent = text || '';
        message.classList.toggle('screen-reader-text', !text);
    }

    function renderGroups(groups) {
        clearList();

        var keys = Object.keys(groups || {});
        if (!keys.length) {
            setMessage(window.uxstudioQuickSearchI18n && window.uxstudioQuickSearchI18n.noResults ? window.uxstudioQuickSearchI18n.noResults : 'No results.');
            showPanel();
            return;
        }

        setMessage('');

        keys.forEach(function(groupLabel) {
            var heading = document.createElement('li');
            heading.className = 'uxstudio-qs__group-heading';
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
                    links.appendChild(a);
                });
                item.appendChild(links);

                list.appendChild(item);
            });
        });

        showPanel();
    }

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

        var url = cfg.restUrl + '?q=' + encodeURIComponent(term);

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
                renderGroups(json && json.data ? json.data : {});
            })
            .catch(function(err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
                setMessage(window.uxstudioQuickSearchI18n && window.uxstudioQuickSearchI18n.error ? window.uxstudioQuickSearchI18n.error : 'Search failed.');
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
            hidePanel();
            return;
        }

        debounceTimer = setTimeout(function() {
            runSearch(term);
        }, DEBOUNCE_MS);
    });

    input.addEventListener('focus', function() {
        if (input.value.trim().length >= 2 && list.children.length) {
            showPanel();
        }
    });
})();
