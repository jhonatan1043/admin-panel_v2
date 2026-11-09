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
        $js = 'boton.addEventListener("click", function () { hacer(); });';

        $result = $this->sanitize('<div></div>', $js);

        $this->assertSame($js, $result['js']);
        $this->assertSame([], $result['changes']);
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
