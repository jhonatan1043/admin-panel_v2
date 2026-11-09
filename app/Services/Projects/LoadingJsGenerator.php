<?php

namespace App\Services\Projects;

/**
 * Genera el JavaScript de la pantalla de carga (loading) de un proyecto.
 *
 * Toma el enfoque de la pantalla de carga del cliente: usa el TOKEN DE SESIÓN del
 * navegador para identificar al usuario exacto y preguntar al servidor si el
 * operador ya pidió la siguiente vista para esa sesión. Cuando llega, navega hacia
 * ella. No depende de Telegram ni toca el HTML/CSS de la plantilla de carga.
 */
class LoadingJsGenerator
{
    public function generate(array $config): string
    {
        $cfg = json_encode([
            'interval' => max(1500, (int) ($config['interval'] ?? 4000)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<JS
/**
 * loading.js — generado automáticamente por el panel.
 * Pantalla de espera: con el token de sesión del navegador pregunta al servidor si
 * el operador ya pidió la siguiente vista para ESTE usuario exacto, y navega hacia
 * ella. No modifica el HTML ni el CSS de la plantilla de carga.
 */
(function () {
    "use strict";

    var CONFIG = $cfg;

    // Token de sesión: identifica al usuario exacto. Llega por ?s= (enlace del
    // operador) o ya está guardado en el navegador. Se recuerda en sessionStorage
    // para los siguientes pasos del flujo.
    function sessionToken() {
        var token = null;
        try {
            var fromUrl = new URLSearchParams(window.location.search).get("s");
            token = fromUrl || window.sessionStorage.getItem("sessionToken");
            if (token) window.sessionStorage.setItem("sessionToken", token);
        } catch (e) {}
        return token;
    }

    var token = sessionToken();
    if (!token) return; // sin sesión no hay a quién consultar

    var endpoint = "/api/solicitud/" + encodeURIComponent(token) + "/next";
    var stopped = false;

    // Si la plantilla de carga trae un elemento con [data-loading-status], se le
    // actualiza el texto; es opcional.
    function setStatus(text) {
        var el = document.querySelector("[data-loading-status]");
        if (el) el.textContent = text;
    }

    function poll() {
        if (stopped) return;
        fetch(endpoint, { headers: { "Accept": "application/json" }, cache: "no-store", credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (body) {
                if (body && body.url) {
                    stopped = true;
                    document.dispatchEvent(new CustomEvent("loading:next", { detail: body }));
                    setStatus("Continuando...");
                    window.location.assign(body.url);
                    return;
                }
                setStatus("Espera un momento");
                window.setTimeout(poll, CONFIG.interval);
            })
            .catch(function () {
                // Error de red: reintenta sin romper la espera.
                setStatus("Reintentando...");
                window.setTimeout(poll, CONFIG.interval);
            });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", poll);
    } else {
        poll();
    }
})();
JS;
    }
}
