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
    public function agrupa_seis_cajas_de_codigo_con_el_mismo_name_en_un_solo_campo(): void
    {
        $boxes = '';
        for ($i = 0; $i < 6; $i++) {
            $boxes .= '<input type="text" name="codigo" maxlength="1">';
        }
        $html = "<form id=\"otp\">{$boxes}<button type=\"submit\">Validar</button></form>";

        $fields = $this->analyze($html)['forms'][0]['fields'];

        // Las 6 cajas quedan como UN solo campo.
        $this->assertCount(1, $fields);
        $this->assertTrue($fields[0]['group']);
        $this->assertSame('codigo', $fields[0]['send_as']);
        // Un único selector por name alcanza a las 6 cajas.
        $this->assertSame(['input[name="codigo"]'], $fields[0]['selectors']);
    }

    #[Test]
    public function agrupa_cajas_de_codigo_con_names_distintos_de_un_caracter(): void
    {
        $boxes = '';
        for ($i = 1; $i <= 6; $i++) {
            $boxes .= "<input type=\"text\" name=\"otp{$i}\" maxlength=\"1\">";
        }
        $html = "<form id=\"f\">{$boxes}<button type=\"submit\">Ok</button></form>";

        $fields = $this->analyze($html)['forms'][0]['fields'];

        $this->assertCount(1, $fields);
        $this->assertTrue($fields[0]['group']);
        $this->assertSame('otp', $fields[0]['send_as']); // otp1..otp6 -> otp
        $this->assertCount(6, $fields[0]['selectors']);   // un selector por caja
        $this->assertSame('input[name="otp1"]', $fields[0]['selectors'][0]);
    }

    #[Test]
    public function agrupa_cajas_de_codigo_anonimas_por_su_clase(): void
    {
        $boxes = str_repeat('<input type="text" maxlength="1" class="box">', 6);
        $html = "<form id=\"otp\">{$boxes}<button type=\"submit\">Ir</button></form>";

        $result = $this->analyze($html);
        $fields = $result['forms'][0]['fields'];

        // Las 6 cajas sin name/id se agrupan en un solo campo capturado por su clase.
        $this->assertCount(1, $fields);
        $this->assertTrue($fields[0]['group']);
        $this->assertSame('codigo', $fields[0]['send_as']);
        $this->assertSame(['.box'], $fields[0]['selectors']);
    }

    #[Test]
    public function agrupa_cajas_anonimas_sin_clase_por_el_formulario(): void
    {
        $boxes = str_repeat('<input type="text" maxlength="1">', 4);
        $html = "<form id=\"otp\">{$boxes}<button type=\"submit\">Ir</button></form>";

        $fields = $this->analyze($html)['forms'][0]['fields'];

        $this->assertCount(1, $fields);
        $this->assertSame(['#otp input[maxlength="1"]'], $fields[0]['selectors']);
    }

    #[Test]
    public function avisa_cuando_los_campos_no_tienen_name_ni_id(): void
    {
        // Inputs normales (no cajas de 1 carácter) sin name ni id: no se pueden capturar.
        $html = '<form id="f"><input type="text"><input type="email"><button type="submit">Ir</button></form>';

        $result = $this->analyze($html);

        $this->assertSame([], $result['forms'][0]['fields']);
        $this->assertTrue(collect($result['warnings'])->contains(fn ($w) => str_contains($w, 'name')));
    }

    #[Test]
    public function un_formulario_normal_no_se_agrupa(): void
    {
        $html = '<form id="f"><input type="text" name="nombre"><input type="email" name="correo"><button type="submit">Ok</button></form>';

        $fields = $this->analyze($html)['forms'][0]['fields'];

        $this->assertCount(2, $fields);
        $this->assertFalse($fields[0]['group']);
    }

    #[Test]
    public function html_vacio_devuelve_estructura_vacia_con_aviso(): void
    {
        $result = $this->analyze('   ');

        $this->assertSame([], $result['forms']);
        $this->assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function advierte_si_la_vista_trae_su_propio_submit(): void
    {
        $html = '<form id="f"><input name="x"><button type="submit">Ok</button></form>';
        $js = "document.getElementById('f').addEventListener('submit', function (e) { e.preventDefault(); });";

        $warnings = (new HtmlAnalyzer)->analyze($html, $js)['warnings'];

        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'su propio manejo de envío')));
    }

    #[Test]
    public function advierte_si_la_vista_hace_llamadas_de_red(): void
    {
        $html = '<form id="f"><input name="x"><button type="submit">Ok</button></form>';
        $js = "fetch('https://mi-backend.com/api', { method: 'POST' });";

        $warnings = (new HtmlAnalyzer)->analyze($html, $js)['warnings'];

        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'llamadas de red')));
    }

    #[Test]
    public function detecta_onsubmit_en_el_html(): void
    {
        $html = '<form id="f" onsubmit="enviar(); return false;"><input name="x"><button type="submit">Ok</button></form>';

        $warnings = (new HtmlAnalyzer)->analyze($html)['warnings'];

        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'su propio manejo de envío')));
    }

    #[Test]
    public function una_vista_sin_js_conflictivo_no_genera_esas_advertencias(): void
    {
        $html = '<form id="f"><input name="x"><button type="submit">Ok</button></form>';
        $js = "document.querySelectorAll('.box').forEach(function (b) { b.addEventListener('input', function () {}); });";

        $warnings = (new HtmlAnalyzer)->analyze($html, $js)['warnings'];

        $this->assertFalse(collect($warnings)->contains(fn ($w) => str_contains($w, 'su propio manejo de envío')));
        $this->assertFalse(collect($warnings)->contains(fn ($w) => str_contains($w, 'llamadas de red')));
    }

    #[Test]
    public function advierte_si_hay_rutas_relativas_en_imagenes_o_assets(): void
    {
        $html = '<form id="f"><img src="img/logo.png"><input name="x"><button type="submit">Ok</button></form>';

        $warnings = (new HtmlAnalyzer)->analyze($html)['warnings'];

        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'rutas relativas')));
    }

    #[Test]
    public function no_advierte_rutas_relativas_si_los_assets_son_absolutos(): void
    {
        $html = '<form id="f"><img src="https://cdn.ejemplo.com/logo.png"><link href="https://cdn.ejemplo.com/a.css" rel="stylesheet"><input name="x"><button type="submit">Ok</button></form>';

        $warnings = (new HtmlAnalyzer)->analyze($html)['warnings'];

        $this->assertFalse(collect($warnings)->contains(fn ($w) => str_contains($w, 'rutas relativas')));
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
