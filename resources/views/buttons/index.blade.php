@extends('layouts.app')

@section('title', 'Botones')

@section('content')
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-xl font-semibold">Botones
            <span class="text-xs text-slate-500 font-normal">· catálogo global · Total: {{ $buttons->count() }}</span>
        </h1>
    </div>
    <p class="text-sm text-slate-400 mb-5">
        Cada botón es un texto que se envía al cliente por Telegram al presionarlo en su tarjeta.
    </p>

    {{-- Alta --}}
    <form action="{{ route('buttons.store') }}" method="POST"
          class="rounded-lg border border-dashed border-slate-600 p-3 mb-6 max-w-3xl">
        @csrf
        <div class="flex gap-2 items-start">
            <input type="number" name="sort_order" value="0" title="Orden"
                   class="w-16 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm">
            <input type="text" name="label" placeholder="Botón (ej: Licencia)" required
                   class="w-48 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm">
            <textarea name="text" rows="1" placeholder="Texto que se envía al cliente" required
                      class="flex-1 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm"></textarea>
            <input type="text" name="url" placeholder="URL (interna)" title="URL interna; la API GET la devuelve"
                   class="w-56 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm">
            <input type="number" name="expires_seconds" min="1" placeholder="Vence (s)"
                   title="Segundos de vencimiento tras llegar el valor del cliente; vacío = no vence"
                   class="w-24 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm">
        </div>
        <div class="flex items-center justify-between mt-2">
            <label class="text-xs text-slate-300 flex items-center gap-1">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Activo
            </label>
            <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-3 py-1.5 rounded">
                + Agregar botón
            </button>
        </div>
    </form>

    {{-- Listado / edición --}}
    <div class="max-w-3xl space-y-3">
        @forelse ($buttons as $button)
            <div class="rounded-lg border border-white/10 bg-black/30 p-3">
                <form action="{{ route('buttons.update', $button) }}" method="POST">
                    @csrf @method('PUT')
                    <div class="flex gap-2 items-start">
                        <input type="number" name="sort_order" value="{{ $button->sort_order }}" title="Orden"
                               class="w-16 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm">
                        <input type="text" name="label" value="{{ $button->label }}"
                               class="w-48 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm">
                        <textarea name="text" rows="1"
                                  class="flex-1 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm">{{ $button->text }}</textarea>
                        <input type="text" name="url" value="{{ $button->url }}" placeholder="URL (interna)"
                               title="URL interna; la API GET la devuelve"
                               class="w-56 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm">
                        <input type="number" name="expires_seconds" min="1" value="{{ $button->expires_seconds }}"
                               placeholder="Vence (s)"
                               title="Segundos de vencimiento tras llegar el valor del cliente; vacío = no vence"
                               class="w-24 rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm">
                    </div>
                    <div class="flex items-center justify-between mt-2">
                        <label class="text-xs text-slate-300 flex items-center gap-1">
                            <input type="checkbox" name="is_active" value="1" @checked($button->is_active)> Activo
                        </label>
                        <button class="text-sky-400 hover:text-sky-300 text-sm">Guardar</button>
                    </div>
                </form>
                <form action="{{ route('buttons.destroy', $button) }}" method="POST" class="text-right mt-1 js-confirm"
                      data-confirm="Se eliminará el botón {{ $button->label }}.">
                    @csrf @method('DELETE')
                    <button class="text-red-400 hover:text-red-300 text-xs">Eliminar</button>
                </form>
            </div>
        @empty
            <p class="text-sm text-slate-500">Aún no hay botones. Crea el primero arriba.</p>
        @endforelse
    </div>
@endsection
