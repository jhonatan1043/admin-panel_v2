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
            @php
                // Enlace público del proyecto: su subdominio si tiene, o la ruta /sitio/{slug}.
                $publicUrl = $project->domains->isNotEmpty()
                    ? 'https://'.$project->domains->first()->host
                    : route('sitio.serve', $project);
            @endphp
            <div class="border border-slate-700 hover:border-emerald-500/60 rounded p-4 bg-black/20 transition">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('projects.show', $project) }}"
                           class="font-semibold text-slate-100 hover:text-emerald-300">{{ $project->name }}</a>
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

                <div class="flex flex-wrap items-center gap-2 mt-3">
                    <a href="{{ route('projects.show', $project) }}"
                       class="text-xs border border-slate-600 text-slate-300 hover:bg-white/5 px-3 py-1.5 rounded">Administrar</a>
                    @if ($project->isPublished())
                        <a href="{{ $publicUrl }}" target="_blank" rel="noopener"
                           class="text-xs border border-emerald-600/50 text-emerald-300 hover:bg-emerald-500/10 px-3 py-1.5 rounded">Abrir sitio ↗</a>
                        <button type="button" onclick="copiarLink(this, @js($publicUrl))"
                                class="text-xs border border-sky-600/50 text-sky-300 hover:bg-sky-500/10 px-3 py-1.5 rounded">Copiar link</button>
                        <span class="text-[11px] text-slate-500 truncate">{{ $publicUrl }}</span>
                    @else
                        <span class="text-[11px] text-slate-500">Publica el proyecto para obtener su enlace público.</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <script>
        function copiarLink(btn, url) {
            function ok() {
                var prev = btn.textContent;
                btn.textContent = '¡Copiado!';
                setTimeout(function () { btn.textContent = prev; }, 1500);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(ok).catch(function () { prompt('Copia el enlace:', url); });
            } else {
                // Respaldo para navegadores sin API de portapapeles.
                var ta = document.createElement('textarea');
                ta.value = url; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); ok(); } catch (e) { prompt('Copia el enlace:', url); }
                document.body.removeChild(ta);
            }
        }
    </script>
@endif
@endsection
