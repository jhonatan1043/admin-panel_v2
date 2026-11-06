<?php

namespace Tests\Unit;

use App\Services\Projects\HtmlAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HtmlAnalyzerTest extends TestCase
{
    private function analyze(string $html): array
    {
        return (new HtmlAnalyzer)->analyze($html);
    }

    #[Test]
    public function detecta_un_formulario_con_tres_campos(): void
    {
        $html = <<<'HTML'
        <form id="formContactos">
            <input type="text" name="nombre">
            <input type="email" name="correo">
            <input type="text" name="telefono">
            <button type="submit">Guardar</button>
        </form>
        HTML;

        $result = $this->analyze($html);

        $this->assertCount(1, $result['forms']);
        $form = $result['forms'][0];
        $this->assertSame('formContactos', $form['id']);
        $this->assertSame('#formContactos', $form['selector']);
        $this->assertCount(3, $form['fields']);
        $this->assertSame(['nombre', 'correo', 'telefono'], array_column($form['fields'], 'key'));
        $this->assertSame('email', $form['fields'][1]['type']);
        $this->assertNotNull($form['submit']);
        $this->assertSame('Guardar', $form['submit']['text']);
        $this->assertTrue($form['submit']['is_submit']);
        $this->assertSame(1, $result['summary']['forms']);
        $this->assertSame(3, $result['summary']['fields']);
    }

    #[Test]
    public function un_solo_campo_suelto_sin_formulario_envia_solo_el_valor(): void
    {
        $html = <<<'HTML'
        <input type="text" id="numeroSolicitud" name="numeroSolicitud">
        <button id="btnEnviar">Continuar</button>
        HTML;

        $result = $this->analyze($html);

        $this->assertCount(0, $result['forms']);
        $this->assertCount(1, $result['standalone']['fields']);
        $this->assertCount(1, $result['standalone']['buttons']);

        $field = $result['standalone']['fields'][0];
        $this->assertSame('numeroSolicitud', $field['key']);
        $this->assertSame('#numeroSolicitud', $field['selector']);

        $button = $result['standalone']['buttons'][0];
        $this->assertSame('btnEnviar', $button['id']);
        $this->assertSame('#btnEnviar', $button['selector']);
        $this->assertSame('Continuar', $button['text']);
    }

    #[Test]
    public function varios_formularios_se_detectan_por_separado(): void
    {
        $html = <<<'HTML'
        <form id="formA">
            <input type="text" name="a1">
            <button type="submit">A</button>
        </form>
        <form id="formB">
            <input type="text" name="b1">
            <input type="text" name="b2">
            <button type="submit">B</button>
        </form>
        HTML;

        $result = $this->analyze($html);

        $this->assertCount(2, $result['forms']);
        $this->assertSame('formA', $result['forms'][0]['id']);
        $this->assertSame('formB', $result['forms'][1]['id']);
        $this->assertCount(1, $result['forms'][0]['fields']);
        $this->assertCount(2, $result['forms'][1]['fields']);
        // No se mezclan los campos entre formularios.
        $this->assertSame(['a1'], array_column($result['forms'][0]['fields'], 'key'));
        $this->assertSame(['b1', 'b2'], array_column($result['forms'][1]['fields'], 'key'));
    }

    #[Test]
    public function detecta_campos_obligatorios_ocultos_textarea_y_select(): void
    {
        $html = <<<'HTML'
        <form id="f">
            <input type="text" name="nombre" required>
            <input type="hidden" name="origen" value="web">
            <textarea name="mensaje"></textarea>
            <select name="plan"><option>Básico</option></select>
            <input type="submit" value="Enviar">
        </form>
        HTML;

        $fields = $this->analyze($html)['forms'][0]['fields'];

        $this->assertSame(['nombre', 'origen', 'mensaje', 'plan'], array_column($fields, 'key'));
        $this->assertTrue($fields[0]['required']);
        $this->assertTrue($fields[1]['hidden']);
        $this->assertSame('textarea', $fields[2]['type']);
        $this->assertSame('select', $fields[3]['type']);
        $this->assertSame('Enviar', $this->analyze($html)['forms'][0]['submit']['text']);
    }

    #[Test]
    public function ignora_botones_como_campos_de_datos(): void
    {
        $html = '<form id="f"><input type="text" name="x"><input type="reset" value="Limpiar"><button type="submit">Ok</button></form>';

        $fields = $this->analyze($html)['forms'][0]['fields'];

        $this->assertSame(['x'], array_column($fields, 'key'));
    }

    #[Test]
    public function usa_el_name_sobre_el_id_como_clave_de_envio(): void
    {
        $html = '<form id="f"><input type="text" id="campoNombre" name="nombre"></form>';

        $field = $this->analyze($html)['forms'][0]['fields'][0];

        $this->assertSame('nombre', $field['key']);
        $this->assertSame('nombre', $field['send_as']);
        $this->assertSame('#campoNombre', $field['selector']);
    }

    #[Test]
    public function html_vacio_devuelve_estructura_vacia_con_aviso(): void
    {
        $result = $this->analyze('   ');

        $this->assertSame([], $result['forms']);
        $this->assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function conserva_acentos_y_utf8(): void
    {
        $html = '<form id="f"><input type="text" name="nombre" placeholder="Añadir nombre"><button type="submit">Guardár</button></form>';

        $result = $this->analyze($html);

        $this->assertSame('Añadir nombre', $result['forms'][0]['fields'][0]['placeholder']);
        $this->assertSame('Guardár', $result['forms'][0]['submit']['text']);
    }
}
