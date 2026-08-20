(function() {
    'use strict';

    // ---------------------------------------------------------------------------
    // Configuration & early exit
    // ---------------------------------------------------------------------------

    var cfg = window.uxstudioAiAssistantWidget;
    if (!cfg || !cfg.restUrl || !cfg.config) {
        return;
    }

    var restUrl  = cfg.restUrl.replace(/\/+$/, '');
    var nonce    = cfg.nonce || '';
    var opts     = cfg.config;
    var root     = document.getElementById('uxstudio-ai-assistant-widget-root');

    if (!root) {
        return;
    }

    // ---------------------------------------------------------------------------
    // Constants
    // ---------------------------------------------------------------------------

    var STORAGE_SESSION  = 'uxstudio_ais_session';
    var STORAGE_GDPR     = 'uxstudio_ais_gdpr';
    var STORAGE_MESSAGES = 'uxstudio_ais_messages';
    var MAX_STORED_MSGS  = 50;
    var MOBILE_BREAKPOINT = 768;

    // ---------------------------------------------------------------------------
    // Session - per tab/window (sessionStorage), GDPR consent persists (localStorage)
    // ---------------------------------------------------------------------------

    var sessionId = sessionStorage.getItem(STORAGE_SESSION);
    if (!sessionId) {
        sessionId = crypto.randomUUID();
        sessionStorage.setItem(STORAGE_SESSION, sessionId);
    }

    // ---------------------------------------------------------------------------
    // GDPR state - persists across sessions (localStorage)
    // ---------------------------------------------------------------------------

    var gdprConsented = localStorage.getItem(STORAGE_GDPR) === '1';

    // ---------------------------------------------------------------------------
    // Message storage helpers (sessionStorage = per tab, cleared on close)
    // ---------------------------------------------------------------------------

    function loadStoredMessages() {
        try {
            var raw = sessionStorage.getItem(STORAGE_MESSAGES);
            if (raw) {
                var parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    return parsed;
                }
            }
        } catch (_) { /* ignore */ }
        return [];
    }

    function saveMessages(msgs) {
        var trimmed = msgs.slice(-MAX_STORED_MSGS);
        try {
            sessionStorage.setItem(STORAGE_MESSAGES, JSON.stringify(trimmed));
        } catch (_) { /* quota exceeded */ }
    }

    var messages = loadStoredMessages();

    // ---------------------------------------------------------------------------
    // CSS custom properties
    // ---------------------------------------------------------------------------

    var accentColor = opts.color || '#2271b1';
    var textColor   = opts.textColor || '#ffffff';

    root.style.setProperty('--uxstudio-ais-color', accentColor);
    root.style.setProperty('--uxstudio-ais-text', textColor);

    // ---------------------------------------------------------------------------
    // DOM creation helpers
    // ---------------------------------------------------------------------------

    function el(tag, className, attrs) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (attrs) {
            Object.keys(attrs).forEach(function(k) {
                node.setAttribute(k, attrs[k]);
            });
        }
        return node;
    }

    function sanitizeText(str) {
        var tmp = document.createElement('span');
        tmp.textContent = str;
        return tmp.innerHTML;
    }

    function decodeHtmlEntities(str) {
        var tmp = document.createElement('textarea');
        tmp.innerHTML = str || '';
        return tmp.value;
    }

    // ---------------------------------------------------------------------------
    // Sound effects (Web Audio API)
    // ---------------------------------------------------------------------------

    var audioCtx = null;

    function getAudioContext() {
        if (!audioCtx) {
            try {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            } catch (_) { return null; }
        }
        // Resume if browser suspended it (autoplay policy)
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume().catch(function() {});
        }
        return audioCtx;
    }

    function playOpenSound() {
        var ctx = getAudioContext();
        if (!ctx) return;
        try {
            // Two-tone ascending chime
            var now = ctx.currentTime;
            var osc1 = ctx.createOscillator();
            var osc2 = ctx.createOscillator();
            var gain = ctx.createGain();

            osc1.connect(gain);
            osc2.connect(gain);
            gain.connect(ctx.destination);

            osc1.frequency.value = 523; // C5
            osc1.type = 'sine';
            osc2.frequency.value = 659; // E5
            osc2.type = 'sine';

            gain.gain.setValueAtTime(0.15, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.4);

            osc1.start(now);
            osc1.stop(now + 0.2);
            osc2.start(now + 0.12);
            osc2.stop(now + 0.4);
        } catch (_) { /* silently fail */ }
    }

    function playReceiveSound() {
        var ctx = getAudioContext();
        if (!ctx) return;
        try {
            var now = ctx.currentTime;
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880; // A5
            osc.type = 'sine';
            gain.gain.setValueAtTime(0.12, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.15);
            osc.start(now);
            osc.stop(now + 0.15);
        } catch (_) { /* silently fail */ }
    }

    function playSendSound() {
        var ctx = getAudioContext();
        if (!ctx) return;
        try {
            var now = ctx.currentTime;
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 600;
            osc.type = 'sine';
            gain.gain.setValueAtTime(0.1, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.1);
            osc.start(now);
            osc.stop(now + 0.1);
        } catch (_) { /* silently fail */ }
    }

    // ---------------------------------------------------------------------------
    // Build HTML structure
    // ---------------------------------------------------------------------------

    // -- Trigger button
    var trigger = el('button', 'uxstudio-ais-trigger', {
        'type': 'button',
        'aria-label': 'Otevrit chat'
    });
    trigger.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

    // -- Panel
    var panel = el('div', 'uxstudio-ais-panel');

    if (opts.type === 'sidebar') {
        panel.classList.add('uxstudio-ais-panel--sidebar');
    }

    if (opts.position === 'bottom-left') {
        panel.classList.add('uxstudio-ais-panel--left');
        trigger.classList.add('uxstudio-ais-trigger--left');
    }

    // -- Header
    var header = el('div', 'uxstudio-ais-header');
    var headerTitle = el('span', 'uxstudio-ais-header-title');
    headerTitle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> ' + sanitizeText(opts.title || 'AI Podpora');
    var closeBtn = el('button', 'uxstudio-ais-header-close', {
        'type': 'button',
        'aria-label': 'Zavrit chat'
    });
    closeBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

    // Email contact button in header
    var emailBtn = el('button', 'uxstudio-ais-header-email', {
        'type': 'button',
        'aria-label': 'Napsat email',
        'title': 'Napsat email'
    });
    emailBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>';
    emailBtn.addEventListener('click', function() {
        showErrorWithContactForm('Napište nám a my se vám ozveme co nejdříve.');
        scrollToBottom();
    });

    // Operator handoff button in header ("call a human operator")
    var operatorBtn = el('button', 'uxstudio-ais-header-email', {
        'type': 'button',
        'aria-label': 'Přivolat operátora',
        'title': 'Mluvit s operátorem'
    });
    operatorBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
    operatorBtn.addEventListener('click', function() {
        requestHandoff();
    });

    // Phone contact button in header
    var phoneBtn = el('button', 'uxstudio-ais-header-email', {
        'type': 'button',
        'aria-label': 'Zavoláme vám zpět',
        'title': 'Zavoláme vám zpět'
    });
    phoneBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
    phoneBtn.addEventListener('click', function() {
        showPhoneCallbackForm('Zanechte nám telefonní číslo a my vám zavoláme zpět.');
        scrollToBottom();
    });

    // Maximize button in header
    var maximizeBtn = el('button', 'uxstudio-ais-header-email', {
        'type': 'button',
        'aria-label': 'Zvětšit chat',
        'title': 'Zvětšit chat'
    });
    var maximizeSvgExpand = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="m21 3-7 7"/><path d="m3 21 7-7"/></svg>';
    var maximizeSvgShrink = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14h6v6"/><path d="M20 10h-6V4"/><path d="m14 10 7-7"/><path d="m3 21 7-7"/></svg>';
    maximizeBtn.innerHTML = maximizeSvgExpand;
    var isMaximized = false;
    maximizeBtn.addEventListener('click', function() {
        isMaximized = !isMaximized;
        panel.classList.toggle('uxstudio-ais-panel--maximized', isMaximized);
        maximizeBtn.innerHTML = isMaximized ? maximizeSvgShrink : maximizeSvgExpand;
        maximizeBtn.title = isMaximized ? 'Zmenšit chat' : 'Zvětšit chat';
        maximizeBtn.setAttribute('aria-label', maximizeBtn.title);
    });

    // New chat button in header
    var newChatBtn = el('button', 'uxstudio-ais-header-email', {
        'type': 'button',
        'aria-label': 'Nový chat',
        'title': 'Začít nový chat'
    });
    newChatBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>';
    newChatBtn.addEventListener('click', function() {
        startNewChat();
    });

    var headerActions = el('div', 'uxstudio-ais-header-actions');
    headerActions.appendChild(newChatBtn);
    headerActions.appendChild(operatorBtn);
    headerActions.appendChild(phoneBtn);
    headerActions.appendChild(emailBtn);
    if (opts.type !== 'sidebar') {
        headerActions.appendChild(maximizeBtn);
    }
    headerActions.appendChild(closeBtn);

    header.appendChild(headerTitle);
    header.appendChild(headerActions);

    // -- GDPR notice (shown as small bar at bottom, not blocking)
    var gdprBar = el('div', 'uxstudio-ais-gdpr-bar');
    gdprBar.innerHTML = '<span class="uxstudio-ais-gdpr-bar-text">' + sanitizeText(opts.gdprText || 'Tento chat vyuziva AI. Odeslani zpravy znamena souhlas se zpracovanim dotazu.') + '</span>';
    if (gdprConsented) {
        gdprBar.style.display = 'none';
    }

    // -- Body (messages + input) - always visible
    var body = el('div', 'uxstudio-ais-body');
    var messagesArea = el('div', 'uxstudio-ais-messages');
    var inputArea = el('div', 'uxstudio-ais-input-area');
    var input = el('textarea', 'uxstudio-ais-input', {
        'placeholder': opts.placeholder || 'Napiste svuj dotaz...',
        'rows': '1',
        'aria-label': 'Zprava'
    });
    var sendBtn = el('button', 'uxstudio-ais-send', {
        'type': 'button',
        'aria-label': 'Odeslat'
    });
    sendBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
    inputArea.appendChild(input);
    inputArea.appendChild(sendBtn);
    body.appendChild(messagesArea);
    body.appendChild(gdprBar);
    body.appendChild(inputArea);

    // -- Assemble panel
    panel.appendChild(header);
    panel.appendChild(body);

    // -- Inject into root
    root.appendChild(trigger);
    root.appendChild(panel);

    // ---------------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------------

    var panelOpen    = false;
    var isStreaming   = false;
    var faqsLoaded   = false;
    var currentBotEl = null;
    var currentBotTextEl = null;
    var currentBotBuffer  = '';
    var ratingSubmitted = sessionStorage.getItem('uxstudio_ais_rated') === '1';
    var inactivityTimer = null;
    var exchangeCount = 0;

    // Handoff state
    var handoffStatus = 'ai'; // 'ai' | 'requested' | 'human'
    var handoffSince = 0;
    var handoffPollTimer = null;
    var handoffBannerEl = null;
    var handoffAssignedTo = null;
    var pendingHandoffSystemMessage = false;
    var isHandoffPolling = false; // race-condition guard
    var renderedMessageKeys = {}; // idempotentní render
    var handoffTimeoutTimer = null; // timer pro fallback po X sekundách nečinnosti operátora

    // ---------------------------------------------------------------------------
    // REST helpers
    // ---------------------------------------------------------------------------

    function apiHeaders(isJson) {
        var h = { 'X-WP-Nonce': nonce };
        if (isJson) {
            h['Content-Type'] = 'application/json';
        }
        return h;
    }

    function apiGet(endpoint) {
        return fetch(restUrl + endpoint, {
            method: 'GET',
            headers: apiHeaders(false),
            credentials: 'same-origin'
        }).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function apiPost(endpoint, data) {
        return fetch(restUrl + endpoint, {
            method: 'POST',
            headers: apiHeaders(true),
            credentials: 'same-origin',
            body: JSON.stringify(data)
        });
    }

    // POST + parsování JSON. Pro endpointy, kde nechceme citlivá data (session_id)
    // posílat v query stringu (končí v access logách / Referer / historii).
    function apiPostJson(endpoint, data) {
        return apiPost(endpoint, data).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    // ---------------------------------------------------------------------------
    // Panel toggle
    // ---------------------------------------------------------------------------

    function openPanel(skipSound) {
        panelOpen = true;
        panel.classList.add('uxstudio-ais-panel--open');
        trigger.classList.add('uxstudio-ais-trigger--active');

        if (!skipSound) {
            playOpenSound();
        }

        if (!faqsLoaded) {
            showGreetingAndFaqs();
        }

        setTimeout(function() { input.focus(); }, 100);
    }

    var wasAutoOpened = false;

    function closePanel() {
        panelOpen = false;
        panel.classList.remove('uxstudio-ais-panel--open');
        trigger.classList.remove('uxstudio-ais-trigger--active');

        if (wasAutoOpened) {
            // 0 = nikdy neblokovat (chat se auto-otevře pokaždé). Proto NEpoužívat
            // "|| 30" fallback, který by nulu (falsy) přepsal na 30.
            var cooldownRaw = parseInt(opts.autoOpenCooldown, 10);
            var cooldown = isNaN(cooldownRaw) ? 30 : cooldownRaw;
            if (cooldown > 0) {
                setAutoOpenCookie(cooldown);
            }
            wasAutoOpened = false;
        }
    }

    function togglePanel() {
        if (panelOpen) {
            closePanel();
        } else {
            openPanel();
        }
    }

    trigger.addEventListener('click', togglePanel);
    closeBtn.addEventListener('click', closePanel);

    // ---------------------------------------------------------------------------
    // Mobile: fullscreen detection
    // ---------------------------------------------------------------------------

    function checkMobile() {
        if (window.innerWidth < MOBILE_BREAKPOINT) {
            panel.classList.add('uxstudio-ais-panel--fullscreen');
        } else {
            panel.classList.remove('uxstudio-ais-panel--fullscreen');
        }
    }

    checkMobile();
    window.addEventListener('resize', checkMobile);

    // ---------------------------------------------------------------------------
    // GDPR consent (sent silently with first message)
    // ---------------------------------------------------------------------------

    function ensureGdprConsent() {
        if (gdprConsented) return;
        gdprConsented = true;
        localStorage.setItem(STORAGE_GDPR, '1');
        gdprBar.style.display = 'none';

        apiPost('/gdpr-consent', { session_id: sessionId }).catch(function() {
            // Silently ignore - consent is tracked locally
        });
    }

    // ---------------------------------------------------------------------------
    // Scroll helper
    // ---------------------------------------------------------------------------

    function scrollToBottom() {
        requestAnimationFrame(function() {
            messagesArea.scrollTop = messagesArea.scrollHeight;
        });
    }

    // ---------------------------------------------------------------------------
    // Message rendering
    // ---------------------------------------------------------------------------

    function createMessageEl(role, text, extraClass) {
        var wrapper = el('div', 'uxstudio-ais-msg uxstudio-ais-msg--' + role);
        if (extraClass) {
            wrapper.classList.add(extraClass);
        }
        var textEl = el('div', 'uxstudio-ais-msg-text');
        textEl.textContent = text;
        wrapper.appendChild(textEl);
        return { wrapper: wrapper, textEl: textEl };
    }

    function addMessageToUI(role, text) {
        var parts = createMessageEl(role, text);
        messagesArea.appendChild(parts.wrapper);
        scrollToBottom();
        return parts;
    }

    function addStoredMessage(msg) {
        if (msg.role === 'handoff-timeout' || (msg.role === 'system' && msg.text === 'handoff-timeout')) {
            renderHandoffTimeoutMessage(true);
            return;
        }
        if (msg.role === 'bot' && msg.products && msg.products.length > 0) {
            // Full product data available - render immediately
            var parts = createMessageEl('bot', msg.text);
            messagesArea.appendChild(parts.wrapper);
            renderProductCardsFromStored(parts.wrapper, msg.products);
        } else if (msg.role === 'bot' && msg.product_ids && msg.product_ids.length > 0 && (!msg.products || msg.products.length === 0)) {
            // Product IDs saved but full data missing (navigation interrupted fetch)
            var parts = createMessageEl('bot', msg.text);
            messagesArea.appendChild(parts.wrapper);
            var wrapperEl = parts.wrapper;
            var fetches = msg.product_ids.map(function(id) {
                return apiGet('/product-card/' + encodeURIComponent(id)).catch(function() { return null; });
            });
            Promise.all(fetches).then(function(results) {
                var products = results.filter(function(p) { return p !== null; });
                if (products.length > 0) {
                    renderProductCardsFromStored(wrapperEl, products);
                    // Update storage with full product data
                    msg.products = products;
                    saveMessages(messages);
                }
            });
        } else {
            addMessageToUI(msg.role, msg.text);
        }
    }

    // ---------------------------------------------------------------------------
    // Typing indicator
    // ---------------------------------------------------------------------------

    function showTypingIndicator() {
        var wrapper = el('div', 'uxstudio-ais-msg uxstudio-ais-msg--bot');
        var typing = el('div', 'uxstudio-ais-typing');
        typing.innerHTML = '<span class="uxstudio-ais-typing-dot"></span><span class="uxstudio-ais-typing-dot"></span><span class="uxstudio-ais-typing-dot"></span>';
        wrapper.appendChild(typing);
        messagesArea.appendChild(wrapper);
        scrollToBottom();
        return wrapper;
    }

    function removeTypingIndicator(indicator) {
        if (indicator && indicator.parentNode) {
            indicator.parentNode.removeChild(indicator);
        }
    }

    // ---------------------------------------------------------------------------
    // Greeting & FAQs
    // ---------------------------------------------------------------------------

    function showGreetingAndFaqs() {
        if (faqsLoaded) return;
        faqsLoaded = true;

        // Restore stored messages first
        if (messages.length > 0) {
            messages.forEach(function(msg) {
                addStoredMessage(msg);
            });
            scrollToBottom();

            // Pokud návštěvník zatím nic nenapsal (uložený je jen úvodní
            // pozdrav), nabídneme mu i tak zkratky na časté dotazy.
            var hasUserMessage = messages.some(function(m) { return m.role === 'user'; });
            if (!hasUserMessage) {
                loadFaqs();
            }
            return;
        }

        // Show greeting
        var greetingText = opts.greeting || 'Dobry den! Jak vam mohu pomoci?';
        addMessageToUI('bot', greetingText);
        messages.push({ role: 'bot', text: greetingText });
        saveMessages(messages);

        loadFaqs();
    }

    function loadFaqs() {
        apiGet('/faqs')
            .then(function(data) {
                if (data && Array.isArray(data) && data.length > 0) {
                    renderFaqChips(data);
                }
            })
            .catch(function() { /* ignore */ });
    }

    // FAQ answers are already known (loaded with the question) - show them
    // instantly without going through the AI chat pipeline.
    function showFaqAnswer(question, answer) {
        ensureGdprConsent();

        addMessageToUI('user', question);
        messages.push({ role: 'user', text: question });

        var answerText = decodeHtmlEntities(answer);
        addMessageToUI('bot', answerText);
        messages.push({ role: 'bot', text: answerText });

        saveMessages(messages);
        scrollToBottom();
    }

    var FAQ_VISIBLE_COUNT = 3;
    var FAQ_CHEVRON_RIGHT = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
    var FAQ_CHEVRON_DOWN = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';

    function renderFaqChips(faqs) {
        var container = el('div', 'uxstudio-ais-faqs');

        var title = el('div', 'uxstudio-ais-faqs-title');
        title.textContent = 'Často kladené dotazy';
        container.appendChild(title);

        var list = el('div', 'uxstudio-ais-faqs-list');
        container.appendChild(list);

        function removeContainer() {
            if (container.parentNode) {
                container.parentNode.removeChild(container);
            }
        }

        function makeChip(faq) {
            var chip = el('button', 'uxstudio-ais-faq-chip', { 'type': 'button' });
            var textEl = el('span', 'uxstudio-ais-faq-chip-text');
            textEl.textContent = faq.question || faq;
            var iconEl = el('span', 'uxstudio-ais-faq-chip-icon');
            iconEl.innerHTML = FAQ_CHEVRON_RIGHT;
            chip.appendChild(textEl);
            chip.appendChild(iconEl);
            chip.addEventListener('click', function() {
                var question = faq.question || faq;
                removeContainer();
                if (faq.answer) {
                    showFaqAnswer(question, faq.answer);
                } else {
                    input.value = question;
                    sendMessage();
                }
            });
            return chip;
        }

        faqs.slice(0, FAQ_VISIBLE_COUNT).forEach(function(faq) {
            list.appendChild(makeChip(faq));
        });

        var extraFaqs = faqs.slice(FAQ_VISIBLE_COUNT);
        if (extraFaqs.length > 0) {
            var extraList = el('div', 'uxstudio-ais-faqs-list uxstudio-ais-faqs-list--hidden');
            extraFaqs.forEach(function(faq) {
                extraList.appendChild(makeChip(faq));
            });
            container.appendChild(extraList);

            var moreBtn = el('button', 'uxstudio-ais-faq-more', { 'type': 'button' });
            var moreLabelEl = el('span', '');
            var moreIconEl = el('span', 'uxstudio-ais-faq-more-icon');
            moreIconEl.innerHTML = FAQ_CHEVRON_DOWN;

            function labelFor(expanded) {
                return expanded ? 'Skrýt otázky' : 'Zobrazit další otázky (' + extraFaqs.length + ')';
            }

            var expanded = false;
            moreLabelEl.textContent = labelFor(expanded);
            moreBtn.appendChild(moreLabelEl);
            moreBtn.appendChild(moreIconEl);
            moreBtn.addEventListener('click', function() {
                expanded = !expanded;
                extraList.classList.toggle('uxstudio-ais-faqs-list--hidden', !expanded);
                moreBtn.classList.toggle('uxstudio-ais-faq-more--expanded', expanded);
                moreLabelEl.textContent = labelFor(expanded);
                scrollToBottom();
            });
            container.appendChild(moreBtn);
        }

        messagesArea.appendChild(container);
        scrollToBottom();
    }

    // ---------------------------------------------------------------------------
    // Product cards
    // ---------------------------------------------------------------------------

    // Scroll šipku pro carousel přidat JEN když je reálně co scrollovat
    // (carousel přetéká). Jinak by zůstala viset mrtvá šipka bez funkce.
    function maybeAddCarouselArrow(wrapper, carousel) {
        var existing = wrapper.querySelector('.uxstudio-ais-product-arrow');
        var overflows = (carousel.scrollWidth - carousel.clientWidth) > 10;
        if (!overflows) {
            if (existing && existing.parentNode) {
                existing.parentNode.removeChild(existing);
            }
            return;
        }
        if (existing) return;
        var arrowBtn = el('button', 'uxstudio-ais-product-arrow', { 'type': 'button', 'aria-label': 'Další' });
        arrowBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
        arrowBtn.addEventListener('click', function() {
            var maxScroll = carousel.scrollWidth - carousel.clientWidth;
            if (carousel.scrollLeft >= maxScroll - 10) {
                carousel.scrollTo({ left: 0, behavior: 'smooth' });
            } else {
                carousel.scrollBy({ left: 180, behavior: 'smooth' });
            }
        });
        wrapper.appendChild(arrowBtn);
    }

    function loadProductCards(productIds, containerEl) {
        if (!productIds || !productIds.length) return;

        // Load all products in parallel, then render at once
        var fetches = productIds.map(function(id) {
            return apiGet('/product-card/' + encodeURIComponent(id)).catch(function() { return null; });
        });

        Promise.all(fetches).then(function(products) {
            var valid = products.filter(function(p) { return p; });
            if (!valid.length) return; // žádné produkty → nic (žádná mrtvá šipka)

            var wrapper = containerEl.querySelector('.uxstudio-ais-product-wrapper');
            var carousel;
            if (!wrapper) {
                wrapper = el('div', 'uxstudio-ais-product-wrapper');
                carousel = el('div', 'uxstudio-ais-product-carousel');
                wrapper.appendChild(carousel);
                containerEl.appendChild(wrapper);
            } else {
                carousel = wrapper.querySelector('.uxstudio-ais-product-carousel');
            }

            valid.forEach(function(product) {
                carousel.appendChild(buildProductCard(product));
            });

            maybeAddCarouselArrow(wrapper, carousel);
            scrollToBottom();
        });
    }

    function buildProductCard(product) {
        var imgUrl = product.image_url || product.image || '';
        var link = product.permalink || product.url || '';
        var priceHtml = product.price_html || '';
        var priceNum = product.price || '';

        var card = el('a', 'uxstudio-ais-product-card', {
            'href': link || '#',
            'target': '_blank',
            'rel': 'noopener noreferrer'
        });

        if (imgUrl) {
            var img = el('img', 'uxstudio-ais-product-card-img', {
                'src': sanitizeText(imgUrl),
                'alt': sanitizeText(product.name || ''),
                'loading': 'lazy'
            });
            card.appendChild(img);
        }

        var bodyEl = el('div', 'uxstudio-ais-product-card-body');

        var name = el('div', 'uxstudio-ais-product-card-name');
        name.textContent = product.name || '';
        bodyEl.appendChild(name);

        if (priceHtml) {
            var price = el('div', 'uxstudio-ais-product-card-price');
            price.innerHTML = priceHtml;
            bodyEl.appendChild(price);
        } else if (priceNum) {
            var price = el('div', 'uxstudio-ais-product-card-price');
            price.textContent = priceNum + ' Kč';
            bodyEl.appendChild(price);
        }

        var cta = el('span', 'uxstudio-ais-product-card-link');
        cta.textContent = 'Zobrazit \u2192';
        bodyEl.appendChild(cta);

        card.appendChild(bodyEl);
        return card;
    }

    function renderProductCardsFromStored(containerEl, products) {
        var valid = (products || []).filter(function(p) { return p; });
        if (!valid.length) return;
        var wrapper = el('div', 'uxstudio-ais-product-wrapper');
        var carousel = el('div', 'uxstudio-ais-product-carousel');
        valid.forEach(function(product) {
            carousel.appendChild(buildProductCard(product));
        });
        wrapper.appendChild(carousel);
        containerEl.appendChild(wrapper);
        maybeAddCarouselArrow(wrapper, carousel);
    }

    // ---------------------------------------------------------------------------
    // Remove [product:ID] tags from text
    // ---------------------------------------------------------------------------

    function stripProductTags(text) {
        return text.replace(/\[product:\d+\]/g, '');
    }

    function cleanBotText(text) {
        // Odstranit produktové tagy
        text = text.replace(/\[product:\d+\]/g, '');
        // Odstranit kompletní handoff tagy (různé varianty psaní)
        text = text.replace(/\[\s*human[-_\s]?agent(?:[-_\s]direct)?\s*\]/gi, '');
        // Odstranit nedokončený tag na konci (streaming - uživatel by viděl "[human-ag" apod.)
        text = text.replace(/\[human[-_\s]?a?g?e?n?t?[-_\s]?d?i?r?e?c?t?\]?\s*$/gi, '');
        // Poslední záchrana - cokoliv co začíná [human na konci
        text = text.replace(/\[\s*human[^\]]*$/gi, '');
        return text.trim();
    }

    // ---------------------------------------------------------------------------
    // Handoff (live operator)
    // ---------------------------------------------------------------------------

    // Hides the "call an operator" header button while a request is pending
    // or already being handled live, so the customer can't fire a second
    // request on top of an existing one.
    function updateOperatorButtonVisibility() {
        if (!operatorBtn) return;
        var hide = (handoffStatus === 'requested' || handoffStatus === 'human');
        operatorBtn.style.display = hide ? 'none' : '';
    }

    function showHandoffBanner() {
        if (handoffBannerEl && handoffBannerEl.parentNode) {
            return;
        }
        handoffBannerEl = el('div', 'uxstudio-ais-handoff-banner');
        updateHandoffBanner();
        body.insertBefore(handoffBannerEl, messagesArea);
    }

    function updateHandoffBanner() {
        if (!handoffBannerEl) return;
        var text, showCancel = false;

        if (handoffStatus === 'requested') {
            text = '<span class="uxstudio-ais-handoff-dot"></span> Čekáme na připojení operátora…';
            showCancel = true;
        } else if (handoffStatus === 'human') {
            var name = handoffAssignedTo ? sanitizeText(handoffAssignedTo) : 'Operátor';
            text = '<span class="uxstudio-ais-handoff-dot uxstudio-ais-handoff-dot--live"></span> ' + name + ' je online';
            showCancel = false;
        } else {
            text = '';
        }

        handoffBannerEl.innerHTML = '<span class="uxstudio-ais-handoff-banner-text">' + text + '</span>';

        if (showCancel) {
            var cancelBtn = el('button', 'uxstudio-ais-handoff-cancel', { 'type': 'button' });
            cancelBtn.textContent = 'Zrušit';
            cancelBtn.addEventListener('click', cancelHandoff);
            handoffBannerEl.appendChild(cancelBtn);
        }
    }

    function hideHandoffBanner() {
        if (handoffBannerEl && handoffBannerEl.parentNode) {
            handoffBannerEl.parentNode.removeChild(handoffBannerEl);
        }
        handoffBannerEl = null;
    }

    function requestHandoff() {
        if (handoffStatus === 'requested' || handoffStatus === 'human') {
            return;
        }

        ensureGdprConsent();

        operatorBtn.disabled = true;
        apiPost('/handoff/request', {
            session_id: sessionId,
            reason: 'Zákazník požádal o operátora přes tlačítko.'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            operatorBtn.disabled = false;
            if (data.error) {
                showError(data.error);
                return;
            }
            handoffStatus = data.status || 'requested';
            updateOperatorButtonVisibility();
            var infoText = data.message || 'Vaše žádost byla zaznamenána. Počkejte, prosím, na připojení operátora.';
            addMessageToUI('bot', infoText);
            messages.push({ role: 'bot', text: infoText });
            saveMessages(messages);

            showHandoffBanner();
            startHandoffPolling();
            startHandoffTimeoutTimer();
        })
        .catch(function() {
            operatorBtn.disabled = false;
            showError('Nepodařilo se zavolat operátora. Zkuste to prosím znovu.');
        });
    }

    function cancelHandoff() {
        clearHandoffTimeoutTimer();
        apiPost('/handoff/cancel', { session_id: sessionId })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                handoffStatus = data.status || 'ai';
                updateOperatorButtonVisibility();
                hideHandoffBanner();
                stopHandoffPolling();
                addMessageToUI('bot', 'Žádost o operátora byla zrušena. Pokračujeme s AI asistentem.');
                messages.push({ role: 'bot', text: 'Žádost o operátora byla zrušena. Pokračujeme s AI asistentem.' });
                saveMessages(messages);
            })
            .catch(function() {});
    }

    function sendHandoffMessage(text) {
        clearInactivityTimer();
        isStreaming = true;
        sendBtn.disabled = true;

        apiPost('/handoff/message', {
            session_id: sessionId,
            message: text,
            page_url: window.location.href
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            isStreaming = false;
            sendBtn.disabled = false;
            if (data.error) {
                showError(data.error);
                return;
            }
            // Zpráva byla odeslána operátorovi - index se zvedne, polling zachytí případné odpovědi
            handoffSince += 1;
            input.focus();
        })
        .catch(function() {
            isStreaming = false;
            sendBtn.disabled = false;
            showError('Nepodařilo se odeslat zprávu operátorovi.');
        });
    }

    function startHandoffPolling() {
        if (handoffPollTimer) return;
        handoffPollTimer = setInterval(pollHandoff, 2500);
        // Spustit ihned
        pollHandoff();
    }

    function stopHandoffPolling() {
        if (handoffPollTimer) {
            clearInterval(handoffPollTimer);
            handoffPollTimer = null;
        }
    }

    function startHandoffTimeoutTimer(remainingSeconds) {
        clearHandoffTimeoutTimer();
        var total = (typeof remainingSeconds === 'number') ? remainingSeconds : (parseInt(opts.handoffTimeout, 10) || 0);
        if (total <= 0) return;
        handoffTimeoutTimer = setTimeout(handleHandoffTimeout, total * 1000);
    }

    function clearHandoffTimeoutTimer() {
        if (handoffTimeoutTimer) {
            clearTimeout(handoffTimeoutTimer);
            handoffTimeoutTimer = null;
        }
    }

    // ---------------------------------------------------------------------------
    // New chat (reset conversation, start a fresh session)
    // ---------------------------------------------------------------------------

    function startNewChat() {
        if (handoffStatus !== 'ai') {
            apiPost('/handoff/cancel', { session_id: sessionId }).catch(function() {});
        }
        clearHandoffTimeoutTimer();
        stopHandoffPolling();
        hideHandoffBanner();
        handoffStatus = 'ai';
        updateOperatorButtonVisibility();
        handoffSince = 0;
        handoffAssignedTo = null;
        pendingHandoffSystemMessage = false;
        isHandoffPolling = false;

        clearInactivityTimer();
        exchangeCount = 0;
        renderedMessageKeys = {};

        sessionId = crypto.randomUUID();
        sessionStorage.setItem(STORAGE_SESSION, sessionId);
        sessionStorage.removeItem('uxstudio_ais_rated');
        ratingSubmitted = false;

        messages = [];
        saveMessages(messages);

        messagesArea.innerHTML = '';
        faqsLoaded = false;
        showGreetingAndFaqs();
        scrollToBottom();
    }

    function handleHandoffTimeout() {
        handoffTimeoutTimer = null;
        // Jen pokud zákazník stále čeká - operátor se nepřipojil
        if (handoffStatus !== 'requested') return;

        // Zrušit handoff na serveru (vrátíme se k AI)
        apiPost('/handoff/cancel', { session_id: sessionId }).catch(function() {});
        handoffStatus = 'ai';
        updateOperatorButtonVisibility();
        hideHandoffBanner();
        stopHandoffPolling();

        renderHandoffTimeoutMessage();
    }

    function renderHandoffTimeoutMessage(skipSave) {
        var wrap = el('div', 'uxstudio-ais-handoff-timeout');

        var msg = el('div', 'uxstudio-ais-handoff-timeout-text');
        msg.textContent = opts.handoffTimeoutMessage || 'Omlouváme se, operátor se vám nyní nemůže věnovat. Napište nám prosím zprávu nebo zanechte telefonní kontakt a ozveme se vám co nejdříve.';
        wrap.appendChild(msg);

        var actions = el('div', 'uxstudio-ais-handoff-timeout-actions');

        var emailLink = el('a', 'uxstudio-ais-handoff-timeout-link', { 'href': '#' });
        emailLink.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> Napsat zprávu';
        emailLink.addEventListener('click', function(e) {
            e.preventDefault();
            showErrorWithContactForm('Napište nám a my se vám ozveme co nejdříve.');
        });

        var phoneLink = el('a', 'uxstudio-ais-handoff-timeout-link', { 'href': '#' });
        phoneLink.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg> Zavolat zpět';
        phoneLink.addEventListener('click', function(e) {
            e.preventDefault();
            showPhoneCallbackForm('Zanechte nám telefonní číslo a my vám zavoláme zpět.');
        });

        var aiLink = el('a', 'uxstudio-ais-handoff-timeout-link', { 'href': '#' });
        aiLink.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Pokračovat s asistentem';
        aiLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (wrap.parentNode) {
                wrap.parentNode.removeChild(wrap);
            }
            // Odstranit marker z historie, aby se po reloadu znovu neobjevil
            messages = messages.filter(function(m) {
                return !(m.role === 'handoff-timeout' || (m.role === 'system' && m.text === 'handoff-timeout'));
            });
            var resumeText = 'S čím vám mohu pomoci?';
            addMessageToUI('bot', resumeText);
            messages.push({ role: 'bot', text: resumeText });
            saveMessages(messages);
            scrollToBottom();
            input.focus();
        });

        actions.appendChild(emailLink);
        actions.appendChild(phoneLink);
        actions.appendChild(aiLink);
        wrap.appendChild(actions);

        messagesArea.appendChild(wrap);
        scrollToBottom();

        if (!skipSave) {
            messages.push({ role: 'handoff-timeout', text: '' });
            saveMessages(messages);
        }
    }

    function pollHandoff() {
        if (isHandoffPolling) return; // race-condition guard
        isHandoffPolling = true;
        apiPostJson('/handoff/poll', { session_id: sessionId, since: handoffSince })
            .then(function(data) {
                if (!data) return;

                var prevStatus = handoffStatus;
                handoffStatus = data.handoff_status || 'ai';
                handoffAssignedTo = data.assigned_to || null;
                updateOperatorButtonVisibility();

                // Stav se změnil
                if (prevStatus !== handoffStatus) {
                    if (handoffStatus === 'ai' || handoffStatus === 'closed') {
                        // Operátor ukončil (vrátil AI) nebo konverzaci uzavřel úplně
                        hideHandoffBanner();
                        stopHandoffPolling();
                        clearHandoffTimeoutTimer();
                    } else if (handoffStatus === 'human') {
                        // Operátor se připojil - timer už nepotřeba
                        clearHandoffTimeoutTimer();
                        showHandoffBanner();
                        updateHandoffBanner();
                    } else {
                        showHandoffBanner();
                        updateHandoffBanner();
                    }
                }

                // Nové zprávy (idempotentní - klíč podle role+timestamp+content)
                if (data.messages && data.messages.length) {
                    data.messages.forEach(function(msg) {
                        renderHandoffMessage(msg);
                    });
                }
                if (typeof data.total_count === 'number' && data.total_count > handoffSince) {
                    handoffSince = data.total_count;
                }
            })
            .catch(function() { /* ignore */ })
            .then(function() { isHandoffPolling = false; });
    }

    function messageKey(msg) {
        var role = msg.role || 'user';
        var ts = msg.timestamp || msg.created_at || '';
        var content = (msg.content || msg.text || '').substring(0, 80);
        return role + '|' + ts + '|' + content;
    }

    function renderHandoffMessage(msg) {
        var role = msg.role || 'user';
        var content = (msg.content || msg.text || '').trim();
        if (!content) return;

        // Idempotentní render - duplicita se zahodí
        var key = messageKey(msg);
        if (renderedMessageKeys[key]) return;
        renderedMessageKeys[key] = true;

        if (role === 'system') {
            var sys = el('div', 'uxstudio-ais-handoff-system');
            sys.textContent = content;
            messagesArea.appendChild(sys);
            scrollToBottom();
            return;
        }

        if (role === 'agent') {
            playReceiveSound();
            var wrapper = el('div', 'uxstudio-ais-msg uxstudio-ais-msg--bot uxstudio-ais-msg--agent');
            var name = el('div', 'uxstudio-ais-msg-agent-name');
            name.textContent = msg.agent_name || 'Operátor';
            wrapper.appendChild(name);
            var textEl = el('div', 'uxstudio-ais-msg-text');
            textEl.textContent = content;
            wrapper.appendChild(textEl);
            messagesArea.appendChild(wrapper);
            messages.push({ role: 'agent', text: content, agent_name: msg.agent_name });
            saveMessages(messages);
            scrollToBottom();
        }
        // Zprávy 'user' jsou už v UI (odeslal je sám), 'assistant' sem nepřijde v handoff režimu
    }

    // Při obnovení session zkontroluj stav handoffu (např. po reloadu stránky)
    function checkHandoffOnLoad() {
        apiPostJson('/handoff/poll', { session_id: sessionId, since: 0 })
            .then(function(data) {
                if (!data) return;
                var status = data.handoff_status || 'ai';
                if (status === 'requested' || status === 'human') {
                    handoffStatus = status;
                    handoffAssignedTo = data.assigned_to || null;
                    updateOperatorButtonVisibility();

                    // Pokud je sessionStorage prázdný (nová záložka), zobrazíme historické
                    // system/agent zprávy, aby zákazník viděl průběh
                    if (messages.length === 0 && data.messages && data.messages.length) {
                        faqsLoaded = true; // potlačit úvodní pozdrav
                        data.messages.forEach(function(msg) {
                            var role = msg.role;
                            if (role === 'system' || role === 'agent') {
                                renderHandoffMessage(msg);
                            } else if (role === 'user') {
                                renderedMessageKeys[messageKey(msg)] = true;
                                addMessageToUI('user', msg.content || msg.text || '');
                            } else if (role === 'assistant') {
                                renderedMessageKeys[messageKey(msg)] = true;
                                addMessageToUI('bot', cleanBotText(msg.content || msg.text || ''));
                            }
                        });
                    } else if (data.messages && data.messages.length) {
                        // sessionStorage již obsahuje zprávy - označit jako vyrenderované
                        data.messages.forEach(function(msg) {
                            renderedMessageKeys[messageKey(msg)] = true;
                        });
                    }

                    handoffSince = data.total_count || 0;
                    showHandoffBanner();
                    startHandoffPolling();

                    // Spustit timeout timer se zbývajícím časem (zákazník mohl reloadnout stránku)
                    if (status === 'requested') {
                        var total = parseInt(opts.handoffTimeout, 10) || 0;
                        if (total > 0 && data.handoff_requested_at) {
                            var requestedAt = new Date(data.handoff_requested_at.replace(' ', 'T'));
                            if (!isNaN(requestedAt.getTime())) {
                                var elapsed = Math.floor((Date.now() - requestedAt.getTime()) / 1000);
                                var remaining = total - elapsed;
                                if (remaining <= 0) {
                                    handleHandoffTimeout();
                                } else {
                                    startHandoffTimeoutTimer(remaining);
                                }
                            } else {
                                startHandoffTimeoutTimer();
                            }
                        } else if (total > 0) {
                            startHandoffTimeoutTimer();
                        }
                    }
                }
            })
            .catch(function() {});
    }

    // ---------------------------------------------------------------------------
    // Send message
    // ---------------------------------------------------------------------------

    function sendMessage() {
        var text = input.value.trim();
        if (!text || isStreaming) return;

        clearInactivityTimer();

        // GDPR consent on first message
        ensureGdprConsent();

        input.value = '';
        autoResizeInput();

        playSendSound();

        // Add user message to UI and storage
        addMessageToUI('user', text);
        messages.push({ role: 'user', text: text });
        saveMessages(messages);

        // V handoff režimu posíláme přes handoff endpoint (bez AI)
        if (handoffStatus === 'requested' || handoffStatus === 'human') {
            sendHandoffMessage(text);
            return;
        }

        // Show typing indicator
        var typingEl = showTypingIndicator();
        isStreaming = true;
        sendBtn.disabled = true;

        // Prepare bot message container
        var botParts = createMessageEl('bot', '');
        currentBotEl = botParts.wrapper;
        currentBotTextEl = botParts.textEl;
        currentBotBuffer = '';
        var currentProducts = [];

        // SSE streaming via fetch + ReadableStream
        apiPost('/chat', {
            message: text,
            session_id: sessionId,
            page_url: window.location.href,
            product_id: cfg.productId || 0
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            removeTypingIndicator(typingEl);
            messagesArea.appendChild(currentBotEl);
            scrollToBottom();

            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var sseBuffer = '';

            function pump() {
                return reader.read().then(function(result) {
                    if (result.done) {
                        finishStream(currentProducts);
                        return;
                    }

                    sseBuffer += decoder.decode(result.value, { stream: true });

                    var lines = sseBuffer.split('\n\n');
                    sseBuffer = lines.pop();

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (!line.startsWith('data: ')) continue;

                        try {
                            var data = JSON.parse(line.slice(6));

                            if (data.text) {
                                currentBotBuffer += data.text;
                                currentBotTextEl.textContent = cleanBotText(currentBotBuffer);
                                scrollToBottom();
                            }

                            if (data.handoff_triggered) {
                                handoffStatus = 'requested';
                                updateOperatorButtonVisibility();
                                showHandoffBanner();
                                pendingHandoffSystemMessage = true;
                                // Polling spustíme až po dokončení streamu, aby se handoffSince
                                // správně nastavil na aktuální total_count včetně této odpovědi
                            }

                            if (data.products && Array.isArray(data.products)) {
                                loadProductCards(data.products, currentBotEl);
                                data.products.forEach(function(pid) {
                                    currentProducts.push(pid);
                                });
                            }

                            if (data.error) {
                                if (data.show_contact_form) {
                                    showErrorWithContactForm(data.error);
                                } else {
                                    showError(data.error);
                                }
                                finishStream([]);
                                return;
                            }

                            if (data.done) {
                                finishStream(currentProducts);
                                return;
                            }
                        } catch (_) { /* skip malformed JSON */ }
                    }

                    return pump();
                });
            }

            return pump();
        })
        .catch(function() {
            removeTypingIndicator(typingEl);
            showErrorWithContactForm('Omlouváme se, došlo k chybě při komunikaci se serverem.');
            finishStream([]);
        });
    }

    function finishStream(productIds) {
        isStreaming = false;
        sendBtn.disabled = false;

        // Play receive sound when bot finishes
        playReceiveSound();

        if (currentBotBuffer) {
            var storedMsg = {
                role: 'bot',
                text: cleanBotText(currentBotBuffer)
            };
            if (productIds && productIds.length > 0) {
                // Phase 1: Immediately save with product_ids (survives navigation)
                storedMsg.product_ids = productIds;
                storedMsg.products = [];
                messages.push(storedMsg);
                saveMessages(messages);

                // Phase 2: Fetch full product data, then update storage
                var fetches = productIds.map(function(id) {
                    return apiGet('/product-card/' + encodeURIComponent(id))
                        .then(function(product) {
                            storedMsg.products.push(product);
                        })
                        .catch(function() { /* ignore */ });
                });
                Promise.all(fetches).then(function() {
                    saveMessages(messages);
                });
            } else {
                messages.push(storedMsg);
                saveMessages(messages);
            }
        }

        currentBotEl = null;
        currentBotTextEl = null;
        currentBotBuffer = '';

        // Pokud AI spustila handoff, spustíme polling - system message dorazí z pollu
        if (pendingHandoffSystemMessage) {
            pendingHandoffSystemMessage = false;
            handoffSince = 0;
            startHandoffPolling();
        }

        exchangeCount++;
        startInactivityTimer();

        input.focus();
    }

    // ---------------------------------------------------------------------------
    // Error message
    // ---------------------------------------------------------------------------

    function showError(text) {
        var parts = createMessageEl('bot', text, 'uxstudio-ais-msg--error');
        messagesArea.appendChild(parts.wrapper);
        scrollToBottom();
    }

    function showErrorWithContactForm(errorText) {
        // Remove existing overlay if any
        var existing = panel.querySelector('.uxstudio-ais-contact-overlay');
        if (existing) existing.remove();

        // Overlay covers the entire body area (below header)
        var overlay = el('div', 'uxstudio-ais-contact-overlay');

        // Back button to return to chat
        var backBtn = el('button', 'uxstudio-ais-contact-back');
        backBtn.type = 'button';
        backBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg> Zpět na chat';
        backBtn.addEventListener('click', function() {
            overlay.remove();
        });

        // Title
        var title = el('div', 'uxstudio-ais-contact-title');
        title.textContent = errorText || 'Napište nám';

        // Form content
        var form = el('div', 'uxstudio-ais-contact-form');

        var nameLabel = el('label', 'uxstudio-ais-contact-label');
        nameLabel.textContent = 'Jméno';
        var nameInput = el('input', 'uxstudio-ais-contact-input', { type: 'text', placeholder: 'Vaše jméno' });

        var emailLabel = el('label', 'uxstudio-ais-contact-label');
        emailLabel.textContent = 'Email';
        var emailInput = el('input', 'uxstudio-ais-contact-input', { type: 'email', placeholder: 'vas@email.cz' });

        var phoneLabel = el('label', 'uxstudio-ais-contact-label');
        phoneLabel.textContent = 'Telefon';
        var phoneNote = el('small', 'uxstudio-ais-contact-note');
        phoneNote.textContent = ' (zavoláme vám zpět)';
        phoneLabel.appendChild(phoneNote);
        var phoneInput = el('input', 'uxstudio-ais-contact-input', { type: 'tel', placeholder: '+420 xxx xxx xxx' });

        var msgLabel = el('label', 'uxstudio-ais-contact-label');
        msgLabel.textContent = 'Zpráva';
        var msgTextarea = el('textarea', 'uxstudio-ais-contact-textarea', { rows: '3', placeholder: 'Vaše zpráva...' });

        // Předvyplnit posledními zprávami uživatele
        var prefill = [];
        for (var i = messages.length - 1; i >= 0 && prefill.length < 3; i--) {
            if (messages[i].role === 'user') {
                prefill.unshift(messages[i].text);
            }
        }
        if (prefill.length > 0) {
            msgTextarea.value = prefill.join('\n');
        }

        var submitBtn = el('button', 'uxstudio-ais-contact-submit');
        submitBtn.textContent = 'Odeslat zprávu';
        submitBtn.type = 'button';

        var status = el('span', 'uxstudio-ais-contact-status');

        submitBtn.addEventListener('click', function() {
            var name = nameInput.value.trim();
            var email = emailInput.value.trim();
            var msg = msgTextarea.value.trim();

            if (!name || !email || !msg) {
                status.textContent = 'Vyplňte všechna pole.';
                status.className = 'uxstudio-ais-contact-status uxstudio-ais-contact-status--error';
                return;
            }

            submitBtn.disabled = true;
            status.textContent = 'Odesílám...';
            status.className = 'uxstudio-ais-contact-status';

            fetch(restUrl + '/contact', {
                method: 'POST',
                headers: apiHeaders(true),
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: name,
                    email: email,
                    phone: phoneInput.value.trim(),
                    message: msg,
                    session_id: sessionId,
                    page_url: window.location.href
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    form.innerHTML = '';
                    var success = el('div', 'uxstudio-ais-contact-success');
                    success.textContent = 'Zpráva odeslána! Ozveme se vám co nejdříve.';
                    form.appendChild(success);
                    setTimeout(function() { overlay.remove(); }, 3000);
                } else {
                    status.textContent = data.error || 'Nepodařilo se odeslat.';
                    status.className = 'uxstudio-ais-contact-status uxstudio-ais-contact-status--error';
                    submitBtn.disabled = false;
                }
            })
            .catch(function() {
                status.textContent = 'Chyba připojení. Zkuste to znovu.';
                status.className = 'uxstudio-ais-contact-status uxstudio-ais-contact-status--error';
                submitBtn.disabled = false;
            });
        });

        form.appendChild(nameLabel);
        form.appendChild(nameInput);
        form.appendChild(emailLabel);
        form.appendChild(emailInput);
        form.appendChild(phoneLabel);
        form.appendChild(phoneInput);
        form.appendChild(msgLabel);
        form.appendChild(msgTextarea);
        form.appendChild(submitBtn);
        form.appendChild(status);

        overlay.appendChild(backBtn);
        overlay.appendChild(title);
        overlay.appendChild(form);

        // Insert overlay into panel (after header, covering body)
        panel.appendChild(overlay);
    }

    // ---------------------------------------------------------------------------
    // Phone callback form (simplified - just phone + note)
    // ---------------------------------------------------------------------------

    function showPhoneCallbackForm(titleText) {
        var existing = panel.querySelector('.uxstudio-ais-contact-overlay');
        if (existing) existing.remove();

        var overlay = el('div', 'uxstudio-ais-contact-overlay');

        var backBtn = el('button', 'uxstudio-ais-contact-back');
        backBtn.type = 'button';
        backBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg> Zpět na chat';
        backBtn.addEventListener('click', function() { overlay.remove(); });

        var title = el('div', 'uxstudio-ais-contact-title');
        title.textContent = titleText || 'Zavoláme vám zpět';

        var form = el('div', 'uxstudio-ais-contact-form');

        var phoneLabel = el('label', 'uxstudio-ais-contact-label');
        phoneLabel.textContent = 'Telefonní číslo';
        var phoneInput = el('input', 'uxstudio-ais-contact-input', { type: 'tel', placeholder: '+420 xxx xxx xxx' });

        var msgLabel = el('label', 'uxstudio-ais-contact-label');
        msgLabel.textContent = 'Poznámka';
        var noteLabel = el('small', 'uxstudio-ais-contact-note');
        noteLabel.textContent = ' (nepovinné)';
        msgLabel.appendChild(noteLabel);
        var msgTextarea = el('textarea', 'uxstudio-ais-contact-textarea', { rows: '2', placeholder: 'Kdy vám můžeme zavolat?' });

        var submitBtn = el('button', 'uxstudio-ais-contact-submit');
        submitBtn.textContent = 'Zavolat zpět';
        submitBtn.type = 'button';

        var status = el('span', 'uxstudio-ais-contact-status');

        submitBtn.addEventListener('click', function() {
            var phone = phoneInput.value.trim();
            if (!phone) {
                status.textContent = 'Vyplňte telefonní číslo.';
                status.className = 'uxstudio-ais-contact-status uxstudio-ais-contact-status--error';
                return;
            }

            submitBtn.disabled = true;
            status.textContent = 'Odesílám...';
            status.className = 'uxstudio-ais-contact-status';

            fetch(restUrl + '/callback', {
                method: 'POST',
                headers: apiHeaders(true),
                credentials: 'same-origin',
                body: JSON.stringify({
                    phone: phone,
                    message: msgTextarea.value.trim(),
                    session_id: sessionId,
                    page_url: window.location.href
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    form.innerHTML = '';
                    var success = el('div', 'uxstudio-ais-contact-success');
                    success.textContent = 'Děkujeme! Ozveme se vám co nejdříve.';
                    form.appendChild(success);
                    setTimeout(function() { overlay.remove(); }, 3000);
                } else {
                    status.textContent = data.error || 'Nepodařilo se odeslat.';
                    status.className = 'uxstudio-ais-contact-status uxstudio-ais-contact-status--error';
                    submitBtn.disabled = false;
                }
            })
            .catch(function() {
                status.textContent = 'Chyba připojení. Zkuste to znovu.';
                status.className = 'uxstudio-ais-contact-status uxstudio-ais-contact-status--error';
                submitBtn.disabled = false;
            });
        });

        form.appendChild(phoneLabel);
        form.appendChild(phoneInput);
        form.appendChild(msgLabel);
        form.appendChild(msgTextarea);
        form.appendChild(submitBtn);
        form.appendChild(status);

        overlay.appendChild(backBtn);
        overlay.appendChild(title);
        overlay.appendChild(form);
        panel.appendChild(overlay);
    }

    // ---------------------------------------------------------------------------
    // Star rating
    // ---------------------------------------------------------------------------

    var starSvgEmpty = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    var starSvgFull = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';

    function showStarRating() {
        if (ratingSubmitted) return;

        var wrap = el('div', 'uxstudio-ais-rating');
        var label = el('div', 'uxstudio-ais-rating-label');
        label.textContent = 'Jak hodnotíte naši pomoc?';
        wrap.appendChild(label);

        var starsRow = el('div', 'uxstudio-ais-rating-stars');
        var selectedRating = 0;
        var starBtns = [];

        function updateStars(hoverVal) {
            var val = hoverVal || selectedRating;
            for (var j = 0; j < 5; j++) {
                starBtns[j].innerHTML = j < val ? starSvgFull : starSvgEmpty;
                starBtns[j].classList.toggle('uxstudio-ais-rating-star--active', j < val);
            }
        }

        for (var i = 1; i <= 5; i++) {
            (function(rating) {
                var btn = el('button', 'uxstudio-ais-rating-star', { 'type': 'button', 'aria-label': rating + ' hvězd' });
                btn.innerHTML = starSvgEmpty;
                btn.addEventListener('mouseenter', function() { updateStars(rating); });
                btn.addEventListener('mouseleave', function() { updateStars(0); });
                btn.addEventListener('click', function() {
                    selectedRating = rating;
                    updateStars(0);
                    showFeedbackAndSubmit(rating, wrap);
                });
                starBtns.push(btn);
                starsRow.appendChild(btn);
            })(i);
        }

        wrap.appendChild(starsRow);
        messagesArea.appendChild(wrap);
        scrollToBottom();
    }

    function showFeedbackAndSubmit(rating, ratingWrap) {
        // Remove stars interaction
        var starsRow = ratingWrap.querySelector('.uxstudio-ais-rating-stars');
        if (starsRow) {
            var btns = starsRow.querySelectorAll('button');
            for (var i = 0; i < btns.length; i++) {
                btns[i].disabled = true;
            }
        }

        // Check if feedback area already exists
        if (ratingWrap.querySelector('.uxstudio-ais-rating-feedback')) return;

        var feedback = el('div', 'uxstudio-ais-rating-feedback');
        var textarea = el('textarea', 'uxstudio-ais-contact-textarea', { rows: '2', placeholder: 'Chcete něco doplnit? (volitelné)' });
        var submitBtn = el('button', 'uxstudio-ais-rating-submit');
        submitBtn.textContent = 'Odeslat hodnocení';
        submitBtn.type = 'button';

        submitBtn.addEventListener('click', function() {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Odesílám...';

            apiPost('/rating', {
                session_id: sessionId,
                rating: rating,
                feedback: textarea.value.trim()
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    ratingSubmitted = true;
                    sessionStorage.setItem('uxstudio_ais_rated', '1');
                    ratingWrap.innerHTML = '';
                    var thanks = el('div', 'uxstudio-ais-rating-thanks');
                    thanks.textContent = 'Děkujeme za vaše hodnocení!';
                    ratingWrap.appendChild(thanks);
                } else {
                    submitBtn.textContent = 'Odeslat hodnocení';
                    submitBtn.disabled = false;
                }
            })
            .catch(function() {
                submitBtn.textContent = 'Odeslat hodnocení';
                submitBtn.disabled = false;
            });
        });

        feedback.appendChild(textarea);
        feedback.appendChild(submitBtn);
        ratingWrap.appendChild(feedback);
        scrollToBottom();
    }

    // ---------------------------------------------------------------------------
    // Inactivity detection
    // ---------------------------------------------------------------------------

    function clearInactivityTimer() {
        if (inactivityTimer) {
            clearTimeout(inactivityTimer);
            inactivityTimer = null;
        }
    }

    function startInactivityTimer() {
        clearInactivityTimer();
        var timeout = parseInt(opts.inactivityTimeout, 10) || 0;
        if (timeout <= 0 || ratingSubmitted || exchangeCount < 1) return;
        // V handoff režimu inactivity neřídit - bot se neptá, když čekáme na operátora nebo mluvíme s ním
        if (handoffStatus === 'requested' || handoffStatus === 'human') return;
        inactivityTimer = setTimeout(showInactivityPrompt, timeout * 1000);
    }

    function showInactivityPrompt() {
        inactivityTimer = null;
        var promptText = 'Mohu vám ještě s něčím pomoci?';
        addMessageToUI('bot', promptText);
        messages.push({ role: 'bot', text: promptText });
        saveMessages(messages);

        var btnsWrap = el('div', 'uxstudio-ais-inactivity-btns');

        var yesBtn = el('button', 'uxstudio-ais-inactivity-btn uxstudio-ais-inactivity-btn--yes');
        yesBtn.textContent = 'Ano';
        yesBtn.type = 'button';

        var noBtn = el('button', 'uxstudio-ais-inactivity-btn uxstudio-ais-inactivity-btn--no');
        noBtn.textContent = 'Ne';
        noBtn.type = 'button';

        yesBtn.addEventListener('click', function() {
            btnsWrap.remove();
            addMessageToUI('bot', 'Zeptejte se na cokoliv!');
            messages.push({ role: 'bot', text: 'Zeptejte se na cokoliv!' });
            saveMessages(messages);
            input.focus();
            startInactivityTimer();
        });

        noBtn.addEventListener('click', function() {
            btnsWrap.remove();
            addMessageToUI('bot', 'Děkujeme za návštěvu! Budeme rádi za vaše hodnocení.');
            messages.push({ role: 'bot', text: 'Děkujeme za návštěvu! Budeme rádi za vaše hodnocení.' });
            saveMessages(messages);
            showStarRating();
        });

        btnsWrap.appendChild(yesBtn);
        btnsWrap.appendChild(noBtn);
        messagesArea.appendChild(btnsWrap);
        scrollToBottom();
    }

    // ---------------------------------------------------------------------------
    // Input handling
    // ---------------------------------------------------------------------------

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    sendBtn.addEventListener('click', function() {
        sendMessage();
    });

    function autoResizeInput() {
        input.style.height = 'auto';
        var maxHeight = 120;
        var newHeight = Math.min(input.scrollHeight, maxHeight);
        input.style.height = newHeight + 'px';
    }

    input.addEventListener('input', autoResizeInput);

    // ---------------------------------------------------------------------------
    // Keyboard: Escape closes panel
    // ---------------------------------------------------------------------------

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && panelOpen) {
            closePanel();
        }
    });

    // ---------------------------------------------------------------------------
    // Handoff: resume state on load (e.g. after a page reload while the
    // customer was already waiting for / talking to an operator)
    // ---------------------------------------------------------------------------

    checkHandoffOnLoad();

    // ---------------------------------------------------------------------------
    // Auto-open logic
    // ---------------------------------------------------------------------------

    var COOKIE_AUTO_DISMISSED = 'uxstudio_ais_auto_dismissed';

    function getAutoOpenCookie() {
        var match = document.cookie.match(new RegExp('(^| )' + COOKIE_AUTO_DISMISSED + '=([^;]+)'));
        return match ? match[2] : null;
    }

    function setAutoOpenCookie(days) {
        var d = new Date();
        d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = COOKIE_AUTO_DISMISSED + '=1;expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    if (opts.autoOpen && !getAutoOpenCookie()) {
        var autoOpenDelay = (opts.autoOpenDelay || 5) * 1000;
        setTimeout(function() {
            if (!panelOpen) {
                wasAutoOpened = true;
                openPanel(true); // skip sound - not a user gesture
            }
        }, autoOpenDelay);
    }

})();
