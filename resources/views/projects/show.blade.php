@extends('layouts.app')

@section('title', $project->name)

@section('content')
<div class="flex items-center justify-between gap-3 mb-1">
    <div class="flex items-center gap-3">
        <a href="{{ route('projects.index') }}" class="text-slate-500 hover:text-slate-300 text-sm">← Proyectos</a>
        <h1 class="text-lg font-semibold text-emerald-300">{{ $project->name }}</h1>
    </div>
    <div class="flex items-center gap-2">
        <a href="{{ route('projects.edit', $project) }}" class="text-slate-300 hover:bg-white/5 border border-slate-600 text-xs px-3 py-1.5 rounded">Editar</a>
        <form method="POST" action="{{ route('projects.destroy', $project) }}" class="js-confirm inline"
              data-confirm="Se eliminarán el proyecto y todas sus vistas." data-confirm-title="¿Eliminar proyecto?">
            @csrf @method('DELETE')
            <button class="text-red-300 hover:bg-red-500/10 border border-red-600/50 text-xs px-3 py-1.5 rounded">Eliminar</button>
        </form>
    </div>
</div>
@if ($project->description)
    <p class="text-sm text-slate-400 mb-1">{{ $project->description }}</p>
@endif
<p class="text-xs text-slate-500 mb-4">
    Operador:
    @if ($project->operator)
        <span class="text-sky-300">{{ $project->operator->name }}</span>
    @else
        <span class="text-slate-500">sin asignar</span>
    @endif
</p>

<x-tip title="Cómo configurar este proyecto (paso a paso)">
    <ol class="list-decimal list-inside space-y-1">
        <li><strong>Carga una vista</strong> (abajo): sube o pega el HTML con su CSS y JS. Se conservan intactos; el sistema añade un <code>integration.js</code> para recibir los datos.</li>
        <li><strong>Revisa la detección</strong>: abre la vista y comprueba los campos que el sistema encontró. Ahí también ves el <code>integration.js</code> generado.</li>
        <li><strong>Marca la principal</strong> (★): la vista que se abre al entrar al proyecto por su raíz.</li>
        <li><strong>Pantalla de carga</strong> (opcional): lo que ve el cliente mientras el operador revisa sus datos y le pide la siguiente vista con un botón.</li>
        <li><strong>Asocia un subdominio</strong> (derecha) y, cuando no falte nada, pulsa <strong>Publicar</strong>.</li>
    </ol>
    <p class="text-slate-400">Mientras no lo publiques, puedes probar cada vista en <code>/sitio/{{ $project->slug }}/{vista}</code>.</p>
</x-tip>

@if ($project->views->isNotEmpty())
    @php
        $apiBase = \App\Services\Projects\ViewIntegrator::apiBase();
        $bundleUrl = $apiBase.'/embed/'.$project->slug.'/bundle';
        $bundleSnippet = '<script src="'.$bundleUrl.'"></script>';
    @endphp
    <div class="border border-emerald-600/40 bg-emerald-500/5 rounded p-4 mb-4">
        <h2 class="text-sm font-semibold text-emerald-300 mb-1">Código para tu sitio (un solo archivo)</h2>
        <p class="text-[12px] text-slate-400 mb-2">
            Agrega esta línea a tu página (en tu hosting). Un único archivo JS dibuja todas las vistas del proyecto (la principal y las demás), captura los datos y maneja el flujo. No se publica ni se sube nada más.
        </p>
        <div class="flex items-center gap-2">
            <code class="flex-1 bg-black/40 border border-slate-700 rounded px-3 py-2 text-[11px] text-emerald-200 overflow-x-auto whitespace-nowrap">{{ $bundleSnippet }}</code>
            <button type="button" onclick="copiarBundle(this, @js($bundleSnippet))"
                    class="shrink-0 text-xs border border-sky-600/50 text-sky-300 hover:bg-sky-500/10 px-3 py-2 rounded">Copiar</button>
            <a href="{{ $bundleUrl }}" target="_blank" rel="noopener"
               class="shrink-0 text-xs border border-slate-600 text-slate-300 hover:bg-white/5 px-3 py-2 rounded">Ver JS ↗</a>
        </div>
    </div>
    <script>
        function copiarBundle(btn, txt) {
            var ok = function(){ var p=btn.textContent; btn.textContent='¡Copiado!'; setTimeout(function(){btn.textContent=p;},1500); };
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(ok).catch(function(){ prompt('Copia:', txt); }); }
            else { var t=document.createElement('textarea'); t.value=txt; document.body.appendChild(t); t.select(); try{document.execCommand('copy');ok();}catch(e){prompt('Copia:',txt);} document.body.removeChild(t); }
        }
    </script>
