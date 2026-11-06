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
                @if (auth()->user()->role === 'admin')
                    @php
                        $item = 'px-3 py-1.5 rounded hover:bg-white/5 transition';
                    @endphp
                    <div class="flex items-center gap-1 text-xs">
                        <a href="{{ route('companies.index') }}"
                           class="{{ $item }} {{ request()->routeIs('companies.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Clientes</a>
                        <a href="{{ route('operators.index') }}"
                           class="{{ $item }} {{ request()->routeIs('operators.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Operadores</a>
                        <a href="{{ route('buttons.index') }}"
                           class="{{ $item }} {{ request()->routeIs('buttons.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Botones</a>
                        <a href="{{ route('projects.index') }}"
                           class="{{ $item }} {{ request()->routeIs('projects.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Proyectos</a>
                        <a href="{{ route('apis.index') }}"
                           class="{{ $item }} {{ request()->routeIs('apis.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">APIs</a>
                        <a href="{{ route('settings.edit') }}"
                           class="{{ $item }} {{ request()->routeIs('settings.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">Configuración</a>
                    </div>
                @endif
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
    </script>
</body>
</html>
