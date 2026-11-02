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
                        <a href="{{ route('apis.index') }}"
                           class="{{ $item }} {{ request()->routeIs('apis.*') ? 'text-emerald-300 bg-white/5' : 'text-slate-300' }}">APIs</a>
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
            <div class="mb-4 rounded bg-emerald-600/20 border border-emerald-500 text-emerald-200 px-4 py-2 text-sm">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded bg-red-600/20 border border-red-500 text-red-200 px-4 py-2 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

    <script>
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
