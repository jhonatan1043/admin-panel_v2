<?php

namespace Tests\Unit;

use App\Services\Projects\LoadingJsGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoadingJsGeneratorTest extends TestCase
{
    #[Test]
    public function genera_un_script_que_sondea_el_endpoint_next_del_proyecto(): void
    {
        $js = (new LoadingJsGenerator)->generate(['project' => 'call-center-norte']);

        $this->assertStringContainsString('/api/sitio/', $js);
        $this->assertStringContainsString('/next', $js);
        $this->assertStringContainsString('call-center-norte', $js);
        $this->assertStringContainsString('URLSearchParams', $js); // lee ?s=
        $this->assertStringContainsString('window.location.href', $js); // navega a la siguiente vista
        $this->assertStringNotContainsString('<?php', $js);
    }

    #[Test]
    public function respeta_un_intervalo_minimo_de_sondeo(): void
    {
        $js = (new LoadingJsGenerator)->generate(['project' => 'x', 'interval' => 100]);

        $this->assertStringContainsString('"interval":1500', str_replace(' ', '', $js));
    }
}
