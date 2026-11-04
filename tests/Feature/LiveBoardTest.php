<?php

namespace Tests\Feature;

use App\Models\Button;
use App\Models\ClientLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_endpoint_del_tablero_requiere_sesion(): void
    {
        $this->get('/companies/board')->assertRedirect('/login');
    }

    public function test_el_endpoint_del_tablero_lista_los_clientes_actuales(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('REG-0001');
    }

    public function test_un_cliente_nuevo_aparece_en_el_siguiente_refresco(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertDontSee('REG-0001');

        Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertSee('REG-0001');
    }

    public function test_el_detalle_muestra_la_conversacion_del_cliente(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => 'solicitud', 'status' => 'received']);

        $this->actingAs($admin)->get("/companies/{$company->id}/detail")
            ->assertOk()
            ->assertSee('Cliente')
            ->assertSee('solicitud');
    }

    public function test_el_operador_no_ve_el_detalle_de_clientes_ajenos(): void
    {
        $operador = User::factory()->create(['role' => 'operador']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($operador)->get("/companies/{$company->id}/detail")
            ->assertForbidden();
    }

    public function test_exporta_la_conversacion_en_txt(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => 'hola', 'status' => 'received']);

        $res = $this->actingAs($admin)->get("/companies/{$company->id}/detail/export")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="conversacion-REG-0001.txt"');

        $this->assertStringContainsString('Cliente: hola', $res->getContent());
    }

    public function test_el_operador_no_exporta_conversacion_ajena(): void
    {
        $operador = User::factory()->create(['role' => 'operador']);
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);

        $this->actingAs($operador)->get("/companies/{$company->id}/detail/export")
            ->assertForbidden();
    }

    public function test_el_tablero_marca_el_vencimiento_cuando_llega_el_valor(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Button::create(['label' => 'OTP', 'text' => 'Código', 'expires_seconds' => 50]);

        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        // Se envió la opción con vencimiento y luego llegó el valor del cliente.
        $out = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'OTP', 'text' => 'Código', 'status' => 'sent']);
        $out->forceFill(['created_at' => now()->subSeconds(10)])->save();
        ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => '123456', 'status' => 'received']);

        // El tablero expone data-alarm-at para que el front dispare la alarma.
        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertSee('data-alarm-at', false);
    }

    public function test_la_respuesta_a_una_opcion_con_cronometro_sale_en_morado(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Button::create(['label' => 'OTP', 'text' => 'Código', 'expires_seconds' => 50]);

        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        $antes = ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => 'hola', 'status' => 'received']);
        $antes->forceFill(['created_at' => now()->subSeconds(30)])->save();
        $out = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'OTP', 'text' => 'Código', 'status' => 'sent']);
        $out->forceFill(['created_at' => now()->subSeconds(10)])->save();
        ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => '123456', 'status' => 'received']);

        $html = $this->actingAs($admin)->get("/companies/{$company->id}/detail")->assertOk()->getContent();

        // Solo la respuesta posterior a la opción con cronómetro va en morado.
        $this->assertSame(1, substr_count($html, 'timed-reply'));
        $this->assertMatchesRegularExpression('/timed-reply[^>]*>123456</', $html);
    }

    public function test_el_codigo_de_prueba_tras_un_boton_con_cronometro_lo_activa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Button::create(['label' => '⭐️OTP', 'text' => 'OTP', 'expires_seconds' => 50]);

        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        // Así llega en la vida real: el botón y luego el código por send-test-code.
        $out = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => '⭐️OTP', 'text' => 'OTP', 'status' => 'sent']);
        $out->forceFill(['created_at' => now()->subSeconds(10)])->save();
        ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => ClientLog::TEST_CODE_LABEL, 'text' => '545787', 'status' => 'sent']);

        $html = $this->actingAs($admin)->get('/companies/board')->assertOk()->getContent();
        $this->assertStringContainsString('data-alarm-at', $html);
        $this->assertMatchesRegularExpression('/timed-reply[^>]*>545787</', $html);
    }

    public function test_si_el_cliente_no_esta_en_linea_no_hay_cronometro_ni_morado(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Button::create(['label' => '⭐️OTP', 'text' => 'OTP', 'expires_seconds' => 50]);

        // Sin actividad hace 15 min: PENDIENTE (no EN LÍNEA).
        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()->subMinutes(15)]);
        $out = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => '⭐️OTP', 'text' => 'OTP', 'status' => 'sent']);
        $out->forceFill(['created_at' => now()->subSeconds(10)])->save();
        ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => ClientLog::TEST_CODE_LABEL, 'text' => '545787', 'status' => 'sent']);

        $board = $this->actingAs($admin)->get('/companies/board')->assertOk()->getContent();
        $this->assertStringContainsString('data-status="pending"', $board);
        $this->assertStringNotContainsString('data-alarm-at', $board);
        $this->assertStringNotContainsString('timed-reply', $board);

        $detail = $this->actingAs($admin)->get("/companies/{$company->id}/detail")->assertOk()->getContent();
        $this->assertStringNotContainsString('data-alarm-at', $detail);
        $this->assertStringNotContainsString('timed-reply', $detail);
    }

    public function test_un_valor_no_numerico_no_activa_el_cronometro(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Button::create(['label' => 'Código', 'text' => 'Envíe su código', 'expires_seconds' => 50]);

        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        $out = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'Código', 'text' => 'Envíe su código', 'status' => 'sent']);
        $out->forceFill(['created_at' => now()->subSeconds(10)])->save();
        ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => 'no me llegó', 'status' => 'received']);

        $html = $this->actingAs($admin)->get('/companies/board')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-alarm-at', $html);
        $this->assertStringNotContainsString('timed-reply', $html);
    }

    public function test_el_cronometro_solo_cuenta_si_lo_ultimo_enviado_tiene_cronometro(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Button::create(['label' => 'Código', 'text' => 'Envíe su código', 'expires_seconds' => 50]);
        Button::create(['label' => 'Info', 'text' => 'Dato']); // sin cronómetro

        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        $a = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'Código', 'text' => 'Envíe su código', 'status' => 'sent']);
        $a->forceFill(['created_at' => now()->subSeconds(20)])->save();
        $b = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'Info', 'text' => 'Dato', 'status' => 'sent']);
        $b->forceFill(['created_at' => now()->subSeconds(10)])->save();
        ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => '123456', 'status' => 'received']);

        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertDontSee('data-alarm-at', false);
    }

    public function test_un_codigo_con_espacios_cuenta_como_numerico(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Button::create(['label' => 'Código', 'text' => 'Envíe su código', 'expires_seconds' => 50]);

        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        $out = ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'Código', 'text' => 'Envíe su código', 'status' => 'sent']);
        $out->forceFill(['created_at' => now()->subSeconds(10)])->save();
        ClientLog::create(['company_id' => $company->id, 'direction' => 'in', 'text' => '123 456', 'status' => 'received']);

        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertSee('data-alarm-at', false);
    }

    public function test_el_tablero_no_marca_vencimiento_sin_valor_recibido(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Button::create(['label' => 'OTP', 'text' => 'Código', 'expires_seconds' => 50]);

        $company = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        // Solo se envió la opción; el cliente aún no responde: no hay vencimiento activo.
        ClientLog::create(['company_id' => $company->id, 'direction' => 'out', 'label' => 'OTP', 'text' => 'Código', 'status' => 'sent']);

        $this->actingAs($admin)->get('/companies/board')
            ->assertOk()
            ->assertDontSee('data-alarm-at', false);
    }
}
