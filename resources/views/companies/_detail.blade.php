{{-- Conversación del cliente (entrantes y salientes). Se refresca en vivo. --}}
@if ($company->logs->isNotEmpty())
    <div class="mb-3">
        <div class="flex items-center justify-between mb-1">
            <p class="text-[10px] tracking-widest text-slate-500">DETALLE</p>
            @if (auth()->user()->role === 'admin')
                <a href="{{ route('companies.detail.export', $company) }}" onclick="event.stopPropagation()"
                   class="text-[10px] text-sky-400 hover:text-sky-300" title="Exportar conversación a TXT">⬇ TXT</a>
            @endif
        </div>
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
