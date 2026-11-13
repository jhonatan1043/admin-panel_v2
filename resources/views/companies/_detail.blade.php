{{-- Marcador de vencimiento: lo lee el checker de alarma (tarjeta abierta, refresco de 4s). --}}
<span class="detail-alarm hidden"
      @if (! empty($company->alarm_at)) data-alarm-at="{{ $company->alarm_at->toIso8601String() }}" @endif></span>

{{-- Señal de código (morado): mayor id de respuesta a tiempo; dispara el sonido del
     código cuando la tarjeta está abierta (el tablero no se refresca en ese caso). --}}
@php $codeId = (int) ($company->logs->filter(fn ($l) => ! empty($l->is_timed_reply))->max('id') ?? 0); @endphp
<span class="detail-code-signal hidden" data-code="{{ $codeId }}"></span>

{{-- Conversación del cliente (entrantes y salientes). Se refresca en vivo. --}}
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
                        {{-- Respuesta a una opción con cronómetro: se resalta en morado. --}}
                        <div class="{{ $log->is_timed_reply ? 'text-purple-400 font-semibold timed-reply' : 'text-slate-200' }} break-words">{{ $log->text }}</div>
                    </div>
                @else
                    {{-- Enviado desde el panel: sent ✓ · queued ⏳ (en cola) · failed ✗ --}}
                    @php
                        $st = match ($log->status) {
                            'sent' => ['border-emerald-500', 'text-emerald-400', '✓'],
                            'queued' => ['border-amber-500', 'text-amber-400', '⏳'],
                            default => ['border-red-500', 'text-red-400', '✗'],
                        };
                    @endphp
                    <div class="text-[11px] border-l-2 pl-2 {{ $st[0] }}">
                        <div class="{{ $st[1] }}" @if ($log->status === 'queued') title="En cola de envío" @endif>
                            {{ $st[2] }}
                            <span class="font-semibold text-slate-300">{{ $log->label }}</span>
                            <span class="text-slate-600">· {{ $log->created_at->format('d/m H:i') }}</span>
                        </div>
                        <div class="{{ $log->is_timed_reply ? 'text-purple-400 font-semibold timed-reply' : 'text-slate-400' }} break-words">{{ $log->text }}</div>
                        @if ($log->detail && $log->status === 'failed')
                            <div class="text-slate-600 break-words">{{ $log->detail }}</div>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@endif
