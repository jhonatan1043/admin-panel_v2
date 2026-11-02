<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sistema de Control · Acceso</title>
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <style>
        body {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            background-color: #050806;
            background-image: radial-gradient(900px 500px at 50% 0%, rgba(16,185,129,.10), transparent 60%);
        }
        .tab { border: 1px solid rgba(100,116,139,.35); color: #94a3b8; }
        .tab.active { border-color: rgba(34,197,94,.7); color: #86efac; box-shadow: 0 0 14px 1px rgba(34,197,94,.25); }
        .field:focus { outline: none; border-color: rgba(34,197,94,.7); box-shadow: 0 0 10px 1px rgba(34,197,94,.2); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center text-slate-100 px-4">
    <div class="w-full max-w-md">
        <h1 class="text-center text-sm tracking-[0.35em] text-emerald-300 mb-6">SISTEMA DE CONTROL</h1>

        <div class="grid grid-cols-2 gap-3 mb-5">
            <button type="button" data-role="operador"
                    class="tab active rounded-lg py-3 text-xs tracking-widest font-semibold">OPERADOR</button>
            <button type="button" data-role="admin"
                    class="tab rounded-lg py-3 text-xs tracking-widest font-semibold">ADMIN</button>
        </div>

        <div class="rounded-xl border border-white/10 bg-black/40 p-6">
            <h2 id="panel-title" class="text-center text-xs tracking-[0.3em] text-emerald-300 mb-5">ACCESO DE OPERADOR</h2>

            @if ($errors->any())
                <div class="mb-4 rounded bg-red-600/15 border border-red-500/50 text-red-200 px-3 py-2 text-xs">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="role" id="role-input" value="operador">

                <input type="text" name="username" value="{{ old('username') }}" placeholder="Usuario" autofocus
                       class="field w-full rounded-lg bg-black/40 border border-slate-600 px-4 py-3 text-sm placeholder-slate-500">

                <input type="password" name="password" placeholder="Contraseña"
                       class="field w-full rounded-lg bg-black/40 border border-slate-600 px-4 py-3 text-sm placeholder-slate-500">

                <button type="submit"
                        class="w-full rounded-lg py-3 text-xs tracking-[0.2em] font-semibold text-emerald-200
                               border border-emerald-500/50 bg-emerald-600/15 hover:bg-emerald-600/25 transition">
                    ENTRAR AL PANEL
                </button>
            </form>
        </div>
    </div>

    <script>
        var roleInput = document.getElementById('role-input');
        var title = document.getElementById('panel-title');
        document.querySelectorAll('.tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                document.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('active'); });
                tab.classList.add('active');
                var role = tab.getAttribute('data-role');
                roleInput.value = role;
                title.textContent = 'ACCESO DE ' + role.toUpperCase();
            });
        });
    </script>
</body>
</html>
