<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'role' => ['required', 'in:operador,admin'],
        ]);

        // El rol (tab) también debe coincidir con la cuenta.
        $ok = Auth::attempt([
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'role' => $credentials['role'],
        ], $request->boolean('remember'));

        if (! $ok) {
            throw ValidationException::withMessages([
                'username' => 'Credenciales o acceso incorrecto.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('companies.index'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
