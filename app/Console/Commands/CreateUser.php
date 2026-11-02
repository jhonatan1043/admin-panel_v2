<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateUser extends Command
{
    protected $signature = 'user:create {name} {username} {email} {password} {--role=operador}';

    protected $description = 'Crea un usuario del panel (operador o admin).';

    public function handle(): int
    {
        $data = [
            'name' => $this->argument('name'),
            'username' => $this->argument('username'),
            'email' => $this->argument('email'),
            'password' => $this->argument('password'),
            'role' => $this->option('role'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:operador,admin'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create($data); // la contraseña se hashea por el cast del modelo

        $this->info("Usuario '{$data['username']}' ({$data['role']}) creado.");

        return self::SUCCESS;
    }
}
