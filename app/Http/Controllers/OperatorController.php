<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperatorController extends Controller
{
    public function index()
    {
        $operators = User::where('role', 'operador')
            ->withCount('clients')
            ->latest()
            ->get();

        return view('operators.index', compact('operators'));
    }

    public function create()
    {
        return view('operators.create', ['operator' => new User()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
        ]);

        $data['role'] = 'operador';
        User::create($data); // la contraseña se hashea por el cast del modelo

        return redirect()->route('operators.index')->with('status', 'Operador creado.');
    }

    public function edit(User $operator)
    {
        abort_unless($operator->role === 'operador', 404);

        return view('operators.edit', compact('operator'));
    }

    public function update(Request $request, User $operator)
    {
        abort_unless($operator->role === 'operador', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($operator->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($operator->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $operator->update($data);

        return redirect()->route('operators.index')->with('status', 'Operador actualizado.');
    }

    public function destroy(User $operator)
    {
        abort_unless($operator->role === 'operador', 404);

        $operator->delete(); // sus clientes quedan sin asignar (operator_id = null)

        return redirect()->route('operators.index')->with('status', 'Operador eliminado.');
    }
}
