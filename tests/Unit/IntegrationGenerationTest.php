<?php

namespace Tests\Unit;

use App\Services\Projects\HtmlAnalyzer;
use App\Services\Projects\IntegrationJsGenerator;
use App\Services\Projects\IntegrationSpecBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationGenerationTest extends TestCase
{
    private function spec(string $html, string $endpoint = '/api/projects/demo/submit'): array
    {
        $analysis = (new HtmlAnalyzer)->analyze($html);

        return (new IntegrationSpecBuilder)->fromAnalysis($analysis, $endpoint);
    }

    #[Test]
    public function construye_un_target_por_formulario_con_sus_campos(): void
    {
        $html = <<<'HTML'
        <form id="formContactos">
            <input type="text" name="nombre">
            <input type="email" name="correo">
            <input type="text" name="telefono">
            <button type="submit">Guardar</button>
        </form>
        HTML;

        $spec = $this->spec($html);

        $this->assertCount(1, $spec['targets']);
        $target = $spec['targets'][0];
        $this->assertSame('formContactos', $target['key']);
        $this->assertSame('map', $target['mode']);
        $this->assertSame('submit', $target['trigger']['type']);
        $this->assertSame('#formContactos', $target['trigger']['selector']);
        $this->assertSame(['nombre', 'correo', 'telefono'], array_column($target['fields'], 'send_as'));
    }

    #[Test]
    public function un_solo_campo_suelto_usa_modo_single_y_dispara_por_click(): void
    {
        $html = '<input type="text" id="numeroSolicitud" name="numeroSolicitud"><button id="btnEnviar">Continuar</button>';

        $target = $this->spec($html)['targets'][0];

        $this->assertSame('single', $target['mode']);
        $this->assertSame('click', $target['trigger']['type']);
        $this->assertSame('#btnEnviar', $target['trigger']['selector']);
    }

    #[Test]
    public function varios_formularios_generan_targets_independientes(): void
    {
        $html = <<<'HTML'
        <form id="formA"><input type="text" name="a1"><button type="submit">A</button></form>
        <form id="formB"><input type="text" name="b1"><button type="submit">B</button></form>
        HTML;

        $spec = $this->spec($html);

        $this->assertCount(2, $spec['targets']);
        $this->assertSame('formA', $spec['targets'][0]['key']);
        $this->assertSame('formB', $spec['targets'][1]['key']);
    }

    #[Test]
    public function campos_excluidos_no_entran_en_el_spec(): void
    {
        $analysis = (new HtmlAnalyzer)->analyze('<form id="f"><input type="text" name="a"><input type="text" name="b"><button type="submit">Ok</button></form>');
        $analysis['forms'][0]['fields'][1]['include'] = false; // la UI excluye "b"

        $spec = (new IntegrationSpecBuilder)->fromAnalysis($analysis, '/x');

        $this->assertSame(['a'], array_column($spec['targets'][0]['fields'], 'send_as'));
    }

    #[Test]
    public function el_javascript_generado_incluye_endpoint_y_control_de_duplicados(): void
    {
        $spec = $this->spec('<form id="f"><input type="text" name="x"><button type="submit">Ok</button></form>', '/api/projects/demo/submit');

        $js = (new IntegrationJsGenerator)->generate($spec);

        $this->assertStringContainsString('/api/projects/demo/submit', $js);
        $this->assertStringContainsString('data-integration-busy', $js); // evita duplicados
        $this->assertStringContainsString('X-CSRF-TOKEN', $js);
        $this->assertStringContainsString('addEventListener', $js);
        $this->assertStringContainsString('preventDefault', $js); // no rompe, pero evita submit nativo
        // No debe quedar ningún marcador PHP sin resolver.
        $this->assertStringNotContainsString('<?php', $js);
    }

    #[Test]
    public function el_javascript_serializa_los_selectores_de_los_campos(): void
    {
        $spec = $this->spec('<form id="formContactos"><input type="text" name="nombre"><button type="submit">Ok</button></form>');

        $js = (new IntegrationJsGenerator)->generate($spec);

        $this->assertStringContainsString('#formContactos', $js);
        $this->assertStringContainsString('"send_as": "nombre"', $js);
    }

    #[Test]
    public function sin_targets_el_javascript_no_falla(): void
    {
        $js = (new IntegrationJsGenerator)->generate(['endpoint' => '/x', 'targets' => []]);

        $this->assertStringContainsString('CONFIG.targets.length', $js);
    }
}
