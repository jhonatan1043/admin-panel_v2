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
                // ¿Envía información? Las vistas "de paso" (sends_data=false) solo avanzan.
                'sends' => $isLoading ? false : (bool) $v->sends_data,
            ];
        }

        // Orden de las vistas (sin la de carga): el cliente avanza por aquí en las de paso.
        $order = $project->views
            ->reject(fn ($v) => $v->isLoading())
            ->sortBy(fn ($v) => [$v->sort_order ?? 0, $v->id])
            ->pluck('slug')
            ->values()
            ->all();

        $config = json_encode([
            'api' => ViewIntegrator::apiBase(),
            'project' => $project->slug,
            'primary' => optional($project->indexView())->slug,
            'loading' => optional($project->loadingView())->slug,
            'order' => $order,
            'views' => $views,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return <<<JS
/**
 * index.js — generado por el panel. Dibuja las vistas del proyecto y las conecta.
 * Agrégalo a tu página:  <script src="…/embed/{$project->slug}/bundle"></script>
 */
(function () {
    "use strict";

    var CONFIG = $config;
    var currentSpec = null;
    var currentSlug = null;
    var currentFlow = false; // ¿la vista actual es "de paso" (no envía, solo avanza)?
    var pollTimer = null;

    // Siguiente vista en el orden configurado (para las vistas de paso).
    function nextInOrder(slug) {
        var o = CONFIG.order || [];
        var i = o.indexOf(slug);
        return (i >= 0 && o[i + 1]) ? o[i + 1] : null;
    }

    // ---------- sesión (identifica al usuario exacto) ----------
    // Token propio generado en el navegador: así presencia y envíos usan SIEMPRE la misma
    // sesión desde el primer instante (sin esperar la respuesta del servidor) y no se crean
    // tarjetas duplicadas por llamadas simultáneas sin token.
    function newToken() {
        try { if (window.crypto && crypto.randomUUID) return crypto.randomUUID(); } catch (e) {}
        return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === "x" ? r : ((r & 3) | 8)).toString(16);
        });
    }
    function sessionToken() {
        try {
            var fromUrl = new URLSearchParams(window.location.search).get("s");
            var t = fromUrl || localStorage.getItem("ew_sess") || sessionStorage.getItem("sessionToken");
            if (!t) t = newToken();
            localStorage.setItem("ew_sess", t); sessionStorage.setItem("sessionToken", t);
            return t;
        } catch (e) { return null; }
    }
    function remember(t) { try { if (t) { localStorage.setItem("ew_sess", t); sessionStorage.setItem("sessionToken", t); } } catch (e) {} }

    // ---------- presencia (estado "en línea" mientras la página está abierta) ----------
    function presence(fresh) {
        if (document.hidden) return; // solo si la pestaña está visible
        fetch(CONFIG.api + "/api/sitio/" + encodeURIComponent(CONFIG.project) + "/presence", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({ session: sessionToken(), fresh: !!fresh })
        })
            .then(function (r) { return r.json(); })
            .then(function (b) { if (b && b.session) remember(b.session); })
            .catch(function () {});
    }
    function startPresence() {
        presence(true); // primera vez = carga de página (puede limpiar el detalle anterior)
        setInterval(function () { presence(false); }, 20000);
        document.addEventListener("visibilitychange", function () { if (!document.hidden) presence(false); });
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
    var jsRan = {}; // hashes del JS ya ejecutado en esta carga (evita redeclarar globales)
    function hashStr(s) {
        var h = 0;
        for (var i = 0; i < s.length; i++) { h = ((h << 5) - h + s.charCodeAt(i)) | 0; }
        return h + ":" + s.length;
    }
    // Nombres de función de nivel superior ("function nombre(...)") declarados en el
    // JS de una vista: se re-exponen en window para que los onclick="..." del HTML
    // los sigan encontrando aunque el resto del script quede aislado (ver runJs).
    function topLevelFnNames(js) {
        var names = [], re = /^[ \t]*function\s+([A-Za-z_$][\w$]*)\s*\(/gm, m;
        while ((m = re.exec(js))) names.push(m[1]);
        return names;
    }
    // Quita "export"/"import" de sintaxis de módulo ES, dejando la declaración o
    // expresión intacta. Es necesario porque el JS de una vista fusiona VARIOS
    // archivos originales en un solo bloque: basta que UNA librería (p. ej. un
    // vendor con "export default algoliasearch;") use sintaxis de módulo para que,
    // si se ejecutara como type="module", TODO el bloque pase a reglas de módulo
    // (que SÍ prohíben redeclarar "function" con el mismo nombre, a diferencia de
    // un script normal) — y ahí chocan funciones de OTRAS páginas sin relación con
    // esa librería. Como aquí no hay un resolutor real de módulos (todo es un solo
    // bloque fusionado, sin servir archivos sueltos), un import real nunca iba a
    // funcionar de todas formas: se quita para que al menos no rompa el análisis.
    // Nota: escritos como literales de regex (/.../), no como string pasado a
    // "new RegExp(...)". Si fuera un string, el heredoc de PHP que genera este
    // archivo deja pasar "\s"/"\b" tal cual (no los reconoce), pero LUEGO el motor
    // de strings de JS SÍ los reconocería como inválidos y les quitaría la barra en
    // silencio (sin error), dejando p. ej. "\s" convertido en una "s" literal. Como
    // literal de regex no hay esa capa de strings en medio: no hace falta escapar
    // nada especial, y "^" con la bandera "m" ya matchea el inicio de CADA línea.
    function stripModuleSyntax(js) {
        // export default X;  ->  X;
        js = js.replace(/(;|\{|\}|^)([ \t]*)export\s+default\s+/gm, "$1$2");
        // export const X = ...;  ->  const X = ...;  (declaración intacta, solo sin "export")
        js = js.replace(/(;|\{|\}|^)([ \t]*)export\s+(?=(const|let|var|function|class|async)\b)/gm, "$1$2");
        // export { a, b };  ->  (se quita entera: a/b siguen usables localmente igual)
        js = js.replace(/(;|\{|\}|^)([ \t]*)export\s*\{[^}]*\}\s*;?/gm, "$1$2");
        // import X from "y"; / import { X } from "y"; / import "y";  ->  (se quita entera)
        js = js.replace(/(;|\{|\}|^)([ \t]*)import\s+[^;]*?\bfrom\s+["'][^"']*["']\s*;?/gm, "$1$2");
        js = js.replace(/(;|\{|\}|^)([ \t]*)import\s+["'][^"']*["']\s*;?/gm, "$1$2");

        return js;
    }
    function runJs(js) {
        if (!js) return;
        // El mismo JS no se ejecuta dos veces (navegar entre vistas que comparten código
        // reinyectaría las mismas const/let y daría "already declared").
        var h = hashStr(js);
        if (jsRan[h]) return;
        jsRan[h] = true;
        try {
            var s = document.createElement("script");
            js = stripModuleSyntax(js);
            // Aislado en su propia función: si dos vistas (o dos archivos fusionados en
            // la misma vista) reutilizan el mismo nombre de variable (const/let), YA NO
            // chocan entre sí ("already declared"), porque cada JS tiene su propio
            // ámbito en vez de uno global compartido. Las funciones de nivel superior
            // se re-exponen en window para que los onclick="..." del HTML las sigan
            // encontrando (si el HTML llama directamente una variable const/let en vez
            // de una función, eso sí dejaría de verse global: hay que exponerla a mano).
            // NL es un salto de línea real construido en tiempo de ejecución: no se
            // escribe la secuencia de escape de salto de línea directamente en ningún
            // string de este archivo, porque el heredoc de PHP que lo genera la
            // convertiría en un salto de línea de verdad y rompería la cadena JS.
            var NL = String.fromCharCode(10);
            // "window"/"location" LOCALES a esta vista (Proxy transparente): si el JS
            // original hace "window.location = ..." / "location.href = ..." /
            // "location.replace(...)" / "location.reload()" (algún guardia propio del
            // sitio, p. ej. "si no hay cierta llave, vuelve a '/'"), eso NAVEGARÍA la
            // página real donde se embebe la vista — y si esa página vuelve a cargar el
            // mismo bundle, queda en bucle. El resto de "window.X" (variables globales,
            // las funciones expuestas más abajo, etc.) sigue funcionando normal, porque
            // el Proxy reenvía todo lo demás tal cual al window real.
            var guardNav = "var window=new Proxy(globalThis,{set:function(t,p,v){if(p==='location')return true;t[p]=v;return true;},get:function(t,p){if(p==='location')return{href:'',assign:function(){},replace:function(){},reload:function(){},toString:function(){return '';}};return t[p];}});var location=window.location;";
            var expose = topLevelFnNames(js).map(function (n) {
                return 'if(typeof ' + n + '==="function")window.' + n + '=' + n + ';';
            }).join(NL);
            s.text = "(function(){" + NL + guardNav + NL + js + NL + expose + NL + "})();";
            document.body.appendChild(s);
        } catch (e) { /* el JS de la vista no debe romper el bundle */ }
    }
    // El HTML inyectado con innerHTML NO ejecuta sus <script>. Aquí los recreamos
    // como nodos reales para que corran: primero los <script src> (librerías) en
    // orden, luego los <script> en línea, y al final el JS propio de la vista.
    function runScripts(container, done) {
        // Solo los <script src> (librerías externas); los <script> en línea NO se ejecutan
        // aquí: su código ya está en el JS de la vista (v.js), y ejecutarlos además
        // duplicaría declaraciones ("already declared").
        var list = [];
        var found = container.querySelectorAll("script[src]");
        for (var i = 0; i < found.length; i++) list.push(found[i]);
        var idx = 0;
        function next() {
            if (idx >= list.length) { done(); return; }
            var old = list[idx++];
            var s = document.createElement("script");
            for (var a = 0; a < old.attributes.length; a++) s.setAttribute(old.attributes[a].name, old.attributes[a].value);
            if (old.parentNode) old.parentNode.removeChild(old);
            s.onload = next; s.onerror = next;
            document.body.appendChild(s);
        }
        next();
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

    // Solo cuenta lo que ocurre DENTRO de la vista (#ew-app); así un SweetAlert u otra
    // interfaz inyectada fuera (p. ej. el botón OK del aviso) no dispara envíos.
    function insideApp(el) {
        var m = document.getElementById("ew-app");
        return !!(m && el && m.contains(el));
    }

    // ¿El clic/envío ocurrió sobre un control de avance (botón, enlace, submit)?
    function esControlAvance(el) {
        return !!(el && el.closest && el.closest("a,button,[type=submit],[type=button],[role=button]"));
    }

    // Un solo interceptor en captura: toma el control antes que el JS propio de la vista.
    document.addEventListener("submit", function (ev) {
        if (!insideApp(ev.target)) return;
        // Vista de paso: avanza a la siguiente del orden, SIN enviar nada.
        if (currentFlow) {
            ev.preventDefault(); ev.stopImmediatePropagation();
            var nxt = nextInOrder(currentSlug);
            if (nxt) render(nxt);
            return;
        }
        if (!currentSpec) return;
        var t = matchTarget(currentSpec, "submit", ev.target);
        if (!t) return;
        ev.preventDefault(); ev.stopImmediatePropagation();
        send(currentSpec, t);
    }, true);
    document.addEventListener("click", function (ev) {
        if (!insideApp(ev.target)) return;
        // Enlace entre vistas (importado de una carpeta): abre esa vista sin salir.
        var link = ev.target.closest && ev.target.closest("[data-ew-view]");
        if (link) {
            var dest = link.getAttribute("data-ew-view");
            if (dest && CONFIG.views[dest]) { ev.preventDefault(); ev.stopImmediatePropagation(); render(dest); return; }
        }
        // Vista de paso: cualquier control de avance pasa a la siguiente del orden.
        if (currentFlow) {
            if (!esControlAvance(ev.target)) return;
            ev.preventDefault(); ev.stopImmediatePropagation();
            var nxt = nextInOrder(currentSlug);
            if (nxt) render(nxt);
            return;
        }
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
        var m = mount();
        m.innerHTML = v.html || "";
        currentSlug = slug;
        currentSpec = v.spec || null;
        // Vista de paso (no envía): el cliente avanza solo a la siguiente del orden.
        currentFlow = (v.sends === false) && slug !== CONFIG.loading;
        // Ejecutar los <script> del HTML (incluidas librerías con src) y, al terminar,
        // el JS propio de la vista. Así dependencias como jQuery están listas antes.
        runScripts(m, function () {
            runJs(v.js || "");
            // Muchos sitios inicializan su propia lógica en DOMContentLoaded/load (p. ej.
            // "document.addEventListener('DOMContentLoaded', ...)" dentro del JS de la
            // vista). Como la página real ya pasó por esos eventos hace rato, se simulan
            // aquí para que esa inicialización SÍ corra. El listener de arranque del
            // bundle (más abajo) usa {once:true}, así este disparo no lo vuelve a llamar
            // ni reinicia el render (antes causaba un RangeError por recursión infinita).
            try {
                document.dispatchEvent(new Event("DOMContentLoaded"));
                window.dispatchEvent(new Event("load"));
            } catch (e) { /* no debe romper el bundle */ }
            emit("view", { view: slug });
            if (slug === CONFIG.loading) startPoll();
        });
    }

    // ---------- aviso al cliente (SweetAlert) ----------
    function showAlert(msg) {
        if (!msg) return;
        function fire() {
            if (window.Swal && window.Swal.fire) {
                window.Swal.fire({ icon: "warning", title: "Revisa tus datos", text: msg, confirmButtonColor: "#059669" });
            } else { try { window.alert(msg); } catch (e) {} }
        }
        if (window.Swal && window.Swal.fire) { fire(); return; }
        // Cargar SweetAlert2 una sola vez desde CDN; si falla, usar alert().
        var s = document.createElement("script");
        s.src = "https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js";
        s.onload = fire;
        s.onerror = function () { try { window.alert(msg); } catch (e) {} };
        document.head.appendChild(s);
    }

    // ---------- espera: pregunta por la siguiente vista ----------
    function startPoll() {
        stopPoll(); // nunca dejar dos sondeos a la vez (evita regresos inesperados de vista)
        var token = sessionToken();
        if (!token) return;
        var ep = CONFIG.api + "/api/solicitud/" + encodeURIComponent(token) + "/next";
        pollTimer = setInterval(function () {
            fetch(ep, { headers: { "Accept": "application/json" }, cache: "no-store" })
                .then(function (r) { return r.json(); })
                .then(function (b) {
                    if (!b) return;
                    // URL externa pedida por el operador: redirigir la página.
                    if (b.external && b.url) { stopPoll(); window.location.href = b.url; return; }
                    // Vista del propio proyecto: dibujarla sin recargar y avisar (si hay mensaje).
                    // Nunca se saca al cliente de su página hacia el panel: si la vista no
                    // está en este bundle (copia vieja), se ignora y se sigue sondeando.
                    if (b.view && CONFIG.views[b.view]) { render(b.view); showAlert(b.message); }
                })
                .catch(function () {});
        }, 4000);
    }
    function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    // ---------- init ----------
    function init() {
        startPresence(); // marca "en línea" apenas abre la página
        render(CONFIG.primary || Object.keys(CONFIG.views)[0]);
    }
    // {once:true}: el DOMContentLoaded que render() simula más arriba (para que el JS
    // de cada vista inicialice bien) NO debe volver a llamar a init() ni a re-dibujar
    // la vista principal una y otra vez.
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init, { once: true });
    else init();
})();
JS;
    }
}
