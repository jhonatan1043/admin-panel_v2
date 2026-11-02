@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
    {{-- Filtro: por empresa y por búsqueda (nombre o registro). Estático: no se recarga con el polling. --}}
    @if ($companies->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 mb-5 text-xs">
            <select id="filtro-empresa"
                    class="rounded bg-slate-800 border border-slate-600 px-2 py-1.5 text-slate-200">
                <option value="">Todas las empresas</option>
                @foreach ($companiesByEmpresa->keys() as $emp)
                    <option value="{{ $emp }}">{{ $emp }}</option>
                @endforeach
            </select>
            <input id="filtro-busqueda" type="search" autocomplete="off"
                   placeholder="Buscar por nombre o registro…"
                   class="rounded bg-slate-800 border border-slate-600 px-3 py-1.5 text-slate-200 flex-1 min-w-[180px]">
            <span id="filtro-vacio" class="hidden text-slate-500">Sin coincidencias.</span>
            <span id="live-dot" class="ml-auto flex items-center gap-1.5 text-[10px] text-slate-500" title="Actualización en vivo">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> en vivo
            </span>
        </div>
    @endif

    {{-- La grilla se refresca sola por polling; su contenido viene de companies._board. --}}
    <div id="board">
        @include('companies._board')
    </div>

    <script>
        // Presionar un botón: confirma con SweetAlert y envía el texto al Telegram del cliente.
        function sendMessage(e, btn) {
            e.stopPropagation();
            var company = btn.dataset.company;
            var buttonId = btn.dataset.button;
            var label = btn.dataset.label;
            var client = btn.dataset.client;

            Swal.fire({
                title: '¿Enviar mensaje?',
                html: 'Botón <b>' + label + '</b> al Telegram de <b>' + client + '</b>.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Enviar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#059669',
            }).then(function (r) {
                if (!r.isConfirmed) return;

                fetch('/api/companies/' + company + '/send', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ button_id: Number(buttonId) }),
                }).then(async function (res) {
                    var data = await res.json().catch(function () { return {}; });
                    if (res.ok && data.ok) {
                        Swal.fire({ icon: 'success', title: 'Enviado', text: data.message || 'Mensaje enviado.', timer: 2000, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'No se pudo enviar', text: data.message || ('Error ' + res.status) });
                    }
                }).catch(function () {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo contactar al servidor.' });
                });
            });
        }

        // Delegación: abre solo la tarjeta seleccionada, el resto se cierra.
        document.addEventListener('click', function (e) {
            var card = e.target.closest('.client-card');
            if (!card) return;
            if (e.target.closest('form')) return; // no togglear al usar "borrar"

            var willOpen = !card.classList.contains('is-open');
            document.querySelectorAll('.client-card.is-open').forEach(function (c) {
                c.classList.remove('is-open');
            });
            if (willOpen) {
                card.classList.add('is-open');
            }
        });

        // Filtro del tablero: por empresa y por texto (nombre o registro).
        function aplicarFiltro() {
            var selEmpresa = document.getElementById('filtro-empresa');
            var inpBusqueda = document.getElementById('filtro-busqueda');
            var vacio = document.getElementById('filtro-vacio');
            if (!selEmpresa) return;

            var emp = selEmpresa.value.toLowerCase();
            var q = (inpBusqueda.value || '').trim().toLowerCase();
            var totalVisibles = 0;

            document.querySelectorAll('.empresa-group').forEach(function (sec) {
                var empOk = !emp || (sec.dataset.empresa || '').toLowerCase() === emp;
                var visibles = 0;

                sec.querySelectorAll('.client-card').forEach(function (card) {
                    var match = empOk && (!q || (card.dataset.search || '').indexOf(q) !== -1);
                    card.style.display = match ? '' : 'none';
                    if (match) visibles++;
                });

                // Al buscar, abre "OTROS" para revelar coincidencias ocultas.
                sec.querySelectorAll('details').forEach(function (d) { d.open = !!q; });

                sec.style.display = (empOk && visibles > 0) ? '' : 'none';
                totalVisibles += visibles;
            });

            if (vacio) vacio.classList.toggle('hidden', totalVisibles > 0);
        }

        (function () {
            var selEmpresa = document.getElementById('filtro-empresa');
            var inpBusqueda = document.getElementById('filtro-busqueda');
            if (selEmpresa) selEmpresa.addEventListener('change', aplicarFiltro);
            if (inpBusqueda) inpBusqueda.addEventListener('input', aplicarFiltro);
        })();

        // Tablero en vivo: refresca la grilla cada 5s sin recargar la página.
        (function () {
            var BOARD_URL = @json(route('companies.board'));
            var INTERVALO = 5000;

            function refrescar() {
                // No interrumpir si hay una tarjeta abierta o un diálogo (SweetAlert).
                if (document.querySelector('.client-card.is-open')) return;
                if (document.querySelector('.swal2-container')) return;

                fetch(BOARD_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (res) { return res.ok ? res.text() : null; })
                    .then(function (html) {
                        if (html === null) return;
                        var board = document.getElementById('board');
                        if (!board) return;
                        board.innerHTML = html;
                        aplicarFiltro(); // mantener el filtro activo tras refrescar
                    })
                    .catch(function () { /* silencio: reintenta en el próximo ciclo */ });
            }

            setInterval(refrescar, INTERVALO);
        })();

        // Conversación en vivo: mientras una tarjeta está abierta, refresca solo su detalle.
        (function () {
            function refrescarDetalle() {
                var abierta = document.querySelector('.client-card.is-open');
                if (!abierta) return;
                if (document.querySelector('.swal2-container')) return;

                var cont = abierta.querySelector('.card-detail');
                if (!cont || !cont.dataset.detail) return;

                fetch('/companies/' + cont.dataset.detail + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (res) { return res.ok ? res.text() : null; })
                    .then(function (html) {
                        if (html === null || !abierta.classList.contains('is-open')) return;
                        var prev = cont.querySelector('.detail-scroll');
                        var top = prev ? prev.scrollTop : 0;
                        cont.innerHTML = html;
                        var nuevo = cont.querySelector('.detail-scroll');
                        if (nuevo) nuevo.scrollTop = top; // conservar la posición de lectura
                    })
                    .catch(function () { /* silencio */ });
            }

            setInterval(refrescarDetalle, 4000);
        })();
    </script>
@endsection
