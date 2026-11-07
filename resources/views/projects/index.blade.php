@extends('layouts.app')

@section('title', 'Proyectos')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold text-emerald-300">Proyectos</h1>
    <a href="{{ route('projects.create') }}"
       class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">+ Nuevo proyecto</a>
</div>

<x-tip title="¿Qué es un proyecto y cómo funciona?">
    <p>Un <strong>proyecto</strong> agrupa las páginas web (<strong>vistas</strong>) de una empresa o campaña. Cargas tu HTML/CSS/JS tal cual y el sistema genera solo la conexión para recibir los datos.</p>
    <p class="text-slate-400">Flujo general:</p>
    <ol class="list-decimal list-inside space-y-0.5 text-slate-400">
        <li>Crea el proyecto y ponle nombre.</li>
        <li>Carga una o varias vistas (formularios). El sistema las analiza solo.</li>
        <li>Marca cuál es la <strong>vista principal</strong> (la que abre al entrar).</li>
        <li>Opcional: carga una <strong>pantalla de carga</strong> para la espera entre pasos.</li>
        <li>Asocia un <strong>subdominio</strong> y pulsa <strong>Publicar</strong>.</li>
    </ol>
</x-tip>

@php
    $badge = [
        'published' => 'bg-emerald-600/20 border-emerald-500 text-emerald-200',
        'unpublished' => 'bg-amber-600/20 border-amber-500 text-amber-200',
        'draft' => 'bg-slate-600/20 border-slate-500 text-slate-300',
    ];
    $label = ['published' => 'Publicado', 'unpublished' => 'Despublicado', 'draft' => 'Borrador'];
@endphp

@if ($projects->isEmpty())
    <div class="text-slate-400 text-sm border border-slate-700 rounded p-8 text-center">
        Todavía no hay proyectos. Crea el primero para cargar sus vistas HTML.
    </div>
@else
    <div class="grid gap-3">
        @foreach ($projects as $project)
            <a href="{{ route('projects.show', $project) }}"
               class="block border border-slate-700 hover:border-emerald-500/60 rounded p-4 bg-black/20 transition">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-semibold text-slate-100">{{ $project->name }}</div>
                        <div class="text-xs text-slate-400 mt-0.5">
                            {{ $project->views_count }} vista(s)
                            @if ($project->operator)
                                · <span class="text-sky-300">{{ $project->operator->name }}</span>
                            @else
                                · <span class="text-slate-500">sin asignar</span>
                            @endif
                            @if ($project->domains->isNotEmpty())
                                · {{ $project->domains->pluck('host')->implode(', ') }}
                            @endif
                            · actualizado {{ $project->updated_at?->diffForHumans() }}
                        </div>
                    </div>
                    <span class="shrink-0 text-xs px-2 py-1 rounded border {{ $badge[$project->status] ?? $badge['draft'] }}">
                        {{ $label[$project->status] ?? 'Borrador' }}
                    </span>
                </div>
            </a>
        @endforeach
    </div>
@endif
@endsection
