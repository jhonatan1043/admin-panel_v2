@extends('layouts.app')

@section('title', 'Editar proyecto')

@section('content')
<div class="max-w-xl mx-auto">
    <h1 class="text-lg font-semibold text-emerald-300 mb-4">Editar proyecto</h1>

    <form method="POST" action="{{ route('projects.update', $project) }}" class="space-y-4 border border-slate-700 rounded p-5 bg-black/20">
        @csrf
        @method('PUT')
        <div>
            <label class="block text-xs text-slate-400 mb-1">Nombre</label>
            <input type="text" name="name" value="{{ old('name', $project->name) }}" required
                   class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm focus:border-emerald-500 outline-none">
        </div>
        <div>
            <label class="block text-xs text-slate-400 mb-1">Descripción</label>
            <textarea name="description" rows="3"
                      class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm focus:border-emerald-500 outline-none">{{ old('description', $project->description) }}</textarea>
        </div>

        @if ($operators->isNotEmpty())
            <div>
                <label class="block text-xs text-slate-400 mb-1">Operador asignado</label>
                <select name="operator_id" class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm focus:border-emerald-500 outline-none">
                    <option value="">— Sin asignar —</option>
                    @foreach ($operators as $op)
                        <option value="{{ $op->id }}" @selected(old('operator_id', $project->operator_id) == $op->id)>{{ $op->name }} ({{ $op->role }})</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="flex items-center gap-2">
            <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">Guardar</button>
            <a href="{{ route('projects.show', $project) }}" class="text-slate-400 hover:text-slate-200 text-sm px-3 py-2">Cancelar</a>
        </div>
    </form>
</div>
@endsection
