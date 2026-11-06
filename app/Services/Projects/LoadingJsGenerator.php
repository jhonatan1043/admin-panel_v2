<?php

namespace App\Services\Projects;

/**
 * Genera el JavaScript de la pantalla de carga (loading) de un proyecto.
 *
 * La vista de carga solo trae HTML y CSS propios; este script —añadido aparte,
 * sin tocar esa plantilla— mantiene la espera: lee el token de sesión de la URL
 * (?s=...), consulta al servidor si el operador ya pidió la siguiente vista y,
 * cuando llega, navega hacia ella conservando la sesión.
 */
class LoadingJsGenerator
{
    public function generate(array $config): string
    {
        $cfg = json_encode([
            'project' => $config['project'] ?? '',
            'interval' => max(1500, (int) ($config['interval'] ?? 4000)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<JS
/**
 * loading.js — generado automáticamente por el panel.
 * Pantalla de espera: consulta si el operador ya pidió la siguiente vista.
 * No modifica el HTML ni el CSS de la plantilla de carga.
 */
(function () {
    "use strict";

    var CONFIG = $cfg;

    function sessionToken() {
        var params = new URLSearchParams(window.location.search);
        return params.get("s") || "";
    }

    var token = sessionToken();
    if (!CONFIG.project || !token) {
        return; // sin sesión no hay nada que esperar
    }

    var endpoint = "/api/sitio/" + encodeURIComponent(CONFIG.project) + "/" + encodeURIComponent(token) + "/next";
    var stopped = false;

    function poll() {
        if (stopped) return;
        fetch(endpoint, { headers: { "Accept": "application/json" }, credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (body) {
                if (body && body.url) {
                    stopped = true;
                    document.dispatchEvent(new CustomEvent("loading:next", { detail: body }));
                    window.location.href = body.url;
                    return;
                }
                setTimeout(poll, CONFIG.interval);
            })
            .catch(function () {
                // Error de red: reintenta sin romper la espera.
                setTimeout(poll, CONFIG.interval);
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
