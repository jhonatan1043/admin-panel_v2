<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel Admin')</title>
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <link rel="stylesheet" href="{{ asset('vendor/sweetalert2/sweetalert2.min.css') }}">
    <script src="{{ asset('vendor/sweetalert2/sweetalert2.min.js') }}"></script>
    <style>
        body {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            background-color: #060b08;
            background-image: radial-gradient(1200px 600px at 50% -10%, rgba(16,185,129,.10), transparent 60%);
        }
        /* Colapso independiente de Tailwind: el cuerpo solo se ve en la tarjeta abierta. */
        .card-body { display: none; }
        .client-card.is-open .card-body { display: block; }
        /* Scrollbar con estilo para el detalle. */
        .detail-scroll { scrollbar-width: thin; scrollbar-color: rgba(16,185,129,.45) transparent; }
        .detail-scroll::-webkit-scrollbar { width: 6px; }
        .detail-scroll::-webkit-scrollbar-track { background: transparent; }
        .detail-scroll::-webkit-scrollbar-thumb {
            background: rgba(16,185,129,.4);
            border-radius: 9999px;
        }
        .detail-scroll::-webkit-scrollbar-thumb:hover { background: rgba(16,185,129,.7); }
        /* Tarjeta con cronómetro corriendo: borde superior morado. */
        .client-card.is-counting {
            box-shadow: inset 0 3px 0 #a855f7 !important;
        }
        /* Quedan menos de 10s: el borde morado y el tiempo titilan.
           (La animación pisa el box-shadow inline del estado de la tarjeta.) */
        .client-card.is-ending {
            animation: ending-blink .6s step-end infinite;
        }
        .client-card.is-ending .alarm-badge {
            animation: ending-badge .6s step-end infinite;
        }
        @keyframes ending-blink {
            0%   { box-shadow: inset 0 3px 0 #a855f7, 0 0 12px 1px rgba(168,85,247,.55); }
            50%  { box-shadow: inset 0 3px 0 transparent, 0 0 0 0 transparent; }
        }
        @keyframes ending-badge {
            0%   { opacity: 1; }
            50%  { opacity: .15; }
        }
        /* Tarjeta con una opción vencida: resaltado rojo parpadeante. */
        .client-card.is-expired {
            outline: 2px solid #ef4444;
            outline-offset: -2px;
            animation: alarm-pulse 1s ease-in-out infinite;
        }
        @keyframes alarm-pulse {
            0%, 100% { box-shadow: inset 0 2px 0 #ef4444, 0 0 0 0 rgba(239,68,68,.0); }
            50% { box-shadow: inset 0 2px 0 #ef4444, 0 0 14px 2px rgba(239,68,68,.5); }
        }
    </style>
</head>
<body class="text-slate-100 min-h-screen">
    <nav class="border-b border-emerald-500/20 bg-black/40 backdrop-blur sticky top-0 z-10">
        <div class="max-w-[1600px] mx-auto px-5 py-3 flex items-center justify-between">
            <a href="{{ route('companies.index') }}" class="font-semibold tracking-wide text-emerald-300">
                ▚ PANEL ADMIN
            </a>

            @auth
                @php
                    $item = 'px-3 py-1.5 rounded hover:bg-white/5 transition';
                    $isAdmin = auth()->user()->role === 'admin';
                @endphp
                <div class="flex items-center gap-1 text-xs">
                    <a href="{{ route('companies.index') }}"
                       class="{{ $item }} {{ request()->routeIs('companies.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Clientes</a>
                    @if ($isAdmin)
                        <a href="{{ route('operators.index') }}"
                           class="{{ $item }} {{ request()->routeIs('operators.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Operadores</a>
                        <a href="{{ route('buttons.index') }}"
                           class="{{ $item }} {{ request()->routeIs('buttons.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Botones</a>
                    @endif
                    {{-- Proyectos: visible para admin y operadores. --}}
                    <a href="{{ route('projects.index') }}"
                       class="{{ $item }} {{ request()->routeIs('projects.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Proyectos</a>
                    @if ($isAdmin)
                        <a href="{{ route('apis.index') }}"
                           class="{{ $item }} {{ request()->routeIs('apis.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">APIs</a>
                        <a href="{{ route('settings.edit') }}"
                           class="{{ $item }} {{ request()->routeIs('settings.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Configuración</a>
                    @endif
                </div>
            @endauth

            @auth
                <div class="flex items-center gap-3 text-xs">
                    <span class="text-slate-400">{{ auth()->user()->name }}
                        <span class="text-emerald-400/70">· {{ auth()->user()->role }}</span>
                    </span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="border border-slate-600 text-slate-300 hover:bg-white/5 px-3 py-1.5 rounded">
                            Salir
                        </button>
                    </form>
                </div>
            @endauth
        </div>
    </nav>

    <main class="max-w-[1600px] mx-auto px-5 py-6">
        @if (session('status'))
            <div class="js-flash mb-4 rounded bg-emerald-600/20 border border-emerald-500 text-emerald-200 px-4 py-2 text-sm flex items-start justify-between gap-3"
                 data-autohide="5000">
                <span>{{ session('status') }}</span>
                <button type="button" onclick="this.closest('.js-flash').remove()"
                        class="text-emerald-300/70 hover:text-emerald-100 leading-none text-base" title="Cerrar">✕</button>
            </div>
        @endif

        @if ($errors->any())
            <div class="js-flash mb-4 rounded bg-red-600/20 border border-red-500 text-red-200 px-4 py-2 text-sm flex items-start justify-between gap-3">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" onclick="this.closest('.js-flash').remove()"
                        class="text-red-300/70 hover:text-red-100 leading-none text-base" title="Cerrar">✕</button>
            </div>
        @endif

        @yield('content')
    </main>

    {{-- Pantalla de progreso para subidas (archivos + análisis). --}}
    <div id="upload-overlay" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/70">
        <div class="w-80 max-w-[90vw] rounded-lg border border-emerald-600/40 bg-slate-900 p-5 text-center shadow-xl">
            <div id="upload-stage" class="text-sm font-semibold text-emerald-300 mb-3">Preparando…</div>
            <div class="h-2 rounded bg-slate-700 overflow-hidden">
                <div id="upload-bar" class="h-full bg-emerald-500 transition-all duration-150" style="width:0%"></div>
            </div>
            <div id="upload-pct" class="text-[11px] text-slate-400 mt-2">0%</div>
        </div>
    </div>

    <script>
        // Las alertas con data-autohide se ocultan solas tras el tiempo indicado.
        document.querySelectorAll('.js-flash[data-autohide]').forEach(function (el) {
            setTimeout(function () {
                el.style.transition = 'opacity .4s';
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 400);
            }, parseInt(el.dataset.autohide, 10) || 5000);
        });

        // Confirmación con SweetAlert para cualquier formulario con la clase "js-confirm".
        document.addEventListener('submit', function (e) {
            var form = e.target.closest('form.js-confirm');
            if (!form || form.dataset.confirmed === '1') return;

            e.preventDefault();
            Swal.fire({
                title: form.dataset.confirmTitle || '¿Estás seguro?',
                text: form.dataset.confirm || 'Esta acción no se puede deshacer.',
                icon: form.dataset.confirmIcon || 'warning',
                showCancelButton: true,
                confirmButtonText: form.dataset.confirmBtn || 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: form.dataset.confirmColor || '#dc2626',
            }).then(function (r) {
                if (r.isConfirmed) {
                    form.dataset.confirmed = '1';
                    form.submit();
                }
            });
        }, true);

        // Subidas con barra de progreso: muestra el % al subir y "Analizando…" mientras
        // el servidor procesa, para que la página no parezca colgada.
        (function () {
            var overlay = document.getElementById('upload-overlay');
            var stage = document.getElementById('upload-stage');
            var bar = document.getElementById('upload-bar');
            var pct = document.getElementById('upload-pct');
            if (!overlay) return;

            function mostrar() { overlay.classList.remove('hidden'); overlay.classList.add('flex'); }
            function ocultar() { overlay.classList.add('hidden'); overlay.classList.remove('flex'); }
            function porcentaje(p) { bar.classList.remove('animate-pulse'); bar.style.width = p + '%'; pct.textContent = Math.round(p) + '%'; }
            function indeterminado(t) { stage.textContent = t; bar.style.width = '100%'; bar.classList.add('animate-pulse'); pct.textContent = ''; }

            // Envía un formulario (FormData) mostrando la barra de progreso.
            function enviar(form, fd, fallback) {
                form.dataset.uploading = '1';
                var xhr = new XMLHttpRequest();
                xhr.open((form.method || 'POST').toUpperCase(), form.action, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                mostrar();
                stage.textContent = 'Subiendo archivos…';
                porcentaje(0);

                xhr.upload.onprogress = function (ev) { if (ev.lengthComputable) porcentaje(ev.loaded / ev.total * 100); };
                xhr.upload.onload = function () { indeterminado('Analizando…'); };
                xhr.onload = function () {
                    if (xhr.status >= 200 && xhr.status < 400) {
                        indeterminado('Listo');
                        if (xhr.responseURL && xhr.responseURL !== form.action) window.location.href = xhr.responseURL;
                        else window.location.reload();
                        return;
                    }
                    form.dataset.uploading = '';
                    ocultar();
                    var msg = xhr.status === 413 || xhr.status === 500
                        ? 'No se pudo guardar. Puede que los archivos sean demasiado grandes.'
                        : ('El servidor devolvió un error (' + xhr.status + ').');
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'No se pudo procesar', text: msg });
                    else alert(msg);
                };
                xhr.onerror = function () {
                    form.dataset.uploading = '';
                    ocultar();
                    if (fallback) fallback(); else form.submit();
                };
                xhr.send(fd);
            }

            // Formularios normales de subida (una vista, archivos sueltos, etc.).
            document.addEventListener('submit', function (e) {
                var form = e.target.closest('form.js-upload-form');
                if (!form || form.dataset.uploading === '1') return;
                e.preventDefault();
                enviar(form, new FormData(form));
            });

            // Importar CARPETA: arma files[]+paths[] con las rutas relativas (webkitRelativePath).
            document.addEventListener('submit', function (e) {
                var form = e.target.closest('form.js-folder-form');
                if (!form || form.dataset.uploading === '1') return;
                e.preventDefault();

                var input = form.querySelector('input[type=file]');
                var lista = input ? input.files : [];
                if (!lista || lista.length === 0) { return; }

                // Aviso de tamaño: evita que una carpeta grande falle por el límite del servidor.
                var LIMITE = 38 * 1024 * 1024; // ~38 MB (debajo de post_max_size)
                var total = 0;
                for (var k = 0; k < lista.length; k++) total += (lista[k].size || 0);
                var mb = (total / 1048576).toFixed(1);

                if (total > LIMITE) {
                    var aviso = 'La carpeta pesa ' + mb + ' MB y supera el límite (~38 MB). '
                        + 'Quita archivos pesados (vídeos/imágenes grandes) o impórtala por partes.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Carpeta muy grande', text: aviso });
                    else alert(aviso);
                    return;
                }

                function armarYEnviar() {
                    var fd = new FormData();
                    var token = form.querySelector('input[name=_token]');
                    if (token) fd.append('_token', token.value);
                    ['mode', 'strategy'].forEach(function (campo) {
                        var sel = form.querySelector('input[name=' + campo + ']:checked');
                        if (sel) fd.append(campo, sel.value);
                    });
                    for (var i = 0; i < lista.length; i++) {
                        fd.append('files[]', lista[i]);
                        fd.append('paths[]', lista[i].webkitRelativePath || lista[i].name);
                    }
                    enviar(form, fd);
                }

                var pregunta = (form.dataset.confirmFolder || '') + ' (' + lista.length + ' archivos, ' + mb + ' MB)';
                if (window.Swal) {
                    Swal.fire({ title: '¿Importar carpeta?', text: pregunta, icon: 'warning', showCancelButton: true,
                        confirmButtonText: 'Sí, importar', cancelButtonText: 'Cancelar', confirmButtonColor: '#0284c7' })
                        .then(function (r) { if (r.isConfirmed) armarYEnviar(); });
                } else {
                    armarYEnviar();
                }
            });
        })();
    </script>
</body>
</html>
