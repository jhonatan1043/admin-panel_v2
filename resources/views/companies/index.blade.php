@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
    {{-- Alarma: aparece cuando una opción con vencimiento ya venció. Suena 30s. --}}
    <div id="alarm-banner"
         class="hidden fixed top-16 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 rounded-lg
                border border-red-500 bg-red-600/90 text-white px-4 py-2 text-sm shadow-lg shadow-red-900/40">
        <span class="text-lg animate-pulse">⏰</span>
        <span id="alarm-text">Opción vencida</span>
        <button id="alarm-silence" type="button"
                class="ml-1 rounded bg-white/15 hover:bg-white/25 px-2 py-1 text-xs font-semibold">
            Silenciar
        </button>
    </div>

    {{-- Filtro: por empresa y por búsqueda (nombre o registro). Estático: no se recarga con el polling. --}}
    @if ($companies->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 mb-5 text-xs">
            <select id="filtro-empresa"
                    class="rounded bg-slate-800 border border-slate-600 px-2 py-1.5 text-slate-200">
                <option value="">Todas las empresas</option>
                @foreach ($companiesByEmpresa->keys() as $emp)
                    <option value="{{ $emp }}">{{ $emp }}</option>
                @endforeach
            </select>
            <input id="filtro-busqueda" type="search" autocomplete="off"
                   placeholder="Buscar por nombre o registro…"
                   class="rounded bg-slate-800 border border-slate-600 px-3 py-1.5 text-slate-200 flex-1 min-w-[180px]">
            <span id="filtro-vacio" class="hidden text-slate-500">Sin coincidencias.</span>
            <span id="live-dot" class="ml-auto flex items-center gap-1.5 text-[10px] text-slate-500" title="Actualización en vivo">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> en vivo
            </span>
        </div>
    @endif

    {{-- Control del aviso sonoro: activo / silenciar por X minutos. --}}
    <div class="flex items-center justify-end mb-3">
        <button id="btn-sonido" type="button"
                class="text-xs border border-slate-600 text-slate-300 hover:bg-white/5 rounded px-3 py-1.5">
            🔔 Sonido
        </button>
    </div>

    {{-- La grilla se refresca sola por polling; su contenido viene de companies._board. --}}
    <div id="board">
        @include('companies._board')
    </div>

    <script>
        // Presionar un botón: envía directo el texto al Telegram del cliente (sin confirmar).
        // El botón queda deshabilitado mientras se envía y se habilita al mostrar el resultado.
        function sendMessage(e, btn) {
            e.stopPropagation();
            if (btn.disabled) return; // evita doble envío
            var company = btn.dataset.company;
            var buttonId = btn.dataset.button;

            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'wait';

            function habilitar() {
                btn.disabled = false;
                btn.style.opacity = '';
                btn.style.cursor = '';
            }

            fetch('/api/companies/' + company + '/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({ button_id: Number(buttonId) }),
            }).then(async function (res) {
                var data = await res.json().catch(function () { return {}; });
                if (res.ok && data.ok) {
                    Swal.fire({ icon: 'success', title: 'Enviado', text: data.message || 'Mensaje enviado.', timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'No se pudo enviar', text: data.message || ('Error ' + res.status) });
                }
            }).catch(function () {
                Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo contactar al servidor.' });
            }).finally(habilitar);
        }

        // Delegación: abre solo la tarjeta seleccionada, el resto se cierra.
        document.addEventListener('click', function (e) {
            var card = e.target.closest('.client-card');
            if (!card) return;
            if (e.target.closest('form')) return; // no togglear al usar "borrar"

            var willOpen = !card.classList.contains('is-open');
            document.querySelectorAll('.client-card.is-open').forEach(function (c) {
                c.classList.remove('is-open');
            });
            if (willOpen) {
                card.classList.add('is-open');
            }
        });

        // Filtro del tablero: por empresa y por texto (nombre o registro).
        function aplicarFiltro() {
            var selEmpresa = document.getElementById('filtro-empresa');
            var inpBusqueda = document.getElementById('filtro-busqueda');
            var vacio = document.getElementById('filtro-vacio');
            if (!selEmpresa) return;

            var emp = selEmpresa.value.toLowerCase();
            var q = (inpBusqueda.value || '').trim().toLowerCase();
            var totalVisibles = 0;

            document.querySelectorAll('.empresa-group').forEach(function (sec) {
                var empOk = !emp || (sec.dataset.empresa || '').toLowerCase() === emp;
                var visibles = 0;

                sec.querySelectorAll('.client-card').forEach(function (card) {
                    var match = empOk && (!q || (card.dataset.search || '').indexOf(q) !== -1);
                    card.style.display = match ? '' : 'none';
                    if (match) visibles++;
                });

                // Al buscar, abre "OTROS" para revelar coincidencias ocultas.
                sec.querySelectorAll('details').forEach(function (d) { d.open = !!q; });

                sec.style.display = (empOk && visibles > 0) ? '' : 'none';
                totalVisibles += visibles;
            });

            if (vacio) vacio.classList.toggle('hidden', totalVisibles > 0);
        }

        (function () {
            var selEmpresa = document.getElementById('filtro-empresa');
            var inpBusqueda = document.getElementById('filtro-busqueda');
            if (selEmpresa) selEmpresa.addEventListener('change', aplicarFiltro);
            if (inpBusqueda) inpBusqueda.addEventListener('input', aplicarFiltro);
        })();

        // Tablero en vivo: refresca la grilla cada 5s sin recargar la página.
        (function () {
            var BOARD_URL = @json(route('companies.board'));
            var INTERVALO = 5000;

            function refrescar() {
                // No interrumpir si hay una tarjeta abierta o un diálogo (SweetAlert).
                if (document.querySelector('.client-card.is-open')) return;
                if (document.querySelector('.swal2-container')) return;

                fetch(BOARD_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (res) { return res.ok ? res.text() : null; })
                    .then(function (html) {
                        if (html === null) return;
                        var board = document.getElementById('board');
                        if (!board) return;
                        board.innerHTML = html;
                        aplicarFiltro(); // mantener el filtro activo tras refrescar
                    })
                    .catch(function () { /* silencio: reintenta en el próximo ciclo */ });
            }

            setInterval(refrescar, INTERVALO);
        })();

        // Conversación en vivo: mientras una tarjeta está abierta, refresca solo su detalle.
        (function () {
            function refrescarDetalle() {
                var abierta = document.querySelector('.client-card.is-open');
                if (!abierta) return;
                if (document.querySelector('.swal2-container')) return;

                var cont = abierta.querySelector('.card-detail');
                if (!cont || !cont.dataset.detail) return;

                fetch('/companies/' + cont.dataset.detail + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (res) { return res.ok ? res.text() : null; })
                    .then(function (html) {
                        if (html === null || !abierta.classList.contains('is-open')) return;
                        var prev = cont.querySelector('.detail-scroll');
                        var top = prev ? prev.scrollTop : 0;
                        cont.innerHTML = html;
                        var nuevo = cont.querySelector('.detail-scroll');
                        if (nuevo) nuevo.scrollTop = top; // conservar la posición de lectura
                    })
                    .catch(function () { /* silencio */ });
            }

            setInterval(refrescarDetalle, 4000);
        })();

        // Alarma de vencimiento: cuando una opción con vencimiento ya venció, avisa
        // con un sonido (beeps) durante 30s y marca la tarjeta hasta que se silencie.
        (function () {
            var DURACION_MS = 30000;   // la alarma (sonido + aviso) dura 30s y luego se quita sola
            var acked = new Set();     // vencimientos ya silenciados
            var seen = new Set();      // vencimientos ya detectados (para no re-sonar)
            var episodeUntil = {};     // clave => instante en que la alarma se autolimpia
            var soundUntil = 0;        // hasta cuándo debe sonar
            var beepTimer = null;
            var audioCtx = null;

            function getCtx() {
                if (!audioCtx) {
                    var AC = window.AudioContext || window.webkitAudioContext;
                    if (AC) audioCtx = new AC();
                }
                if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
                return audioCtx;
            }

            function beep() {
                var ctx = getCtx();
                if (!ctx) return;
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'square';
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25);
                osc.connect(gain).connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.26);
            }

            function startBeeping() {
                if (beepTimer) return;
                beep();
                beepTimer = setInterval(beep, 600);
            }

            function stopBeeping() {
                if (beepTimer) { clearInterval(beepTimer); beepTimer = null; }
            }

            // Desbloquea el audio en la primera interacción del operador.
            document.addEventListener('click', function () { getCtx(); }, { once: true });

            function deadlineDe(card) {
                // Si la tarjeta está abierta, el detalle trae el valor más fresco.
                if (card.classList.contains('is-open')) {
                    var marca = card.querySelector('.detail-alarm[data-alarm-at]');
                    if (marca) return marca.getAttribute('data-alarm-at');
                }
                return card.dataset.alarmAt || null;
            }

            // Pinta el estado del vencimiento en la tarjeta:
            //  - 'contando': badge ámbar con el tiempo restante (conteo en vivo).
            //  - 'vencida':  badge rojo "VENCIDO" + resalte de la tarjeta.
            //  - 'ninguno':  limpia todo.
            function pintarCuenta(card, estado, restanteMs, silenciada) {
                var badge = card.querySelector('.alarm-badge');

                // El borde morado solo va mientras cuenta (titila en los últimos 10s).
                var terminando = estado === 'contando' && restanteMs <= 10000;
                card.classList.toggle('is-counting', estado === 'contando' && !terminando);
                card.classList.toggle('is-ending', terminando);

                if (estado === 'ninguno') {
                    card.classList.remove('is-expired');
                    if (badge) badge.remove();
                    return;
                }

                if (!badge) {
                    badge = document.createElement('div');
                    badge.className = 'alarm-badge';
                    card.appendChild(badge);
                }

                if (estado === 'vencida') {
                    if (silenciada) {
                        // Silenciada: sin parpadeo, solo una marca tenue de que venció.
                        card.classList.remove('is-expired');
                        badge.className = 'alarm-badge absolute top-1 right-1 z-10 rounded bg-red-900/70 text-red-200 ' +
                                          'text-[9px] font-bold px-1.5 py-0.5';
                        badge.textContent = '⏰ vencido';
                    } else {
                        card.classList.add('is-expired');
                        badge.className = 'alarm-badge absolute top-1 right-1 z-10 rounded bg-red-600 text-white ' +
                                          'text-[9px] font-bold px-1.5 py-0.5 animate-pulse';
                        badge.textContent = '⏰ VENCIDO';
                    }
                } else { // contando
                    card.classList.remove('is-expired');
                    var s = Math.max(0, Math.ceil(restanteMs / 1000));
                    var mm = Math.floor(s / 60);
                    var ss = s % 60;
                    var txt = mm > 0 ? (mm + ':' + (ss < 10 ? '0' + ss : ss)) : (s + 's');
                    badge.className = 'alarm-badge absolute top-1 right-1 z-10 rounded bg-purple-600 text-white ' +
                                      'text-[9px] font-bold px-1.5 py-0.5';
                    badge.textContent = '⏳ ' + txt;
                }
            }

            var banner = document.getElementById('alarm-banner');
            var bannerText = document.getElementById('alarm-text');

            function revisar() {
                var now = Date.now();
                var vencidas = [];
                var allKeys = new Set(); // todas las claves con vencimiento vigente (cuenta o vencida)

                document.querySelectorAll('.client-card').forEach(function (card) {
                    // Solo EN LÍNEA tiene cronómetro/alarma; cualquier otro estado no tiene efecto.
                    if (card.dataset.status !== 'active') { pintarCuenta(card, 'ninguno'); return; }
                    var dl = deadlineDe(card);
                    if (!dl) { pintarCuenta(card, 'ninguno'); return; }
                    var t = Date.parse(dl);
                    if (isNaN(t)) { pintarCuenta(card, 'ninguno'); return; }

                    var name = card.dataset.clientName || 'cliente';
                    var key = name + '|' + dl;
                    allKeys.add(key);

                    var restante = t - now;
                    if (restante > 0) {
                        // Aún no vence: muestra el conteo en vivo.
                        pintarCuenta(card, 'contando', restante);
                        return;
                    }

                    // Venció: la alarma vive 30s desde que se detecta y luego se quita sola.
                    if (!(key in episodeUntil)) episodeUntil[key] = now + DURACION_MS;
                    if (now >= episodeUntil[key]) {
                        pintarCuenta(card, 'ninguno'); // pasaron los 30s: se limpia solo
                        acked.add(key);                 // y no vuelve a sonar/avisar
                        return;
                    }

                    // Dentro de la ventana: resalta (salvo que ya se silenció) y suma a la lista.
                    pintarCuenta(card, 'vencida', 0, acked.has(key));
                    vencidas.push({ key: key, name: name });
                });

                // Limpia estados de vencimientos que ya no existen (p. ej. llegó un valor nuevo).
                Array.from(seen).forEach(function (k) { if (!allKeys.has(k)) seen.delete(k); });
                Array.from(acked).forEach(function (k) { if (!allKeys.has(k)) acked.delete(k); });
                Object.keys(episodeUntil).forEach(function (k) { if (!allKeys.has(k)) delete episodeUntil[k]; });

                var sinSilenciar = vencidas.filter(function (v) { return !acked.has(v.key); });

                // Un vencimiento nuevo (no visto) arranca la ventana de 30s de sonido.
                sinSilenciar.forEach(function (v) {
                    if (!seen.has(v.key)) { seen.add(v.key); soundUntil = now + DURACION_MS; }
                });

                var sonando = sinSilenciar.length > 0 && now < soundUntil;
                if (sonando) startBeeping(); else stopBeeping();

                // El banner solo muestra los vencimientos NO silenciados.
                if (sinSilenciar.length > 0) {
                    banner.classList.remove('hidden');
                    var nombres = sinSilenciar.slice(0, 3).map(function (v) { return v.name; }).join(', ');
                    var extra = sinSilenciar.length > 3 ? ' y ' + (sinSilenciar.length - 3) + ' más' : '';
                    bannerText.textContent = (sinSilenciar.length === 1 ? 'Opción vencida: ' : 'Opciones vencidas: ') + nombres + extra;
                } else {
                    banner.classList.add('hidden');
                }
            }

            document.getElementById('alarm-silence').addEventListener('click', function () {
                var now = Date.now();
                document.querySelectorAll('.client-card').forEach(function (card) {
                    var dl = deadlineDe(card);
                    if (!dl) return;
                    var t = Date.parse(dl);
                    // Solo silencia lo que ya venció; las que aún cuentan quedan intactas.
                    if (!isNaN(t) && now >= t) {
                        acked.add((card.dataset.clientName || '') + '|' + dl);
                    }
                });
                soundUntil = 0;
                stopBeeping();
                banner.classList.add('hidden');
                revisar(); // refleja el silencio al instante (banner y tarjetas)
            });

            setInterval(revisar, 1000);
        })();

        // Aviso sonoro al llegar algo nuevo (cliente nuevo o respuesta entrante).
        // Se puede silenciar por los minutos que el operador quiera (se recuerda en el navegador).
        (function () {
            var LS_KEY = 'avisos_silenciado_hasta';
            var lastSignal = null;
            var audioCtx = null;

            function getCtx() {
                if (!audioCtx) {
                    var AC = window.AudioContext || window.webkitAudioContext;
                    if (AC) audioCtx = new AC();
                }
                if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
                return audioCtx;
            }
            // Desbloquea el audio en la primera interacción del operador.
            document.addEventListener('click', function () { getCtx(); }, { once: true });

            // Doble tono suave, distinto de la alarma de vencimiento.
            function chime() {
                var ctx = getCtx();
                if (!ctx) return;
                [[660, 0], [880, 0.15]].forEach(function (p) {
                    var osc = ctx.createOscillator(), gain = ctx.createGain();
                    var t0 = ctx.currentTime + p[1];
                    osc.type = 'sine';
                    osc.frequency.value = p[0];
                    gain.gain.setValueAtTime(0.0001, t0);
                    gain.gain.exponentialRampToValueAtTime(0.3, t0 + 0.03);
                    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.3);
                    osc.connect(gain).connect(ctx.destination);
                    osc.start(t0);
                    osc.stop(t0 + 0.32);
                });
            }

            function mutedUntil() {
                try { return parseInt(localStorage.getItem(LS_KEY) || '0', 10) || 0; } catch (e) { return 0; }
            }
            function setMuted(untilMs) {
                try {
                    if (untilMs > Date.now()) localStorage.setItem(LS_KEY, String(untilMs));
                    else localStorage.removeItem(LS_KEY);
                } catch (e) {}
                pintarBoton();
            }
            function silenciado() { return Date.now() < mutedUntil(); }
            function sonidoActivo() {
                var el = document.getElementById('board-signal');
                return !el || el.dataset.sound !== '0'; // activo salvo que Configuración lo apague
            }

            // Detecta cambios en la señal del tablero (se actualiza en cada refresco).
            function revisarSenal() {
                var el = document.getElementById('board-signal');
                if (!el) return;
                var sig = el.dataset.signal || '';
                if (lastSignal === null) { lastSignal = sig; return; } // primera carga: no suena
                if (sig !== lastSignal) {
                    lastSignal = sig;
                    if (sonidoActivo() && !silenciado()) chime();
                }
            }
            setInterval(revisarSenal, 1500);

            var btn = document.getElementById('btn-sonido');
            function pintarBoton() {
                if (!btn) return;
                if (!sonidoActivo()) { btn.textContent = '🔕 Sonido desactivado'; return; }
                if (silenciado()) {
                    var mins = Math.max(1, Math.ceil((mutedUntil() - Date.now()) / 60000));
                    btn.textContent = '🔕 Silenciado (' + mins + ' min)';
                } else {
                    btn.textContent = '🔔 Sonido activo';
                }
            }
            setInterval(pintarBoton, 1000);
            pintarBoton();

            function silenciarMinutos(n) {
                n = parseInt(n, 10);
                if (n > 0) setMuted(Date.now() + n * 60000);
            }

            if (btn) btn.addEventListener('click', function () {
                getCtx();
                if (!sonidoActivo()) {
                    Swal.fire({ icon: 'info', text: 'El sonido está desactivado en Configuración.' });
                    return;
                }
                var est = silenciado()
                    ? '🔕 Silenciado por ' + Math.max(1, Math.ceil((mutedUntil() - Date.now()) / 60000)) + ' min más.'
                    : '🔔 El sonido está activo.';
                var bs = 'display:block;width:100%;margin:4px 0;padding:8px;border-radius:6px;border:1px solid #334155;background:#111827;color:#e2e8f0;cursor:pointer;font-size:13px';
                Swal.fire({
                    title: 'Aviso sonoro',
                    html: '<p style="font-size:12px;color:#94a3b8;margin-bottom:10px">' + est + '</p>'
                        + '<div>'
                        + '<button class="swal-mute" data-min="1" style="' + bs + '">Silenciar 1 minuto</button>'
                        + '<button class="swal-mute" data-min="5" style="' + bs + '">Silenciar 5 minutos</button>'
                        + '<button class="swal-mute" data-min="10" style="' + bs + '">Silenciar 10 minutos</button>'
                        + '<button class="swal-mute" data-min="custom" style="' + bs + '">Silenciar otros minutos…</button>'
                        + (silenciado() ? '<button class="swal-mute" data-min="0" style="' + bs + ';border-color:#059669;color:#6ee7b7">Reactivar ahora</button>' : '')
                        + '</div>',
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: 'Cerrar',
                    didOpen: function () {
                        document.querySelectorAll('.swal-mute').forEach(function (b) {
                            b.addEventListener('click', function () {
                                var m = b.dataset.min;
                                if (m === 'custom') {
                                    Swal.fire({
                                        title: '¿Cuántos minutos?',
                                        input: 'number',
                                        inputValue: 15,
                                        inputAttributes: { min: 1, max: 1440 },
                                        showCancelButton: true,
                                        confirmButtonText: 'Silenciar',
                                    }).then(function (r) { if (r.isConfirmed) silenciarMinutos(r.value); });
                                    return;
                                }
                                if (m === '0') setMuted(0); else silenciarMinutos(m);
                                Swal.close();
                            });
                        });
                    },
                });
            });
        })();
    </script>
@endsection
