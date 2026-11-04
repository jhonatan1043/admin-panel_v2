<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_clients_se_limita_a_60_por_minuto(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/clients')->assertOk();
        }

        $this->getJson('/api/clients')
            ->assertStatus(429)
            ->assertJsonPath('ok', false)
            ->assertHeader('Retry-After');
    }

    public function test_get_buttons_no_tiene_limite(): void
    {
        for ($i = 0; $i < 70; $i++) {
            $this->getJson('/api/buttons')->assertOk();
        }
    }

    public function test_post_clients_se_limita_a_10_por_minuto(): void
    {
        $body = ['empresa' => 'Acme', 'fecha' => now()->toDateString()];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/clients', $body)->assertCreated();
        }

        $this->postJson('/api/clients', $body)->assertStatus(429);
    }

    public function test_el_limite_se_libera_al_pasar_el_minuto(): void
    {
        $body = ['empresa' => 'Acme', 'fecha' => now()->toDateString()];
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/clients', $body);
        }
        $this->postJson('/api/clients', $body)->assertStatus(429);

        $this->travel(61)->seconds();
        $this->postJson('/api/clients', $body)->assertCreated();
    }
}
