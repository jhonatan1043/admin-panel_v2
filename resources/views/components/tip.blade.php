@props(['title' => 'Ayuda', 'open' => false, 'icon' => '💡'])

{{-- Caja de ayuda desplegable, reutilizable en las vistas del panel. --}}
<details {{ $attributes->merge(['class' => 'rounded-lg border border-sky-600/30 bg-sky-500/5 mb-4']) }} @if ($open) open @endif>
    <summary class="cursor-pointer select-none px-4 py-2.5 text-sm text-sky-200 font-medium flex items-center gap-2">
        <span>{{ $icon }}</span> {{ $title }}
    </summary>
    <div class="px-4 pb-4 pt-1 text-sm text-slate-300 space-y-2 leading-relaxed">
        {{ $slot }}
    </div>
</details>