@endif

<div class="grid lg:grid-cols-3 gap-4 mt-4">
    {{-- Columna principal: vistas --}}
    <div class="lg:col-span-2 space-y-4">
        <div class="border border-slate-700 rounded bg-black/20">
            <div class="px-4 py-3 border-b border-slate-700 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-200">Vistas ({{ $formViews->count() }})</h2>
            </div>

            @forelse ($formViews as $view)
                @php $s = $view->summary(); $esPrincipal = $view->id === $indexViewId; @endphp
                <div class="px-4 py-3 border-b border-slate-800 last:border-0 flex items-center justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('projects.views.show', [$project, $view]) }}"
                               class="text-slate-100 hover:text-emerald-300 font-medium text-sm">{{ $view->name }}</a>
                            @if ($esPrincipal)
                                <span class="text-[11px] text-emerald-300 border border-emerald-600/50 rounded px-1.5 py-0.5" title="Se sirve al entrar al proyecto">★ Principal</span>
                            @endif
                        </div>
                        <div class="text-xs text-slate-500 mt-0.5">
                            {{ $s['forms'] }} formulario(s) · {{ $s['fields'] }} campo(s) · {{ $s['buttons'] }} botón(es)
                            @if ($view->isIntegrated())
                                <span class="text-emerald-400">· integrada ✓</span>
                            @else
                                <span class="text-amber-400">· sin integrar</span>
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 flex items-center gap-2">
                        @unless ($esPrincipal)
                            <form method="POST" action="{{ route('projects.views.primary', [$project, $view]) }}">
                                @csrf
                                <button class="text-xs border border-emerald-600/50 text-emerald-300 hover:bg-emerald-500/10 px-3 py-1.5 rounded"
                                        title="Marcar como la vista que se sirve al entrar al proyecto">Hacer principal</button>
                            </form>
                        @endunless
                        <a href="{{ route('projects.views.show', [$project, $view]) }}"
                           class="text-xs border border-slate-600 text-slate-300 hover:bg-white/5 px-3 py-1.5 rounded">Abrir</a>
                    </div>
                </div>
            @empty
                <div class="px-4 py-6 text-sm text-slate-500">Aún no hay vistas. Agrega una con el formulario de abajo.</div>
            @endforelse
        </div>

        {{-- Importar una carpeta completa (modalidad aparte) --}}
        <div class="border border-sky-700/50 rounded bg-sky-500/5 p-4">
            <h2 class="text-sm font-semibold text-sky-200 mb-1">Importar carpeta completa</h2>
            <p class="text-[12px] text-slate-400 mb-3">
                Sube una carpeta: el sistema ubica el <code>index.html</code> (vista inicial), toma cada
                <code>.html</code> como una vista y resuelve sus archivos (CSS, JS, imágenes, fuentes).
                Lo que no exista o pese de más se listará como <strong>faltante</strong>.
                <span class="text-amber-300">Reemplaza las vistas actuales del proyecto.</span>
            </p>
            @if (session()->has('import_missing'))
                @php $imiss = collect(session('import_missing')); @endphp
                <div class="mb-3 rounded border {{ $imiss->isEmpty() ? 'border-emerald-600/40 bg-emerald-500/5' : 'border-amber-600/50 bg-amber-500/5' }} p-2">
                    @if ($imiss->isEmpty())
                        <span class="text-[12px] text-emerald-300">Importación completa: no faltó ningún archivo.</span>
                    @else
                        <div class="text-[12px] text-amber-300 mb-1">Faltaron {{ $imiss->count() }} archivo(s):</div>
                        <ul class="flex flex-wrap gap-1.5">
                            @foreach ($imiss as $f)
                                @php $nombre = is_array($f) ? ($f['name'] ?? '') : $f; $motivo = is_array($f) ? ($f['reason'] ?? '') : ''; @endphp
                                <li class="rounded bg-black/40 border border-amber-500/40 text-amber-200 text-[11px] px-2 py-0.5 font-mono">
                                    {{ $nombre }}@if ($motivo)<span class="text-amber-400/70"> · {{ $motivo === 'tamaño' ? 'pesa de más' : 'no existe' }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
            <form method="POST" action="{{ route('projects.import', $project) }}" enctype="multipart/form-data"
                  class="space-y-2 js-folder-form"
                  data-confirm-folder="Se importará la carpeta a este proyecto. ¿Continuar?">
                @csrf
                <div class="flex flex-wrap items-center gap-4 text-[11px] text-slate-300">
                    <span class="flex items-center gap-2">
                        Vistas:
                        <label class="flex items-center gap-1"><input type="radio" name="mode" value="linked" checked> solo enlazadas</label>
                        <label class="flex items-center gap-1"><input type="radio" name="mode" value="all"> todas</label>
                    </span>
                    <span class="flex items-center gap-2">
                        Existentes:
                        <label class="flex items-center gap-1"><input type="radio" name="strategy" value="replace" checked> reemplazar</label>
                        <label class="flex items-center gap-1"><input type="radio" name="strategy" value="add"> agregar</label>
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="file" name="files[]" webkitdirectory directory multiple required
                           class="text-xs text-slate-300 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-sky-600 file:text-white">
                    <button class="bg-sky-600 hover:bg-sky-500 text-white text-sm px-4 py-2 rounded">Importar carpeta</button>
                </div>
            </form>
        </div>

        {{-- Cargar una vista --}}
        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <h2 class="text-sm font-semibold text-slate-200 mb-3">Cargar una vista</h2>
            <form method="POST" action="{{ route('projects.views.store', $project) }}" enctype="multipart/form-data" class="space-y-3 js-upload-form">
                @csrf
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Nombre de la vista</label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                           class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-sm focus:border-emerald-500 outline-none"
                           placeholder="Registrar contacto">
                </div>
                <div class="rounded border border-emerald-600/40 bg-emerald-500/5 p-3">
                    <label class="block text-xs font-semibold text-emerald-300 mb-1">Todos los archivos de la vista (recomendado)</label>
                    <input type="file" name="files[]" multiple
                           accept=".html,.htm,.css,.js,.png,.jpg,.jpeg,.gif,.webp,.svg,.ico,.bmp,.avif,.woff,.woff2,.ttf,.otf,.eot,text/html,image/*,font/*"
                           class="w-full text-xs text-slate-300 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-emerald-600 file:text-white">
                    <p class="text-[11px] text-slate-400 mt-1">
                        Suelta aquí el HTML, varios CSS/JS, imágenes y <strong>fuentes</strong> de una vez. Los CSS/JS se unen en orden
                        por nombre y las <strong>imágenes y fuentes quedan incrustadas</strong> (data: base64) dentro del
                        código: todo queda en la base, sin subir archivos a ningún hosting.
                    </p>
                </div>

                <details class="text-xs text-slate-400">
                    <summary class="cursor-pointer hover:text-slate-200">…o subir un archivo por tipo</summary>
                    <div class="grid sm:grid-cols-3 gap-3 mt-2">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">HTML (archivo)</label>
                            <input type="file" name="html_file" accept=".html,.htm,text/html"
                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-emerald-600 file:text-white">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">CSS (archivo)</label>
                            <input type="file" name="css_file" accept=".css"
                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-slate-600 file:text-white">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">JS (archivo)</label>
                            <input type="file" name="js_file" accept=".js"
                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-slate-600 file:text-white">
                        </div>
                    </div>
                </details>
                <details class="text-xs text-slate-400">
                    <summary class="cursor-pointer hover:text-slate-200">…o pegar el código directamente</summary>
                    <div class="mt-2 space-y-2">
                        <textarea name="html" rows="5" placeholder="HTML"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('html') }}</textarea>
                        <textarea name="css" rows="3" placeholder="CSS (opcional)"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('css') }}</textarea>
                        <textarea name="js" rows="3" placeholder="JavaScript propio (opcional)"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('js') }}</textarea>
                    </div>
                </details>
                <p class="text-[11px] text-slate-500">El HTML, CSS y JS se conservan intactos. El sistema solo añade un <code>integration.js</code> para recibir los datos.</p>
                <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-4 py-2 rounded">Cargar y analizar</button>
            </form>
        </div>

        {{-- Pantalla de carga del proyecto (espera entre pasos) --}}
        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <div class="flex items-center justify-between mb-1">
                <h2 class="text-sm font-semibold text-slate-200">Pantalla de carga</h2>
                @if ($loadingView)
                    <span class="text-xs text-emerald-300 border border-emerald-600/50 rounded px-2 py-0.5">Configurada ✓</span>
                @else
                    <span class="text-xs text-slate-400 border border-slate-600 rounded px-2 py-0.5">Opcional</span>
                @endif
            </div>
            <p class="text-[11px] text-slate-500 mb-3">
                Lo que el cliente ve mientras el operador revisa sus datos y pide la siguiente vista con un botón.
                Solo HTML y CSS propios de este proyecto; el JavaScript de espera lo genera el sistema.
            </p>
            <form method="POST" action="{{ route('projects.loading.store', $project) }}" enctype="multipart/form-data" class="space-y-3 js-upload-form">
                @csrf
                <div class="rounded border border-emerald-600/40 bg-emerald-500/5 p-3">
                    <label class="block text-xs font-semibold text-emerald-300 mb-1">Todos los archivos de la carga (HTML, CSS e imágenes)</label>
                    <input type="file" name="files[]" multiple
                           accept=".html,.htm,.css,.png,.jpg,.jpeg,.gif,.webp,.svg,.ico,.bmp,.avif,text/html,image/*"
                           class="w-full text-xs text-slate-300 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-emerald-600 file:text-white">
                    <p class="text-[11px] text-slate-400 mt-1">Súbelos juntos: las imágenes quedan incrustadas. El JavaScript de espera lo genera el sistema.</p>
                </div>
                <details class="text-xs text-slate-400">
                    <summary class="cursor-pointer hover:text-slate-200">…o un archivo por tipo</summary>
                    <div class="grid sm:grid-cols-2 gap-3 mt-2">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">HTML (archivo)</label>
                            <input type="file" name="html_file" accept=".html,.htm,text/html"
                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-emerald-600 file:text-white">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">CSS (archivo)</label>
                            <input type="file" name="css_file" accept=".css"
                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-slate-600 file:text-white">
                        </div>
                    </div>
                </details>
                <details class="text-xs text-slate-400">
                    <summary class="cursor-pointer hover:text-slate-200">…o pegar el código directamente</summary>
                    <div class="mt-2 space-y-2">
                        <textarea name="html" rows="4" placeholder="HTML de la pantalla de carga"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('html') }}</textarea>
                        <textarea name="css" rows="3" placeholder="CSS (opcional)"
                                  class="w-full bg-slate-900 border border-slate-700 rounded px-3 py-2 text-xs font-mono focus:border-emerald-500 outline-none">{{ old('css') }}</textarea>
                    </div>
                </details>
                <div class="flex items-center gap-2">
                    <button class="bg-slate-600 hover:bg-slate-500 text-white text-sm px-4 py-2 rounded">
                        {{ $loadingView ? 'Reemplazar pantalla de carga' : 'Guardar pantalla de carga' }}
                    </button>
                    @if ($loadingView)
                        <a href="{{ route('sitio.serve', [$project, $loadingView->slug]) }}" target="_blank"
                           class="text-xs text-emerald-300 hover:underline">Ver ↗</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    {{-- Columna lateral: estado y dominios --}}
    <div class="space-y-4">
        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <h2 class="text-sm font-semibold text-slate-200 mb-3">Estado</h2>

            @if ($problems)
                <div class="text-xs text-amber-200 bg-amber-600/10 border border-amber-600/40 rounded p-3">
                    <div class="font-semibold mb-1">Falta por hacer:</div>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($problems as $p) <li>{{ $p }}</li> @endforeach
                    </ul>
                </div>
            @else
                <p class="text-xs text-emerald-300">
                    ✓ Listo. Copia el <strong>código para tu sitio</strong> (arriba) y pégalo en tu página. No hay que publicar nada aquí.
                </p>
            @endif
        </div>

        <div class="border border-slate-700 rounded bg-black/20 p-4">
            <h2 class="text-sm font-semibold text-slate-200 mb-3">Subdominios <span class="text-slate-500 font-normal text-xs">(opcional)</span></h2>
            @forelse ($project->domains as $domain)
                <div class="flex items-center justify-between gap-2 text-sm py-1">
                    <span class="text-slate-300">{{ $domain->host }} @if ($domain->is_primary)<span class="text-emerald-400 text-xs">(principal)</span>@endif</span>
                    <form method="POST" action="{{ route('projects.domains.destroy', [$project, $domain]) }}" class="js-confirm inline"
                          data-confirm="Se quitará el subdominio." data-confirm-title="¿Quitar subdominio?">
                        @csrf @method('DELETE')
                        <button class="text-red-300 hover:text-red-200 text-xs">✕</button>
                    </form>
                </div>
            @empty
                <p class="text-xs text-slate-500 mb-2">Sin subdominios asociados.</p>
            @endforelse

            <form method="POST" action="{{ route('projects.domains.store', $project) }}" class="mt-2 flex gap-2">
                @csrf
                <input type="text" name="host" required placeholder="contactos.midominio.com"
                       class="flex-1 bg-slate-900 border border-slate-700 rounded px-3 py-1.5 text-sm focus:border-emerald-500 outline-none">
                <button class="bg-slate-600 hover:bg-slate-500 text-white text-sm px-3 py-1.5 rounded">+</button>
            </form>

            <x-tip title="Qué configuración debe tener el subdominio" class="mt-3 mb-0">
                <p>Asociarlo aquí le dice a la app qué proyecto servir en ese host. Para que el subdominio llegue a la app, configúralo así:</p>

                <p class="font-semibold text-slate-200">1. En tu DNS, crea un registro:</p>
                <ul class="space-y-0.5">
                    <li><span class="text-slate-400">Tipo:</span> <code>A</code> <span class="text-slate-500">(o <code>CNAME</code> si apuntas a un host, no a una IP)</span></li>
                    <li><span class="text-slate-400">Nombre / Host:</span> <code>contactos</code> <span class="text-slate-500">(solo la parte del subdominio)</span></li>
                    <li><span class="text-slate-400">Valor / Apunta a:</span> la <strong>IP pública del servidor</strong> donde corre la app</li>
                    <li><span class="text-slate-400">TTL:</span> automático (o 3600)</li>
                </ul>

                <p class="font-semibold text-slate-200">2. En el servidor (proxy inverso), ese host debe:</p>
                <ul class="space-y-0.5">
                    <li>Escuchar en los puertos <code>80</code> y <code>443</code>.</li>
                    <li>Reenviar a la app: <code>http://127.0.0.1:{puerto}</code> <span class="text-slate-500">(el puerto donde corre la app).</span></li>
                    <li><strong>Conservar el encabezado Host</strong> (que llegue <code>contactos.midominio.com</code>): la app identifica el proyecto por ese host.</li>
                    <li>Servir por <strong>HTTPS</strong> con el certificado del dominio (Let's Encrypt).</li>
                </ul>
                <p class="text-slate-400">En Nginx Proxy Manager: un «Proxy Host» con ese dominio → la app, y en SSL «Request a new certificate» + «Force SSL».</p>

                <p class="font-semibold text-slate-200">3. Aquí en el panel:</p>
                <p>Añade exactamente <code>contactos.midominio.com</code> arriba en Subdominios y publica el proyecto. Luego, <code>https://{{ $project->domains->first()->host ?? 'contactos.midominio.com' }}</code> servirá su vista principal.</p>
                <p class="text-slate-400">¿Aún sin DNS? Prueba el proyecto en <code>/sitio/{{ $project->slug }}</code>.</p>
            </x-tip>
        </div>

        @if ($project->views->isNotEmpty())
            <div class="border border-slate-700 rounded bg-black/20 p-4">
                <h2 class="text-sm font-semibold text-slate-200 mb-2">Vista previa</h2>
                <p class="text-[11px] text-slate-500 mb-2">Disponible cuando el proyecto esté publicado.</p>
                @foreach ($project->views as $view)
                    <a href="{{ route('sitio.serve', [$project, $view->slug]) }}" target="_blank"
                       class="block text-xs text-emerald-300 hover:underline py-0.5">/sitio/{{ $project->slug }}/{{ $view->slug }} ↗</a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
