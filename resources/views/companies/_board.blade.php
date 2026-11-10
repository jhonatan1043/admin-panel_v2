@php
    // Estilo por estado: borde superior, glow del punto y etiqueta.
    $styles = [
        'active'  => ['dot' => '#22c55e', 'ring' => 'rgba(34,197,94,.45)',  'label' => 'EN LÍNEA',     'text' => 'text-emerald-300'],
        'pending' => ['dot' => '#f59e0b', 'ring' => 'rgba(245,158,11,.45)', 'label' => 'PENDIENTE',    'text' => 'text-amber-300'],
        'offline' => ['dot' => '#64748b', 'ring' => 'rgba(100,116,139,.35)','label' => 'DESCONECTADO', 'text' => 'text-slate-400'],
        'deletable' => ['dot' => '#ef4444', 'ring' => 'rgba(239,68,68,.45)', 'label' => 'BORRABLE',     'text' => 'text-red-400'],
    ];
    $counts = $companies->groupBy('current_status')->map->count();
@endphp

{{-- Señal para el aviso sonoro: cambia cuando llega algo nuevo (se lee tras cada refresco). --}}
<div id="board-signal" hidden
     data-signal="{{ $arrivalSignal ?? '' }}"
     data-sound="{{ ($soundEnabled ?? true) ? '1' : '0' }}"></div>

@php $online = $companies->filter->isOnline()->count(); @endphp
<div class="flex flex-wrap items-center gap-3 mb-5 text-xs">
    <span class="text-slate-400">Clientes:</span>
    <span class="rounded border border-emerald-400/60 bg-emerald-500/10 text-emerald-200 px-2 py-1 flex items-center gap-1.5"
          title="Con la página abierta ahora">
        <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span> En vivo: {{ $online }}
    </span>
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
