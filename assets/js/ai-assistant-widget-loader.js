/**
 * UX Studio AI Assistant - lazy loader for the chat widget.
 *
 * Renders only the floating bubble (styled by a few lines of inline CSS) and
 * pulls in the full widget JS + CSS on the first interaction with it - hover,
 * focus or touch start the download, a click also opens the panel once it has
 * loaded. Pages where nobody touches the chat never download the widget.
 *
 * Loads right away (when the browser is idle) if this tab already has a chat
 * session, so an ongoing conversation/operator handoff is restored as before,
 * or when auto-open is configured.
 *
 * Config: window.uxstudioAiAssistantWidget (same object the full widget reads)
 * with an extra `assets: { js, css }` and `openLabel`.
 */
(function () {
    'use strict';

    var cfg  = window.uxstudioAiAssistantWidget;
    var root = document.getElementById('uxstudio-ai-assistant-widget-root');
    if (!cfg || !cfg.config || !cfg.assets || !root) {
        return;
    }

    var opts  = cfg.config;
    var state = 0; // 0 = idle, 1 = loading, 2 = loaded
    var openWhenLoaded = false;

    root.style.setProperty('--uxstudio-ais-color', opts.color || '#2271b1');
    root.style.setProperty('--uxstudio-ais-text', opts.textColor || '#ffffff');

    var bubble = document.createElement('button');
    bubble.type = 'button';
    bubble.className = 'uxstudio-ais-trigger uxstudio-ais-trigger--placeholder' + (opts.position === 'bottom-left' ? ' uxstudio-ais-trigger--left' : '');
    bubble.setAttribute('aria-label', cfg.openLabel || 'Chat');
    bubble.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    root.appendChild(bubble);

    function injectScript() {
        var script = document.createElement('script');
        script.src = cfg.assets.js;
        script.async = true;
        script.onload = function () {
            state = 2;
            // The full widget has rendered its own bubble by now - hand over.
            if (bubble.parentNode) {
                bubble.parentNode.removeChild(bubble);
            }
            if (openWhenLoaded) {
                var trigger = root.querySelector('.uxstudio-ais-trigger');
                if (trigger) {
                    trigger.click();
                }
            }
        };
        script.onerror = function () {
            state = 0; // allow a retry on the next interaction
            bubble.removeAttribute('aria-busy');
        };
        document.body.appendChild(script);
    }

    function load() {
        if (state !== 0) {
            return;
        }
        state = 1;
        bubble.setAttribute('aria-busy', 'true');

        if (document.getElementById('uxstudio-ai-assistant-widget-css')) {
            injectScript();
            return;
        }

        // CSS first, so the panel the script builds is never shown unstyled.
        var injected = false;
        var once = function () {
            if (!injected) {
                injected = true;
                injectScript();
            }
        };
        var link = document.createElement('link');
        link.id = 'uxstudio-ai-assistant-widget-css';
        link.rel = 'stylesheet';
        link.href = cfg.assets.css;
        link.onload = once;
        link.onerror = once;
        document.head.appendChild(link);
        setTimeout(once, 3000);
    }

    function loadWhenIdle() {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(load, { timeout: 3000 });
        } else {
            setTimeout(load, 1);
        }
    }

    bubble.addEventListener('click', function () {
        openWhenLoaded = true;
        load();
    });
    ['pointerenter', 'touchstart', 'focus'].forEach(function (type) {
        bubble.addEventListener(type, load, { passive: true });
    });

    var hasSession = false;
    try {
        hasSession = !!window.sessionStorage.getItem('uxstudio_ais_session');
    } catch (_) { /* storage blocked */ }

    // Auto-open shows the panel anyway - the full widget runs its own timer once loaded.
    if (hasSession || opts.autoOpen) {
        loadWhenIdle();
    }
})();
