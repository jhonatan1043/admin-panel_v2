<?php

namespace App\Services\Projects;

use App\Models\Project;

/**
 * Genera un ÚNICO archivo index.js por proyecto. Ese archivo lleva dentro, desde la
 * base de datos, el HTML/CSS/JS de todas las vistas (la principal y las demás) y:
 *  - dibuja la vista principal al cargar,
 *  - captura y envía los datos a la API del panel,
 *  - al enviar, muestra la pantalla de carga y pregunta por la siguiente vista,
 *  - dibuja la siguiente vista (sin recargar) cuando el operador la pide.
 *
 * El cliente solo agrega <script src=".../index.js"> en su página (en su hosting).
 * Todo es dinámico: no se guardan archivos, el contenido vive en la base.
 */
class ProjectBundleGenerator
{
    public function generate(Project $project): string
    {
        $project->loadMissing('views');

        $views = [];
        foreach ($project->views as $v) {
            $isLoading = $v->isLoading();
            $views[$v->slug] = [
                'css' => (string) $v->css,
                'html' => (string) $v->html,
                'js' => $isLoading ? '' : (string) $v->js,
                'spec' => $isLoading ? null : ($v->spec ?? null),
            ];
        }

        $config = json_encode([
            'api' => ViewIntegrator::apiBase(),
            'project' => $project->slug,
            'primary' => optional($project->indexView())->slug,
            'loading' => optional($project->loadingView())->slug,
            'views' => $views,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return <<<JS
/**
 * index.js — generado por el panel. Dibuja las vistas del proyecto y las conecta.
 * Agrégalo a tu página:  <script src="…/embed/{$project->slug}/index.js"></script>
 */
(function () {
    "use strict";

    var CONFIG = $config;
    var currentSpec = null;
    var pollTimer = null;

    // ---------- sesión (identifica al usuario exacto) ----------
    function sessionToken() {
        try {
            var fromUrl = new URLSearchParams(window.location.search).get("s");
            var t = fromUrl || localStorage.getItem("ew_sess") || sessionStorage.getItem("sessionToken");
            if (t) { localStorage.setItem("ew_sess", t); sessionStorage.setItem("sessionToken", t); }
            return t || null;
        } catch (e) { return null; }
    }
    function remember(t) { try { if (t) { localStorage.setItem("ew_sess", t); sessionStorage.setItem("sessionToken", t); } } catch (e) {} }

    // ---------- presencia (estado "en línea" mientras la página está abierta) ----------
    function presence() {
        if (document.hidden) return; // solo si la pestaña está visible
        fetch(CONFIG.api + "/api/sitio/" + encodeURIComponent(CONFIG.project) + "/presence", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({ session: sessionToken() })
        })
            .then(function (r) { return r.json(); })
            .then(function (b) { if (b && b.session) remember(b.session); })
            .catch(function () {});
    }
    function startPresence() {
        presence();
        setInterval(presence, 20000);
        document.addEventListener("visibilitychange", function () { if (!document.hidden) presence(); });
    }

    // ---------- montaje ----------
    function mount() {
        var m = document.getElementById("ew-app");
        if (!m) { m = document.createElement("div"); m.id = "ew-app"; document.body.appendChild(m); }
        return m;
    }
    function applyCss(css) {
        var old = document.getElementById("ew-style");
        if (old) old.parentNode.removeChild(old);
        if (css) { var s = document.createElement("style"); s.id = "ew-style"; s.textContent = css; document.head.appendChild(s); }
    }
    function runJs(js) {
        if (!js) return;
        try { (new Function(js)).call(window); } catch (e) { /* el JS de la vista no debe romper el bundle */ }
    }

    // ---------- captura ----------
    function readValue(el) {
        if (!el) return "";
        if (el.type === "checkbox") return el.checked;
        if (el.type === "radio") { var c = document.querySelector('input[name="' + el.name + '"]:checked'); return c ? c.value : ""; }
        return el.value != null ? el.value : "";
    }
    function fieldValue(f) {
        if (f.group) {
            var sels = (f.selectors && f.selectors.length) ? f.selectors : [f.selector];
            var parts = [];
            if (sels.length === 1) { document.querySelectorAll(sels[0]).forEach(function (el) { parts.push(String(readValue(el))); }); }
            else { sels.forEach(function (s) { var el = document.querySelector(s); if (el) parts.push(String(readValue(el))); }); }
            return parts.join("");
        }
        return readValue(document.querySelector(f.selector));
    }
    function collect(t) {
        var data = {}, missing = [];
        (t.fields || []).forEach(function (f) {
            var v = fieldValue(f);
            if (f.required && (v === "" || v === false)) missing.push(f.send_as);
            data[f.send_as] = v;
        });
        return { data: data, missing: missing };
    }
    function payload(t, c) {
        var base = { view_target: t.key, session: sessionToken() };
        if (t.mode === "single") { var k = Object.keys(c.data); base.value = c.data[k[0]]; }
        else base.data = c.data;
        return base;
    }
    function matchTarget(spec, type, el) {
        for (var i = 0; i < (spec.targets || []).length; i++) {
            var t = spec.targets[i];
            if (!t.trigger || t.trigger.type !== type || !t.trigger.selector) continue;
            if (type === "submit") { if (el.matches && el.matches(t.trigger.selector)) return t; }
            else if (el.closest && el.closest(t.trigger.selector)) return t;
        }
        return null;
    }

    function send(spec, target) {
        var c = collect(target);
        if (c.missing.length) { emit("error", "Faltan campos: " + c.missing.join(", ")); return; }
        fetch(spec.endpoint, {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify(payload(target, c))
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (r) {
                if (!r.ok) { emit("error", (r.body && r.body.message) || "No se pudo enviar."); return; }
                if (r.body && r.body.loading_token) remember(r.body.loading_token);
                emit("sent", r.body);
                // Tras enviar: mostrar la pantalla de carga del proyecto (si existe).
                if (CONFIG.loading && CONFIG.views[CONFIG.loading]) render(CONFIG.loading);
                else emit("success", r.body);
            })
            .catch(function () { emit("error", "Error de conexión."); });
    }

    function emit(type, detail) { document.dispatchEvent(new CustomEvent("ew:" + type, { detail: detail })); }

    // Un solo interceptor en captura: toma el control antes que el JS propio de la vista.
    document.addEventListener("submit", function (ev) {
        if (!currentSpec) return;
        var t = matchTarget(currentSpec, "submit", ev.target);
        if (!t) return;
        ev.preventDefault(); ev.stopImmediatePropagation();
        send(currentSpec, t);
    }, true);
    document.addEventListener("click", function (ev) {
        if (!currentSpec) return;
        var t = matchTarget(currentSpec, "click", ev.target);
        if (!t) return;
        ev.preventDefault(); ev.stopImmediatePropagation();
        send(currentSpec, t);
    }, true);

    // ---------- render de una vista ----------
    function render(slug) {
        var v = CONFIG.views[slug];
        if (!v) return;
        stopPoll();
        applyCss(v.css || "");
        mount().innerHTML = v.html || "";
        currentSpec = v.spec || null;
        runJs(v.js || "");
        emit("view", { view: slug });
        if (slug === CONFIG.loading) startPoll();
    }

    // ---------- espera: pregunta por la siguiente vista ----------
    function startPoll() {
        var token = sessionToken();
        if (!token) return;
        var ep = CONFIG.api + "/api/solicitud/" + encodeURIComponent(token) + "/next";
        pollTimer = setInterval(function () {
            fetch(ep, { headers: { "Accept": "application/json" }, cache: "no-store" })
                .then(function (r) { return r.json(); })
                .then(function (b) { if (b && b.view && CONFIG.views[b.view]) render(b.view); })
                .catch(function () {});
        }, 4000);
    }
    function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    // ---------- init ----------
    function init() {
        startPresence(); // marca "en línea" apenas abre la página
        render(CONFIG.primary || Object.keys(CONFIG.views)[0]);
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
    else init();
})();
JS;
    }
}
