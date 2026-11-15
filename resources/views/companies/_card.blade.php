@php $s = $styles[$company->current_status] ?? $styles['offline']; @endphp
<div class="client-card group relative rounded-lg bg-black/40 border border-white/5 p-4 overflow-hidden cursor-pointer"
     data-search="{{ \Illuminate\Support\Str::lower(trim(($company->name ?? '').' '.$company->registro.' '.$company->empresa)) }}"
     data-client-name="{{ $company->name ?: $company->registro }}"
     data-status="{{ $company->current_status }}"
     @if (! empty($company->alarm_at)) data-alarm-at="{{ $company->alarm_at->toIso8601String() }}" @endif
     style="box-shadow: inset 0 2px 0 {{ $s['dot'] }};">
    <div class="flex items-center gap-2">
        <span class="inline-block h-2.5 w-2.5 rounded-full"
              style="background: {{ $s['dot'] }}; box-shadow: 0 0 10px 2px {{ $s['ring'] }};"></span>
        <span class="text-[10px] tracking-widest {{ $s['text'] }}">{{ $s['label'] }}</span>
        @if ($company->isOnline())
            {{-- Solo un foco verde: la página está abierta ahora. --}}
            <span class="inline-block h-2 w-2 rounded-full bg-emerald-400 animate-pulse"
                  title="En línea (tiene la página abierta ahora)"></span>
        @endif
        {{-- La empresa solo se muestra si no es ya el título de la tarjeta (evita repetirla). --}}
        @if ($company->empresa && $company->name)
            <span class="rounded bg-indigo-500/15 border border-indigo-400/30 text-indigo-200 text-[9px] px-1.5 py-0.5"
                  title="Proyecto / empresa de origen">{{ $company->empresa }}</span>
        @endif
        {{-- Operador (solo admin): icono discreto; al tocarlo se elige/cambia el operador. --}}
        @if (auth()->user()->role === 'admin')
            @php $op = $company->operator; @endphp
            <button type="button" onclick="asignarOperador(event, this)"
                    data-company="{{ $company->id }}"
                    data-operator="{{ $company->operator_id }}"
                    data-client="{{ $company->name ?: $company->registro }}"
                    class="text-[11px] leading-none text-slate-500 hover:text-emerald-300"
                    title="Operador: {{ $op ? ($op->id === auth()->id() ? 'Tú (admin)' : $op->name) : 'sin asignar' }} (clic para cambiar)">👤</button>
        @endif
        @if ($company->current_status === 'deletable' && auth()->user()->role === 'admin')
            <div class="ml-auto flex items-center gap-2 text-[10px]">
                <form action="{{ route('companies.destroy', $company) }}" method="POST"
                      onclick="event.stopPropagation()"
                      class="js-confirm" data-confirm="Se eliminará el registro {{ $company->name ?: $company->registro }}.">
                    @csrf @method('DELETE')
                    <button class="text-red-400 hover:text-red-300">borrar</button>
                </form>
            </div>
        @endif
    </div>

    <div class="mt-2 flex items-baseline gap-2">
        {{-- Título de la tarjeta: nombre del cliente; si no hay, el nombre del proyecto/empresa; y por último el registro. --}}
        <span class="text-lg font-semibold tracking-wide">{{ $company->name ?: ($company->empresa ?: $company->registro) }}</span>
        @php $fecha = $company->fecha ?? $company->last_activity_at; @endphp
        @if ($fecha)
            <span class="text-[10px] text-slate-500">{{ $fecha->copy()->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}</span>
        @endif
    </div>

    @php
        // Aplanamos la data (incluida la anidada) a pares "clave: valor" legibles,
        // y ocultamos los campos internos (_proyecto, _vista, etc.).
        $flatData = !empty($company->data)
            ? collect(\Illuminate\Support\Arr::dot($company->data))
                ->reject(fn ($v, $k) => \Illuminate\Support\Str::startsWith((string) $k, '_'))
            : collect();
    @endphp

    @if ($flatData->isNotEmpty())
        @php
            // El primer valor que llegó a la tarjeta: se ve SIN abrirla.
            $firstKey = $flatData->keys()->first();
            $firstVal = $flatData->first();
            $firstLabel = \Illuminate\Support\Str::of((string) $firstKey)
                ->replace(['_', '.'], ' ')->trim()->ucfirst();
            $firstDisplay = is_bool($firstVal)
                ? ($firstVal ? 'Sí' : 'No')
                : ((is_null($firstVal) || $firstVal === '') ? '—' : (string) $firstVal);
        @endphp
        {{-- Primer valor recibido: visible en la tarjeta cerrada. --}}
        <div class="mt-2">
            <div class="text-[9px] uppercase tracking-wide text-slate-600">{{ $firstLabel }}</div>
            <div class="text-sm text-slate-100 break-words">{{ $firstDisplay }}</div>
        </div>
    @endif

    <div class="card-body mt-3">
        @if ($flatData->count() > 1)
            {{-- El resto de la info (sin repetir el primer valor, que ya se ve en la tarjeta). --}}
            <div class="mb-3 max-h-40 overflow-y-auto pr-1 detail-scroll">
                <div class="grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 text-[11px]">
                    @foreach ($flatData->slice(1) as $key => $value)
                        @php
                            $label = \Illuminate\Support\Str::of((string) $key)
                                ->replace(['_', '.'], ' ')->trim()->ucfirst();
                            if (is_bool($value)) {
                                $display = $value ? 'Sí' : 'No';
                            } elseif (is_null($value) || $value === '') {
                                $display = '—';
                            } else {
                                $display = (string) $value;
                            }
                        @endphp
                        <span class="text-slate-500 break-words">{{ $label }}</span>
                        <span class="text-slate-300 break-words">{{ $display }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Conversación en vivo: este contenedor se refresca solo mientras la tarjeta está abierta. --}}
        <div class="card-detail" data-detail="{{ $company->id }}">
            @include('companies._detail', ['company' => $company])
        </div>

        @if ($buttons->isNotEmpty())
            <div class="flex flex-wrap gap-1.5">
                @foreach ($buttons as $button)
                    <button type="button" onclick="sendMessage(event, this)"
                            data-company="{{ $company->id }}"
                            data-button="{{ $button->id }}"
                            data-label="{{ $button->label }}"
                            data-client="{{ $company->name ?: $company->registro }}"
                            class="rounded border border-emerald-500/30 bg-emerald-500/5 hover:bg-emerald-500/15 text-emerald-200 text-[11px] px-2 py-1"
                            title="{{ $button->text }}">
                        {{ $button->label }}
                    </button>
                @endforeach
            </div>
        @else
            <div class="text-[11px] text-slate-600">Sin botones en el catálogo.</div>
        @endif
    </div>
</div>
