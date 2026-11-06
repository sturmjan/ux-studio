/**
 * UX Studio AI Search - live suggestions, results page updates and the
 * hand-off to the AI Assistant chat widget.
 *
 * All markup that comes back from the REST endpoints is built (and escaped) on
 * the server by AiSearch\Renderer; this script only inserts it. Config:
 * window.uxstudioAiSearch (see AiSearch\Module::enqueue()).
 */
(function () {
    'use strict';

    var cfg = window.uxstudioAiSearch;
    if (!cfg) {
        return;
    }

    var RECENT_KEY = 'uxs_recent_searches';
    var OFFERED_KEY = 'uxs_ai_offered';
    var SUGGEST_DELAY = 200;
    var LIVE_DELAY = 350;

    function debounce(fn, wait) {
        var timer;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(null, args); }, wait);
        };
    }

    function api(path, params, signal) {
        var url = cfg.restUrl + path + '?' + new URLSearchParams(params).toString();
        return fetch(url, { signal: signal, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            });
    }

    function getRecent() {
        try {
            var list = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
            return Array.isArray(list) ? list.slice(0, 5) : [];
        } catch (e) {
            return [];
        }
    }

    function addRecent(q) {
        q = (q || '').trim();
        if (q.length < 2) {
            return;
        }
        try {
            var list = getRecent().filter(function (item) { return item !== q; });
            list.unshift(q);
            window.localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 5)));
        } catch (e) { /* storage blocked */ }
    }

    // -----------------------------------------------------------------------
    // Search field
    // -----------------------------------------------------------------------

    function initField(root) {
        if (root.__uxs) {
            return;
        }
        root.__uxs = true;

        var form = root.querySelector('form');
        var input = root.querySelector('.uxs-search__input');
        var list = root.querySelector('.uxs-suggest');
        if (!form || !input || !list) {
            return;
        }

        var suggestOn = root.getAttribute('data-suggest') === '1';
        var liveOn = root.getAttribute('data-live') === '1';
        var controller = null;
        var seq = 0;

        function setOpen(open) {
            list.hidden = !open;
            input.setAttribute('aria-expanded', open ? 'true' : 'false');
            root.classList.toggle('has-suggestions', open);
            if (!open) {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function options() {
            return Array.prototype.slice.call(list.querySelectorAll('[role="option"]'));
        }

        function setActive(index) {
            var opts = options();
            opts.forEach(function (opt, i) {
                var on = i === index;
                opt.classList.toggle('is-active', on);
                opt.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) {
                    if (!opt.id) {
                        opt.id = list.id + '-opt-' + i;
                    }
                    input.setAttribute('aria-activedescendant', opt.id);
                    opt.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function submitTerm(term) {
            input.value = term;
            addRecent(term);
            if (form.requestSubmit) {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }

        function showIdle() {
            var groups = [];
            var recent = getRecent();
            if (recent.length) {
                groups.push({ title: cfg.i18n.recent, terms: recent });
            }
            if (cfg.popular && cfg.popular.length) {
                groups.push({ title: cfg.i18n.popular, terms: cfg.popular });
            }
            if (!groups.length) {
                setOpen(false);
                return;
            }
            list.textContent = '';
            groups.forEach(function (group) {
                var heading = document.createElement('div');
                heading.className = 'uxs-suggest__heading';
                heading.textContent = group.title;
                list.appendChild(heading);
                group.terms.forEach(function (term) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'uxs-suggest__term';
                    btn.setAttribute('role', 'option');
                    btn.textContent = term;
                    btn.addEventListener('click', function () { submitTerm(term); });
                    list.appendChild(btn);
                });
            });
            setOpen(true);
        }

        function fetchSuggestions(q) {
            if (controller) {
                controller.abort();
            }
            controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var mine = ++seq;
            root.classList.add('is-loading');

            api('/suggest', { q: q }, controller ? controller.signal : undefined)
                .then(function (data) {
                    if (mine !== seq) {
                        return;
                    }
                    list.innerHTML = data.html;
                    setOpen(!!data.html);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    list.textContent = cfg.i18n.error;
                    setOpen(true);
                })
                .then(function () {
                    if (mine === seq) {
                        root.classList.remove('is-loading');
                    }
                });
        }

        var suggest = debounce(fetchSuggestions, SUGGEST_DELAY);

        input.addEventListener('input', function () {
            var q = input.value.trim();

            if (liveOn) {
                liveUpdate(root, q);
            }
            if (!suggestOn) {
                return;
            }
            if (q.length === 0) {
                seq++;
                showIdle();
            } else if (q.length >= cfg.minChars) {
                suggest(q);
            } else {
                seq++;
                setOpen(false);
            }
        });

        input.addEventListener('focus', function () {
            if (suggestOn && input.value.trim() === '') {
                showIdle();
            }
        });

        input.addEventListener('keydown', function (e) {
            var opts = options();
            var current = opts.findIndex(function (opt) { return opt.classList.contains('is-active'); });

            if (e.key === 'ArrowDown' && !list.hidden && opts.length) {
                e.preventDefault();
                setActive((current + 1) % opts.length);
            } else if (e.key === 'ArrowUp' && !list.hidden && opts.length) {
                e.preventDefault();
                setActive(current <= 0 ? opts.length - 1 : current - 1);
            } else if (e.key === 'Enter' && current > -1 && !list.hidden) {
                e.preventDefault();
                opts[current].click();
            } else if (e.key === 'Escape') {
                setOpen(false);
            }
        });

        form.addEventListener('submit', function () {
            addRecent(input.value);
            setOpen(false);
        });

        list.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('a[role="option"]')) {
                addRecent(input.value);
            }
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                setOpen(false);
            }
        });

        // Overlay design: icon opens a full-screen search.
        var overlay = root.querySelector('.uxs-overlay');
        if (overlay) {
            var trigger = root.querySelector('[data-uxs-open]');
            var closeBtn = root.querySelector('[data-uxs-close]');

            var openOverlay = function () {
                overlay.hidden = false;
                root.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
                document.documentElement.classList.add('uxs-lock');
                setTimeout(function () { input.focus(); }, 30);
            };
            var closeOverlay = function () {
                overlay.hidden = true;
                root.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
                document.documentElement.classList.remove('uxs-lock');
                setOpen(false);
                trigger.focus();
            };

            trigger.addEventListener('click', openOverlay);
            closeBtn.addEventListener('click', closeOverlay);
            overlay.addEventListener('mousedown', function (e) {
                if (e.target === overlay) {
                    closeOverlay();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !overlay.hidden) {
                    closeOverlay();
                }
            });
            root.__uxsOpen = openOverlay;
        }
    }

    // Ctrl/Cmd+K (and "/" outside of inputs) jumps to the command palette field,
    // or to the first search field on the page when there is no such design.
    document.addEventListener('keydown', function (e) {
        var isK = (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k';
        var tag = (e.target && e.target.tagName) || '';
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (e.target && e.target.isContentEditable);
        if (!isK && !(e.key === '/' && !typing)) {
            return;
        }
        var root = document.querySelector('.uxs-search--command') || document.querySelector('.uxs-search--overlay') || document.querySelector('.uxs-search');
        if (!root) {
            return;
        }
        e.preventDefault();
        if (root.__uxsOpen) {
            root.__uxsOpen();
            return;
        }
        var field = root.querySelector('.uxs-search__input');
        if (field) {
            field.focus();
            field.select();
        }
    });

    // -----------------------------------------------------------------------
    // Results page
    // -----------------------------------------------------------------------

    var resultsState = new WeakMap();

    function resultsFor(root) {
        var container = root.closest ? root.closest('[data-uxs-results]') : null;
        if (!container) {
            return null;
        }
        if (!resultsState.has(container)) {
            resultsState.set(container, { type: '', page: 1, controller: null, seq: 0 });
        }
        return { el: container, state: resultsState.get(container) };
    }

    function currentTerm(container) {
        var input = container.querySelector('.uxs-search__input');
        return input ? input.value.trim() : '';
    }

    function loadBody(container, state, q, allowAuto) {
        var body = container.querySelector('.uxs-results__body');
        if (!body) {
            return;
        }
        if (state.controller) {
            state.controller.abort();
        }
        state.controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var mine = ++state.seq;
        container.classList.add('is-loading');

        api('/search', {
            q: q,
            type: state.type,
            page: state.page,
            layout: container.getAttribute('data-layout') || ''
        }, state.controller ? state.controller.signal : undefined)
            .then(function (data) {
                if (mine !== state.seq) {
                    return;
                }
                body.innerHTML = data.html;
                setupAssistant(container, allowAuto);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
                body.textContent = cfg.i18n.error;
            })
            .then(function () {
                if (mine === state.seq) {
                    container.classList.remove('is-loading');
                }
            });
    }

    function syncUrl(q, type) {
        if (!window.history || !window.history.replaceState) {
            return;
        }
        var url = new URL(window.location.href);
        if (q) {
            url.searchParams.set(cfg.param, q);
        } else {
            url.searchParams.delete(cfg.param);
        }
        if (type) {
            url.searchParams.set('type', type);
        } else {
            url.searchParams.delete('type');
        }
        window.history.replaceState(null, '', url.toString());
    }

    var liveDebounced = new WeakMap();

    function liveUpdate(fieldRoot, q) {
        var res = resultsFor(fieldRoot);
        if (!res) {
            return;
        }
        if (!liveDebounced.has(res.el)) {
            liveDebounced.set(res.el, debounce(function (term) {
                res.state.type = '';
                res.state.page = 1;
                syncUrl(term, '');
                loadBody(res.el, res.state, term, false);
            }, LIVE_DELAY));
        }
        liveDebounced.get(res.el)(q);
    }

    document.addEventListener('click', function (e) {
        var chip = e.target.closest ? e.target.closest('.uxs-results__types [data-type]') : null;
        var more = e.target.closest ? e.target.closest('[data-uxs-more]') : null;
        if (!chip && !more) {
            return;
        }
        var res = resultsFor(e.target);
        if (!res) {
            return;
        }
        var q = currentTerm(res.el);
        if (chip) {
            res.state.type = chip.getAttribute('data-type') || '';
            res.state.page = 1;
        } else {
            res.state.page += 1;
        }
        syncUrl(q, res.state.type);
        loadBody(res.el, res.state, q, false);
    });

    // -----------------------------------------------------------------------
    // Hand-off to the AI Assistant
    // -----------------------------------------------------------------------

    function whenAssistant(callback, tries) {
        if (window.uxstudioAiAssistant && typeof window.uxstudioAiAssistant.offerHelp === 'function') {
            callback(window.uxstudioAiAssistant);
        } else if (tries > 0) {
            setTimeout(function () { whenAssistant(callback, tries - 1); }, 150);
        }
    }

    function fill(template, q) {
        return String(template || '').replace(/\{query\}/g, q);
    }

    function setupAssistant(container, allowAuto) {
        var box = container.querySelector('[data-uxs-ai]');
        var summary = container.querySelector('.uxs-results__summary');
        var q = summary ? summary.getAttribute('data-q') || '' : '';
        if (!box || cfg.ai.mode === 'off') {
            return;
        }

        // Look the API up on every call: the loader's stub is replaced by the real one once the widget has loaded.
        function offer() {
            window.uxstudioAiAssistant.offerHelp({
                text: fill(cfg.ai.offerText, q),
                reply: fill(cfg.ai.replyText, q),
                replyLabel: fill(cfg.ai.replyLabel, q)
            });
        }

        whenAssistant(function () {
            box.hidden = false;
            var button = box.querySelector('[data-uxs-ai-open]');
            if (button) {
                button.addEventListener('click', offer);
            }

            var count = parseInt(box.getAttribute('data-count'), 10) || 0;
            var withinThreshold = cfg.ai.threshold === 0 || count <= cfg.ai.threshold;
            var already = false;
            try {
                already = window.sessionStorage.getItem(OFFERED_KEY) === q;
            } catch (e) { /* storage blocked */ }

            // On phones the chat opens full screen and would hide the results: leave it to the button there.
            var roomy = !window.matchMedia || window.matchMedia('(min-width: 769px)').matches;

            if (allowAuto && cfg.ai.mode === 'auto' && q && withinThreshold && !already && roomy) {
                try {
                    window.sessionStorage.setItem(OFFERED_KEY, q);
                } catch (e) { /* storage blocked */ }
                setTimeout(offer, 1200);
            }
        }, 20);
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    function init() {
        document.querySelectorAll('[data-uxs-search]').forEach(initField);
        document.querySelectorAll('[data-uxs-results]').forEach(function (container) {
            setupAssistant(container, true);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
