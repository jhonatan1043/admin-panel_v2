<?php

namespace Tests\Feature;

use App\Models\Button;
use App\Models\Company;
use App\Models\MessageTemplate;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CachingTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_catalogo_de_botones_se_cachea(): void
    {
        Button::create(['label' => 'A', 'text' => 'a']);
        $this->getJson('/api/buttons')->assertOk(); // llena la caché

        DB::enableQueryLog();
        $this->getJson('/api/buttons')->assertOk()->assertJsonCount(1, 'data');
        $consultas = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"buttons"') || str_contains($q['query'], '`buttons`'));

        $this->assertCount(0, $consultas, 'La segunda lectura no debe consultar la tabla buttons.');
    }

    public function test_crear_editar_y_borrar_un_boton_invalida_la_cache(): void
    {
        $b = Button::create(['label' => 'A', 'text' => 'a']);
        $this->getJson('/api/buttons')->assertJsonCount(1, 'data');

        Button::create(['label' => 'B', 'text' => 'b']);
        $this->getJson('/api/buttons')->assertJsonCount(2, 'data');

        $b->update(['label' => 'A2']);
        $this->getJson('/api/buttons')->assertJsonPath('data.0.nombre', 'A2');

        $b->delete();
        $this->getJson('/api/buttons')->assertJsonCount(1, 'data');
    }

    public function test_las_configuraciones_se_cachean_y_se_invalidan(): void
    {
        Setting::put('telegram_chat_id', '-1001');
        $this->assertSame('-1001', Setting::get('telegram_chat_id'));
        $this->assertTrue(Cache::has(Setting::CACHE_KEY));

        Setting::put('telegram_chat_id', '-1002');
        $this->assertSame('-1002', Setting::get('telegram_chat_id'));
    }

    public function test_la_lista_de_clientes_se_invalida_al_cambiar_un_cliente(): void
    {
        $c = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        $this->getJson('/api/clients')->assertJsonCount(1, 'data');
        $version = Company::apiListVersion();

        // Nuevo cliente por la API: aparece de inmediato.
        $this->postJson('/api/clients', ['empresa' => 'Beta', 'fecha' => now()->toDateString()])->assertCreated();
        $this->getJson('/api/clients')->assertJsonCount(2, 'data');
        $this->assertNotSame($version, Company::apiListVersion());

        // Cambio de un cliente existente.
        $c->update(['name' => 'Juan']);
        $this->getJson('/api/clients')->assertJsonFragment(['name' => 'Juan']);

        // Una plantilla nueva también se refleja.
        MessageTemplate::create(['company_id' => $c->id, 'label' => 'Hola', 'body' => 'Hola!', 'is_active' => true]);
        $this->getJson('/api/clients')->assertJsonFragment(['label' => 'Hola']);
    }

    /**
     * Con un store que serializa (file, como database/redis en producción) y
     * serializable_classes = false, la caché no puede contener objetos.
     * La caché "array" de las pruebas no serializa y no detectaba este error.
     */
    public function test_la_cache_funciona_con_un_store_que_serializa(): void
    {
        config(['cache.default' => 'file', 'cache.serializable_classes' => false]);
        Cache::flush();

        Button::create(['label' => 'OTP', 'text' => 'Código', 'expires_seconds' => 50, 'is_active' => true]);
        $c = Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now(), 'data' => ['a' => 1]]);
        MessageTemplate::create(['company_id' => $c->id, 'label' => 'Hola', 'body' => 'Hola!', 'is_active' => true]);

        // Primera lectura llena la caché; la segunda la lee deserializada.
        foreach ([1, 2] as $_) {
            $catalog = Button::catalog();
            $this->assertInstanceOf(Button::class, $catalog->first());
            $this->assertSame(50, $catalog->first()->expires_seconds);
            $this->assertTrue($catalog->first()->is_active);
            $this->assertSame([ 'OTP' => 50 ], Button::expiryByLabel()->all());

            $this->getJson('/api/clients')
                ->assertOk()
                ->assertJsonPath('data.0.registro', 'REG-0001')
                ->assertJsonPath('data.0.data.a', 1)
                ->assertJsonPath('data.0.buttons.0.label', 'Hola');
            $this->getJson('/api/buttons')->assertOk()->assertJsonPath('data.0.vence_segundos', 50);
        }

        Cache::flush();
    }

    public function test_una_entrada_corrupta_del_catalogo_se_reconstruye(): void
    {
        Button::create(['label' => 'A', 'text' => 'a']);
        Cache::forever(Button::CACHE_KEY, 'basura');

        $this->assertCount(1, Button::catalog());
    }

    public function test_el_estado_por_tiempo_no_queda_viejo_en_la_cache(): void
    {
        Company::create(['registro' => 'REG-0001', 'empresa' => 'Acme', 'fecha' => now(), 'last_activity_at' => now()]);
        $this->getJson('/api/clients')->assertJsonPath('data.0.status', 'active');

        // Pasa el tiempo sin cambios en la base: la caché sigue, pero el estado se recalcula.
        $this->travel(15)->minutes();
        $this->getJson('/api/clients')->assertJsonPath('data.0.status', 'pending');
    }
}
