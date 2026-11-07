@extends('layouts.app')

@section('title', 'Nuevo proyecto')

@section('content')
<div class="max-w-xl mx-auto">
    <h1 class="text-lg font-semibold text-emerald-300 mb-4">Nuevo proyecto</h1>

    <x-tip title="Primer paso">
        <p>Solo necesitas un <strong>nombre</strong> para empezar (por ejemplo, el de la empresa o la campaña). Después, en la página del proyecto, cargarás sus vistas y lo publicarás.</p>
        @if ($operators->isNotEmpty())
            <p class="text-slate-400">Como administrador, puedes <strong>asignarlo a un operador</strong>: será su dueño y podrá gestionarlo. Déjalo «Sin asignar» para administrarlo tú.</p>
        @endif
    </x-tip>

    <form method="POST" action="{{ route('projects.store') }}" class="space-y-4 border border-slate-700 rounded p-5 bg-black/20">
        @csrf
        <div>
            <label class="block text-xs text-slate-400 mb-1">Nombre</label>
            <input type="text" name="name" value="{{ old('name') }}" required autofocus
                   class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm focus:border-emerald-500 outline-none"
                   placeholder="Call Center Norte">
        </div>
        <div>
            <label class="block text-xs text-slate-400 mb-1">Descripción (opcional)</label>
            <textarea name="description" rows="3"
                      class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm focus:border-emerald-500 outline-none"
                      placeholder="Vistas de contacto y solicitudes del call center norte.">{{ old('description') }}</textarea>
        </div>

        @if ($operators->isNotEmpty())
            <div>
                <label class="block text-xs text-slate-400 mb-1">Asignar a operador (opcional)</label>
                <select name="operator_id" class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm focus:border-emerald-500 outline-none">
                    <option value="">— Sin asignar —</option>
                    @foreach ($operators as $op)
                        <option value="{{ $op->id }}" @selected(old('operator_id') == $op->id)>{{ $op->name }} ({{ $op->role }})</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="flex items-center gap-2">
            <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">Crear proyecto</button>
            <a href="{{ route('projects.index') }}" class="text-slate-400 hover:text-slate-200 text-sm px-3 py-2">Cancelar</a>
        </div>
    </form>
</div>
@endsection
