<?php

namespace Tests\Unit;

use App\Services\Projects\ViewCodeSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ViewCodeSanitizerTest extends TestCase
{
    private function sanitize(string $html, ?string $js = null): array
    {
        return (new ViewCodeSanitizer)->sanitize($html, $js);
    }

    #[Test]
    public function desactiva_el_submit_propio_pero_conserva_el_resto_del_js(): void
    {
        $js = <<<'JS'
        function mostrarError(){ mostrarMensaje("fallo"); }
        document.getElementById("otp").addEventListener("submit", function (e) { e.preventDefault(); mostrarError(); });
        document.querySelectorAll(".box").forEach(function (b) { b.addEventListener("input", function () {}); });
        JS;

        $result = $this->sanitize('<form id="otp"></form>', $js);

        // El manejador de submit ya no llama a mostrarError.
        $this->assertStringNotContainsString('mostrarError();', $result['js']);
        $this->assertStringContainsString('submit desactivado por el panel', $result['js']);
        // Conserva el resto del código (función y el listener de input visual).
        $this->assertStringContainsString('function mostrarError()', $result['js']);
        $this->assertStringContainsString('addEventListener("input"', $result['js']);
        $this->assertNotEmpty($result['changes']);
    }

    #[Test]
    public function quita_el_onsubmit_del_html(): void
    {
        $result = $this->sanitize('<form id="f" onsubmit="enviar(); return false;"><input name="x"></form>');

        $this->assertStringNotContainsString('onsubmit', $result['html']);
        $this->assertStringContainsString('<form id="f"', $result['html']);
    }

    #[Test]
    public function no_toca_un_addeventlistener_que_no_sea_submit(): void
    {
        $js = 'function hacer(){} boton.addEventListener("click", function () { hacer(); });';

        $result = $this->sanitize('<div></div>', $js);

        $this->assertSame($js, $result['js']);
        $this->assertSame([], $result['changes']);
    }

    #[Test]
    public function define_como_vacia_una_funcion_que_la_vista_usa_pero_no_define(): void
    {
        // El manejador de input llama a mostrarMensaje, que no existe (caso real).
        $js = 'box.addEventListener("input", function () { mostrarMensaje("hola"); });';

        $result = $this->sanitize('<input class="box">', $js);

        $this->assertStringContainsString("typeof mostrarMensaje === 'undefined'", $result['js']);
        $this->assertStringContainsString('window.mostrarMensaje = function', $result['js']);
        $this->assertTrue(collect($result['changes'])->contains(fn ($c) => str_contains($c, 'mostrarMensaje')));
    }

    #[Test]
    public function no_define_funciones_si_la_vista_carga_librerias_externas(): void
    {
        $html = '<script src="https://cdn/jquery.js"></script><input class="box">';
        $js = 'box.addEventListener("input", function () { algoDeLaLibreria(); });';

        $result = $this->sanitize($html, $js);

        $this->assertStringNotContainsString('window.algoDeLaLibreria', $result['js']);
    }

    #[Test]
    public function corregir_dos_veces_no_vuelve_a_cambiar_nada(): void
    {
        $html = '<form id="f" onsubmit="x()"></form>';
        $js = 'f.addEventListener("submit", function (e) { e.preventDefault(); mostrarError(); }); function mostrarError(){ mostrarMensaje("x"); }';

        $first = $this->sanitize($html, $js);
        $this->assertNotEmpty($first['changes']);

        // Segunda pasada sobre el resultado: ya no hay nada que corregir.
        $second = $this->sanitize($first['html'], $first['js']);
        $this->assertSame([], $second['changes']);
    }

    #[Test]
    public function maneja_parentesis_y_cadenas_dentro_del_manejador(): void
    {
        $js = 'f.addEventListener("submit", function (e) { e.preventDefault(); foo("a(b)c", bar()); });';

        $result = $this->sanitize('<form></form>', $js);

        // Debe neutralizar toda la llamada sin cortarla a la mitad.
        $this->assertStringNotContainsString('foo("a(b)c"', $result['js']);
        $this->assertStringContainsString('submit desactivado por el panel', $result['js']);
        // El resultado sigue siendo JS equilibrado (mismos paréntesis de apertura/cierre).
        $this->assertSame(substr_count($result['js'], '('), substr_count($result['js'], ')'));
    }

    #[Test]
    public function sin_conflicto_no_cambia_nada(): void
    {
        $result = $this->sanitize('<form><input name="x"></form>', 'console.log("hola");');

        $this->assertSame([], $result['changes']);
    }
}
