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
            <span class="flex items-center gap-1 text-[9px] text-emerald-300" title="Tiene la página abierta ahora">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span> en línea
            </span>
        @endif
        @if ($company->empresa)
            <span class="rounded bg-indigo-500/15 border border-indigo-400/30 text-indigo-200 text-[9px] px-1.5 py-0.5"
                  title="Proyecto / empresa de origen">{{ $company->empresa }}</span>
        @endif
        {{-- Tarjeta morada (código pendiente): botón para marcarla atendida y callar la alarma. --}}
        @if (! empty($company->alarm_at))
            <button type="button" onclick="marcarAtendido(event, this)" data-company="{{ $company->id }}"
                    class="btn-atendido ml-auto rounded bg-purple-600 hover:bg-purple-500 text-white text-[10px] font-bold px-2 py-0.5">
                Atendido
            </button>
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
            <span class="text-[10px] text-slate-500">{{ $fecha->format('d/m/Y H:i') }}</span>
        @endif
    </div>

    @if (auth()->user()->role === 'admin')
        @php $op = $company->operator; @endphp
        <div class="text-[10px] mt-1 flex items-center gap-1">
            <span class="text-slate-500">Operador:</span>
            @if (! $op)
                <span class="text-slate-600">sin asignar</span>
            @elseif ($op->id === auth()->id())
                <span class="text-emerald-400">Tú (admin)</span>
            @else
                <span class="text-sky-300">{{ $op->name }}</span>
            @endif
        </div>
    @endif

    <div class="card-body mt-3">
        @php $telegramListo = \App\Models\Setting::get('telegram_bot_token')
            && ($company->telegram_chat_id || \App\Models\Setting::get('telegram_chat_id')); @endphp
        <div class="text-[11px] text-slate-500 mb-3">
            <span class="text-slate-600">#{{ $company->registro }}</span>
            · <span class="{{ $telegramListo ? 'text-emerald-400' : 'text-amber-400' }}">
                {{ $telegramListo ? 'Telegram listo' : 'Telegram pendiente' }}
            </span>
        </div>

        @if (!empty($company->data))
            @php
                // Aplanamos la data (incluida la anidada) a pares "clave: valor" legibles,
                // y ocultamos los campos internos (_proyecto, _vista, etc.).
                $flatData = collect(\Illuminate\Support\Arr::dot($company->data))
                    ->reject(fn ($v, $k) => \Illuminate\Support\Str::startsWith((string) $k, '_'));
            @endphp
            @if ($flatData->isNotEmpty())
                {{-- La info llega pero se despliega con un botoncito pequeño. --}}
                <details class="mb-3" onclick="event.stopPropagation()">
                    <summary class="cursor-pointer select-none text-[11px] text-emerald-300 hover:text-emerald-200 mb-1">
                        Ver datos ({{ $flatData->count() }})
                    </summary>
                    <div class="max-h-40 overflow-y-auto pr-1 detail-scroll">
                        <div class="grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 text-[11px]">
                            @foreach ($flatData as $key => $value)
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
                </details>
            @endif
        @endif

        @if (auth()->user()->role === 'admin')
            <form action="{{ route('companies.assign', $company) }}" method="POST"
                  onclick="event.stopPropagation()"
                  class="mb-3 flex items-center gap-2 js-confirm"
                  data-confirm-title="¿Asignar operador?"
                  data-confirm="Se cambiará el operador de {{ $company->name ?: $company->registro }}."
                  data-confirm-btn="Sí, asignar"
                  data-confirm-icon="question"
                  data-confirm-color="#059669">
                @csrf @method('PUT')
                <span class="text-[10px] text-slate-500">Asignar a:</span>
                <select name="operator_id"
                        class="rounded bg-black/40 border border-slate-600 px-2 py-1 text-[11px]">
                    <option value="">sin asignar</option>
                    <option value="{{ auth()->id() }}" @selected($company->operator_id === auth()->id())>Yo (admin)</option>
                    @foreach ($operators as $op)
                        <option value="{{ $op->id }}" @selected($company->operator_id === $op->id)>{{ $op->name }}</option>
                    @endforeach
                </select>
                <button class="rounded border border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10 text-[11px] px-2 py-1">
                    Guardar
                </button>
            </form>
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
