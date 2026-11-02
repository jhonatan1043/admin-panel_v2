@extends('layouts.app')

@section('title', 'Editar registro')

@section('content')
    <h1 class="text-xl font-semibold mb-4">Editar registro: {{ $company->registro }}</h1>

    <form action="{{ route('companies.update', $company) }}" method="POST" enctype="multipart/form-data">
        @method('PUT')
        @include('companies._form')
    </form>

    <hr class="my-8 border-slate-700">

    <section class="max-w-xl">
        <h2 class="text-lg font-semibold mb-3">Botones / plantillas</h2>
        <p class="text-sm text-slate-400 mb-4">
            Cada botón es un texto que el agente envía al cliente al pulsarlo.
        </p>

        @forelse ($company->messageTemplates()->orderBy('sort_order')->get() as $template)
            <form action="{{ route('companies.templates.update', [$company, $template]) }}" method="POST"
                  class="mb-3 rounded border border-slate-700 bg-slate-800/50 p-3">
                @csrf @method('PUT')
                <div class="flex gap-2 items-start">
                    <input type="number" name="sort_order" value="{{ $template->sort_order }}"
                           class="w-16 rounded bg-slate-800 border border-slate-600 px-2 py-1.5 text-sm" title="Orden">
                    <input type="text" name="label" value="{{ $template->label }}"
                           class="w-40 rounded bg-slate-800 border border-slate-600 px-2 py-1.5 text-sm" placeholder="Botón">
                    <textarea name="body" rows="1"
                              class="flex-1 rounded bg-slate-800 border border-slate-600 px-2 py-1.5 text-sm"
                              placeholder="Mensaje">{{ $template->body }}</textarea>
                </div>
                <div class="flex items-center justify-between mt-2">
                    <label class="text-xs text-slate-300 flex items-center gap-1">
                        <input type="checkbox" name="is_active" value="1" @checked($template->is_active)> Activo
                    </label>
                    <div class="flex items-center gap-3">
                        <button class="text-sky-400 hover:text-sky-300 text-sm">Guardar</button>
                </div>
                </div>
            </form>
            <form action="{{ route('companies.templates.destroy', [$company, $template]) }}" method="POST"
                  class="-mt-2 mb-4 text-right js-confirm" data-confirm="Se eliminará este botón.">
                @csrf @method('DELETE')
                <button class="text-red-400 hover:text-red-300 text-xs">Eliminar botón</button>
            </form>
        @empty
            <p class="text-sm text-slate-500 mb-4">Aún no hay botones.</p>
        @endforelse

        <form action="{{ route('companies.templates.store', $company) }}" method="POST"
              class="rounded border border-dashed border-slate-600 p-3 mt-4">
            @csrf
            <div class="flex gap-2 items-start">
                <input type="number" name="sort_order" value="0"
                       class="w-16 rounded bg-slate-800 border border-slate-600 px-2 py-1.5 text-sm" title="Orden">
                <input type="text" name="label"
                       class="w-40 rounded bg-slate-800 border border-slate-600 px-2 py-1.5 text-sm" placeholder="Botón" required>
                <textarea name="body" rows="1"
                          class="flex-1 rounded bg-slate-800 border border-slate-600 px-2 py-1.5 text-sm"
                          placeholder="Mensaje" required></textarea>
            </div>
            <div class="text-right mt-2">
                <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-3 py-1.5 rounded">
                    + Agregar botón
                </button>
            </div>
        </form>
    </section>
@endsection
