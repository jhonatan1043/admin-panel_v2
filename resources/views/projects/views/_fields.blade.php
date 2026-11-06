{{-- Tabla compacta de campos detectados. $fields = array de campos del analizador. --}}
@if (empty($fields))
    <p class="text-xs text-slate-500">Sin campos.</p>
@else
    <table class="w-full text-xs">
        <thead class="text-slate-500">
            <tr class="text-left">
                <th class="font-normal pb-1">Campo</th>
                <th class="font-normal pb-1">Tipo</th>
                <th class="font-normal pb-1">Envía como</th>
                <th class="font-normal pb-1">Obl.</th>
            </tr>
        </thead>
        <tbody class="text-slate-300">
            @foreach ($fields as $f)
                <tr class="border-t border-slate-800">
                    <td class="py-1 font-mono text-slate-200">{{ $f['key'] }}@if (!empty($f['hidden']))<span class="text-slate-500"> (oculto)</span>@endif</td>
                    <td class="py-1">{{ $f['type'] }}</td>
                    <td class="py-1 font-mono text-emerald-300">{{ $f['send_as'] ?? $f['key'] }}</td>
                    <td class="py-1">{{ !empty($f['required']) ? 'Sí' : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
