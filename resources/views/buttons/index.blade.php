@extends('layouts.app')

@section('title', 'Botones')

@php
    $field = 'w-full rounded bg-black/40 border border-slate-600 px-2 py-1.5 text-sm focus:border-emerald-500 outline-none';
    $lbl = 'block text-[11px] uppercase tracking-wide text-slate-500 mb-1';
@endphp

@push('head')
@endpush

@section('content')
    <style>
        dialog.btn-dialog {
            background: #0b1220;
            color: #e2e8f0;
            border: 1px solid rgba(16, 185, 129, .3);
            border-radius: .75rem;
            padding: 0;
            width: min(92vw, 640px);
        }
        dialog.btn-dialog::backdrop { background: rgba(0, 0, 0, .6); }
    </style>

    <div class="max-w-5xl">
        <div class="flex items-center justify-between mb-1">
            <h1 class="text-xl font-semibold">Botones
                <span class="text-xs text-slate-500 font-normal">· catálogo global · Total: {{ $buttons->count() }}</span>
            </h1>
            <button type="button" onclick="document.getElementById('dlg-new').showModal()"
                    class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">+ Nuevo botón</button>
        </div>
        <p class="text-sm text-slate-400 mb-5">
            Cada botón envía su texto al cliente por Telegram, o abre una vista de proyecto, al presionarlo en su tarjeta.
        </p>

        {{-- Listado en filas compactas --}}
        <div class="rounded-lg border border-white/10 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-black/40 text-slate-400 text-xs">
                    <tr class="text-left">
                        <th class="font-normal px-3 py-2 w-12">#</th>
                        <th class="font-normal px-3 py-2">Nombre</th>
                        <th class="font-normal px-3 py-2">Texto</th>
                        <th class="font-normal px-3 py-2">Abre vista</th>
                        <th class="font-normal px-3 py-2 w-20">Vence</th>
                        <th class="font-normal px-3 py-2 w-20">Estado</th>
                        <th class="font-normal px-3 py-2 w-28 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($buttons as $button)
                        <tr class="border-t border-white/5 hover:bg-white/5">
                            <td class="px-3 py-2 text-slate-500">{{ $button->sort_order }}</td>
                            <td class="px-3 py-2 font-medium text-slate-100">{{ $button->label }}</td>
                            <td class="px-3 py-2 text-slate-400 max-w-xs truncate" title="{{ $button->text }}">{{ $button->text }}</td>
                            <td class="px-3 py-2">
                                @if ($button->projectView)
                                    <span class="text-[11px] text-sky-300 border border-sky-600/40 rounded px-1.5 py-0.5">{{ $button->projectView->name }}</span>
                                @else
                                    <span class="text-slate-600">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-400">{{ $button->expires_seconds ? $button->expires_seconds.'s' : '—' }}</td>
                            <td class="px-3 py-2">
                                @if ($button->is_active)
                                    <span class="text-[11px] text-emerald-300 border border-emerald-600/40 rounded px-1.5 py-0.5">Activo</span>
                                @else
                                    <span class="text-[11px] text-slate-400 border border-slate-600 rounded px-1.5 py-0.5">Inactivo</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <button type="button" onclick="document.getElementById('dlg-{{ $button->id }}').showModal()"
                                        class="text-sky-400 hover:text-sky-300 text-xs">Editar</button>
                                <form action="{{ route('buttons.destroy', $button) }}" method="POST" class="js-confirm inline ml-2"
                                      data-confirm="Se eliminará el botón {{ $button->label }}.">
                                    @csrf @method('DELETE')
                                    <button class="text-red-400 hover:text-red-300 text-xs">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500">Aún no hay botones. Crea el primero con «+ Nuevo botón».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal: nuevo botón --}}
    <dialog id="dlg-new" class="btn-dialog">
        <form action="{{ route('buttons.store') }}" method="POST" class="p-5">
            @csrf
            <h2 class="text-sm font-semibold text-emerald-300 mb-4">Nuevo botón</h2>
            @include('buttons._fields', ['button' => null])
            <div class="flex justify-end gap-2 mt-5">
                <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-slate-200 text-sm px-3 py-2">Cancelar</button>
                <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">Agregar botón</button>
            </div>
        </form>
    </dialog>

    {{-- Modal de edición por botón --}}
    @foreach ($buttons as $button)
        <dialog id="dlg-{{ $button->id }}" class="btn-dialog">
            <form action="{{ route('buttons.update', $button) }}" method="POST" class="p-5">
                @csrf @method('PUT')
                <h2 class="text-sm font-semibold text-sky-300 mb-4">Editar «{{ $button->label }}»</h2>
                @include('buttons._fields', ['button' => $button])
                <div class="flex justify-end gap-2 mt-5">
                    <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-slate-200 text-sm px-3 py-2">Cancelar</button>
                    <button class="bg-sky-600 hover:bg-sky-500 text-white text-sm px-4 py-2 rounded">Guardar cambios</button>
                </div>
            </form>
        </dialog>
    @endforeach

    <script>
        // Si una validación falló, reabrir el modal de alta para no perder los datos.
        @if ($errors->any() && ! old('_edit_id'))
            document.getElementById('dlg-new')?.showModal();
        @endif
    </script>
@endsection
