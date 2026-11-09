<?php

namespace App\Services\Projects;

/**
 * Genera el archivo integration.js que se añade (sin tocar la vista original)
 * para conectar los campos de la plantilla con el sistema.
 *
 * El JavaScript resultante:
 *  - localiza los campos por selector,
 *  - captura los valores al dispararse el envío,
 *  - arma el objeto de datos (o el valor único),
 *  - lo envía al endpoint del proyecto,
 *  - gestiona estado de carga, errores y respuesta,
 *  - evita envíos duplicados,
 *  - y permite continuar el flujo tras el envío.
 *
 * NO modifica el HTML, CSS ni el JavaScript recibido.
 */
class IntegrationJsGenerator
{
    public function generate(array $spec): string
    {
        $config = json_encode(
            [
                'endpoint' => $spec['endpoint'] ?? '',
                'targets' => array_values($spec['targets'] ?? []),
                'onSuccess' => $spec['on_success'] ?? ['message' => 'Enviado.', 'redirect' => null],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return <<<JS
/**
 * integration.js — generado automáticamente por el panel.
 * Conecta esta vista con el sistema SIN modificar su HTML, CSS ni JavaScript.
 * No edites este archivo a mano: se regenera al volver a analizar la vista.
 */
(function () {
    "use strict";

    var CONFIG = $config;

    if (!CONFIG.endpoint || !CONFIG.targets || !CONFIG.targets.length) {
        return;
    }

    // Token de sesión: identifica este navegador para no confundir un cliente con
    // otro y para que los datos lleguen al mismo registro. Llega por ?s= (enlace del
    // operador) o se recuerda en el navegador tras el primer envío.
    var SESSION_KEY = "ew_sess:" + CONFIG.endpoint.replace(/\/[^/]+\/submit$/, "");
    function currentSession() {
        try {
            var fromUrl = new URLSearchParams(window.location.search).get("s");
            if (fromUrl) { rememberSession(fromUrl); return fromUrl; }
            return localStorage.getItem(SESSION_KEY) || sessionStorage.getItem("sessionToken") || null;
        } catch (e) { return null; }
    }
    function rememberSession(token) {
        try {
            if (!token) return;
            localStorage.setItem(SESSION_KEY, token);
            // Misma clave que usa la pantalla de carga para identificar al usuario.
            sessionStorage.setItem("sessionToken", token);
        } catch (e) {}
    }

    function readValue(el) {
        if (!el) return "";
        if (el.type === "checkbox") return el.checked;
        if (el.type === "radio") {
            var checked = document.querySelector('input[name="' + el.name + '"]:checked');
            return checked ? checked.value : "";
        }
        return el.value != null ? el.value : "";
    }

    function fieldValue(f) {
        // Campo agrupado (varias cajas de un mismo código): concatena todas en orden.
        if (f.group) {
            var selectors = (f.selectors && f.selectors.length) ? f.selectors : [f.selector];
            var parts = [];
            if (selectors.length === 1) {
                // Un selector que puede coincidir con varias cajas (p. ej. mismo name).
                document.querySelectorAll(selectors[0]).forEach(function (el) {
                    parts.push(String(readValue(el)));
                });
            } else {
                selectors.forEach(function (sel) {
                    var el = document.querySelector(sel);
                    if (el) parts.push(String(readValue(el)));
                });
            }
            return parts.join("");
        }
        return readValue(document.querySelector(f.selector));
    }

    function collect(target) {
        var data = {};
        var missing = [];
        (target.fields || []).forEach(function (f) {
            var value = fieldValue(f);
            if (f.required && (value === "" || value === false)) {
                missing.push(f.send_as);
            }
            data[f.send_as] = value;
        });
        return { data: data, missing: missing };
    }

    function buildPayload(target, collected) {
        var base = { view_target: target.key, session: currentSession() };
        // Un solo campo suelto envía únicamente su valor; el resto, el objeto completo.
        if (target.mode === "single") {
            var keys = Object.keys(collected.data);
            base.value = collected.data[keys[0]];
            return base;
        }
        base.data = collected.data;
        return base;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute("content") : null;
    }

    function setBusy(trigger, busy) {
        if (!trigger) return;
        if (busy) {
            trigger.setAttribute("data-integration-busy", "1");
            if ("disabled" in trigger) trigger.disabled = true;
        } else {
            trigger.removeAttribute("data-integration-busy");
            if ("disabled" in trigger) trigger.disabled = false;
        }
    }

    function isBusy(trigger) {
        return trigger && trigger.getAttribute("data-integration-busy") === "1";
    }

    function notify(type, message) {
        // Usa SweetAlert2 si la vista lo incluye; si no, un aviso básico.
        if (window.Swal && typeof window.Swal.fire === "function") {
            window.Swal.fire({ icon: type, text: message, timer: type === "success" ? 2000 : undefined });
        } else if (type === "error") {
            window.alert(message);
        }
        document.dispatchEvent(new CustomEvent("integration:" + type, { detail: { message: message } }));
    }

    function send(target, trigger) {
        if (isBusy(trigger)) return; // evita envíos duplicados
        var collected = collect(target);
        if (collected.missing.length) {
            notify("error", "Faltan campos obligatorios: " + collected.missing.join(", "));
            return;
        }

        setBusy(trigger, true);
        var headers = { "Content-Type": "application/json", "Accept": "application/json" };
        var token = csrfToken();
        if (token) headers["X-CSRF-TOKEN"] = token;

        fetch(CONFIG.endpoint, {
            method: "POST",
            headers: headers,
            credentials: "same-origin",
            body: JSON.stringify(buildPayload(target, collected))
        })
            .then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (body) {
                    return { ok: res.ok, body: body };
                });
            })
            .then(function (r) {
                if (!r.ok) {
                    notify("error", (r.body && r.body.message) || "No se pudo enviar la información.");
                    return;
                }
                if (r.body && r.body.loading_token) {
                    rememberSession(r.body.loading_token);
                }
                document.dispatchEvent(new CustomEvent("integration:sent", { detail: r.body }));
                // Tras enviar, el cliente pasa a la pantalla de carga (si el proyecto la
                // tiene), donde espera a que el operador pida la siguiente vista.
                var next = (r.body && r.body.loading_url) || CONFIG.onSuccess.redirect;
                if (next) {
                    window.location.href = next;
                    return;
                }
                notify("success", CONFIG.onSuccess.message || "Enviado.");
            })
            .catch(function () {
                notify("error", "Error de conexión al enviar la información.");
            })
            .finally(function () {
                setBusy(trigger, false);
            });
    }

    function bind(target) {
        var trigger = target.trigger;
        if (!trigger || !trigger.selector) return;
        var el = document.querySelector(trigger.selector);
        if (!el) return;

        var eventName = trigger.type === "submit" ? "submit" : "click";
        el.addEventListener(eventName, function (ev) {
            // Conserva el comportamiento original pero evita el envío nativo del form.
            if (eventName === "submit") ev.preventDefault();
            send(target, el.tagName === "FORM" ? el.querySelector('[type="submit"],button') : el);
        });
    }

    function init() {
        CONFIG.targets.forEach(bind);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
JS;
    }
}
