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

    @if ($companies->isEmpty())
        <p class="text-slate-400 text-sm">Aún no hay clientes. Crea el primer registro.</p>
    @else
        {{-- Arriba: clientes en línea (activos) --}}
        <div class="flex items-center gap-2 mb-3 text-[10px] tracking-widest text-emerald-300">
            <span class="h-2 w-2 rounded-full bg-emerald-500" style="box-shadow:0 0 8px 2px rgba(34,197,94,.5);"></span>
            EN LÍNEA ({{ $active->count() }})
        </div>
        @if ($active->isNotEmpty())
            <div class="grid gap-4 grid-cols-[repeat(auto-fill,minmax(260px,1fr))]">
                @foreach ($active as $company)
                    @include('companies._card', ['company' => $company, 'styles' => $styles, 'buttons' => $buttons])
                @endforeach
            </div>
        @else
            <p class="text-slate-600 text-xs">Nadie en línea.</p>
        @endif

        {{-- Línea divisoria --}}
        <hr class="my-6 border-0 h-px bg-gradient-to-r from-transparent via-slate-600 to-transparent">

        {{-- Abajo: el resto (pendientes, borrables, desconectados) --}}
        <div class="flex items-center gap-2 mb-3 text-[10px] tracking-widest text-slate-400">
            <span class="h-2 w-2 rounded-full bg-slate-500"></span>
            RESTO ({{ $others->count() }})
        </div>
        @if ($others->isNotEmpty())
            <div class="grid gap-4 grid-cols-[repeat(auto-fill,minmax(260px,1fr))]">
                @foreach ($others as $company)
                    @include('companies._card', ['company' => $company, 'styles' => $styles, 'buttons' => $buttons])
                @endforeach
            </div>
        @else
            <p class="text-slate-600 text-xs">Sin clientes en esta sección.</p>
        @endif
    @endif

    <script>
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
    </script>
@endsection
