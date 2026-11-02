@php $s = $styles[$company->current_status] ?? $styles['offline']; @endphp
<div class="client-card group relative rounded-lg bg-black/40 border border-white/5 p-4 overflow-hidden cursor-pointer"
     style="box-shadow: inset 0 2px 0 {{ $s['dot'] }};">
    <div class="flex items-center gap-2">
        <span class="inline-block h-2.5 w-2.5 rounded-full"
              style="background: {{ $s['dot'] }}; box-shadow: 0 0 10px 2px {{ $s['ring'] }};"></span>
        <span class="text-[10px] tracking-widest {{ $s['text'] }}">{{ $s['label'] }}</span>
        @if ($company->empresa)
            <span class="rounded bg-indigo-500/15 border border-indigo-400/30 text-indigo-200 text-[9px] px-1.5 py-0.5"
                  title="Empresa de origen">{{ $company->empresa }}</span>
        @endif
        @if ($company->current_status === 'deletable')
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
        <span class="text-lg font-semibold tracking-wide">{{ $company->name ?: $company->registro }}</span>
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
        <div class="text-[11px] text-slate-500 mb-3">
            <span class="text-slate-600">#{{ $company->registro }}</span>
            · {{ $company->telegram_bot_token ? 'Telegram configurado' : 'Sin Telegram' }}
        </div>

        @if (!empty($company->data))
            <div class="mb-3 grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 text-[11px]">
                @foreach ($company->data as $key => $value)
                    <span class="text-slate-500">{{ $key }}</span>
                    <span class="text-slate-300 break-all">{{ is_scalar($value) ? $value : json_encode($value) }}</span>
                @endforeach
            </div>
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

        @if ($company->logs->isNotEmpty())
            <div class="mb-3">
                <p class="text-[10px] tracking-widest text-slate-500 mb-1">DETALLE</p>
                <div class="detail-scroll max-h-28 overflow-y-auto space-y-1.5 pr-2">
                    @foreach ($company->logs as $log)
                        @if ($log->direction === 'in')
                            {{-- Respuesta del cliente --}}
                            <div class="text-[11px] border-l-2 border-sky-500 pl-2">
                                <div class="text-sky-300">
                                    <span class="font-semibold">Cliente</span>
                                    <span class="text-slate-600">· {{ $log->created_at->format('d/m H:i') }}</span>
                                </div>
                                <div class="text-slate-200 break-words">{{ $log->text }}</div>
                            </div>
                        @else
                            {{-- Enviado desde el panel --}}
                            <div class="text-[11px] border-l-2 pl-2
                                        {{ $log->status === 'sent' ? 'border-emerald-500' : 'border-red-500' }}">
                                <div class="{{ $log->status === 'sent' ? 'text-emerald-400' : 'text-red-400' }}">
                                    {{ $log->status === 'sent' ? '✓' : '✗' }}
                                    <span class="font-semibold text-slate-300">{{ $log->label }}</span>
                                    <span class="text-slate-600">· {{ $log->created_at->format('d/m H:i') }}</span>
                                </div>
                                <div class="text-slate-400 break-words">{{ $log->text }}</div>
                                @if ($log->detail && $log->status !== 'sent')
                                    <div class="text-slate-600 break-words">{{ $log->detail }}</div>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

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
