<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_un_usuario_con_el_comando(): void
    {
        $this->artisan('user:create', [
            'name' => 'Ana', 'username' => 'ana', 'email' => 'ana@panel.test', 'password' => 'secret12',
            '--role' => 'admin',
        ])->assertExitCode(0);

        $user = User::where('username', 'ana')->first();
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret12', $user->password));
    }

    public function test_rechaza_rol_invalido(): void
    {
        $this->artisan('user:create', [
            'name' => 'X', 'username' => 'x', 'email' => 'x@panel.test', 'password' => 'secret12',
            '--role' => 'superuser',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['username' => 'x']);
    }
}
