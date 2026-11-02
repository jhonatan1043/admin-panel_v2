@extends('layouts.app')

@section('title', 'Clientes')

@php
    // Estilo por estado: borde superior, glow del punto y etiqueta.
    $styles = [
        'active'  => ['dot' => '#22c55e', 'ring' => 'rgba(34,197,94,.45)',  'label' => 'EN LÍNEA',     'text' => 'text-emerald-300'],
        'pending' => ['dot' => '#f59e0b', 'ring' => 'rgba(245,158,11,.45)', 'label' => 'PENDIENTE',    'text' => 'text-amber-300'],
        'offline' => ['dot' => '#64748b', 'ring' => 'rgba(100,116,139,.35)','label' => 'DESCONECTADO', 'text' => 'text-slate-400'],
        'deletable' => ['dot' => '#ef4444', 'ring' => 'rgba(239,68,68,.45)', 'label' => 'BORRABLE',     'text' => 'text-red-400'],
    ];
    $counts = $companies->groupBy('current_status')->map->count();
    $active = $companies->where('current_status', 'active');
    $others = $companies->where('current_status', '!==', 'active');
@endphp

@section('content')
    <div class="flex flex-wrap items-center gap-3 mb-5 text-xs">
        <span class="text-slate-400">Clientes:</span>
        <span class="rounded border border-emerald-500/40 bg-emerald-500/10 text-emerald-300 px-2 py-1">
            En línea: {{ $counts['active'] ?? 0 }}
        </span>
        <span class="rounded border border-amber-500/40 bg-amber-500/10 text-amber-300 px-2 py-1">
            Pendientes: {{ $counts['pending'] ?? 0 }}
        </span>
        <span class="rounded border border-slate-500/40 bg-slate-500/10 text-slate-300 px-2 py-1">
            Desconectados: {{ $counts['offline'] ?? 0 }}
        </span>
        <span class="ml-auto text-slate-500">Total: {{ $companies->count() }}</span>
    </div>

    {{-- Filtro: por empresa y por búsqueda (nombre o registro). --}}
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
        </div>
    @endif

    @if ($companies->isEmpty())
        <p class="text-slate-400 text-sm">Aún no hay clientes. Crea el primer registro.</p>
    @else
        {{-- Una sección por empresa, a lo ancho, separadas por una línea divisoria. --}}
        @foreach ($companiesByEmpresa as $empresa => $group)
            @php
                $gActive = $group->where('current_status', 'active');
                $gOthers = $group->where('current_status', '!==', 'active');
            @endphp

            <section class="empresa-group" data-empresa="{{ $empresa }}">
                {{-- Línea que divide la pantalla entre empresas --}}
                @unless ($loop->first)
                    <hr class="empresa-divider my-6 border-0 h-px bg-gradient-to-r from-transparent via-slate-600 to-transparent">
                @endunless

                {{-- Nombre de la empresa arriba del grupo --}}
                <h2 class="flex items-center gap-2 mb-3 text-sm font-semibold tracking-wide text-indigo-200">
                    {{ $empresa }}
                    <span class="text-[10px] font-normal text-slate-500">
                        · <span class="text-emerald-400">{{ $gActive->count() }} en línea</span> · {{ $group->count() }} total
                    </span>
                </h2>

                @if ($gActive->isNotEmpty())
                    <div class="grid gap-4 grid-cols-[repeat(auto-fill,minmax(260px,1fr))]">
                        @foreach ($gActive as $company)
                            @include('companies._card', ['company' => $company, 'styles' => $styles, 'buttons' => $buttons])
                        @endforeach
                    </div>
                @else
                    <p class="text-slate-600 text-xs">Nadie en línea.</p>
                @endif

                {{-- El resto, agrupado y colapsado. --}}
                @if ($gOthers->isNotEmpty())
                    <details class="mt-3">
                        <summary class="cursor-pointer select-none text-[10px] tracking-widest text-slate-500 hover:text-slate-300">
                            OTROS ({{ $gOthers->count() }})
                        </summary>
                        <div class="grid gap-4 grid-cols-[repeat(auto-fill,minmax(260px,1fr))] mt-3 opacity-80">
                            @foreach ($gOthers as $company)
                                @include('companies._card', ['company' => $company, 'styles' => $styles, 'buttons' => $buttons])
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @endforeach
    @endif

    <script>
        // Presionar un botón: confirma con SweetAlert y envía el texto al Telegram del cliente.
        function sendMessage(e, btn) {
            e.stopPropagation();
            var company = btn.dataset.company;
            var buttonId = btn.dataset.button;
            var label = btn.dataset.label;
            var client = btn.dataset.client;

            Swal.fire({
                title: '¿Enviar mensaje?',
                html: 'Botón <b>' + label + '</b> al Telegram de <b>' + client + '</b>.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Enviar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#059669',
            }).then(function (r) {
                if (!r.isConfirmed) return;

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
                });
            });
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
        (function () {
            var selEmpresa = document.getElementById('filtro-empresa');
            var inpBusqueda = document.getElementById('filtro-busqueda');
            var vacio = document.getElementById('filtro-vacio');
            if (!selEmpresa) return;

            function aplicar() {
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

            selEmpresa.addEventListener('change', aplicar);
            inpBusqueda.addEventListener('input', aplicar);
        })();
    </script>
@endsection
