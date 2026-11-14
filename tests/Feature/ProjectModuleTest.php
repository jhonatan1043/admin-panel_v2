<?php

namespace Tests\Feature;

use App\Models\Button;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProjectModuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function contactForm(): string
    {
        return <<<'HTML'
        <!doctype html><html><head><title>Contacto</title></head><body>
        <form id="formContactos">
            <input type="text" name="nombre" required>
            <input type="email" name="correo">
            <input type="text" name="telefono">
            <button type="submit">Guardar</button>
        </form>
        </body></html>
        HTML;
    }

    private function makeIntegratedProject(): array
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), [
            'name' => 'Call Center Norte',
            'description' => 'Vistas del norte',
        ])->assertRedirect();

        $project = Project::firstWhere('name', 'Call Center Norte');

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Registrar contacto',
            'html' => $this->contactForm(),
        ])->assertRedirect();

        return [$admin, $project->fresh('views')];
    }

    public function test_crear_proyecto_genera_slug_y_queda_en_borrador(): void
    {
        [, $project] = $this->makeIntegratedProject();

        $this->assertSame('call-center-norte', $project->slug);
        $this->assertSame(Project::STATUS_DRAFT, $project->status);
    }

    public function test_cargar_una_vista_analiza_y_genera_la_integracion_sin_tocar_el_html(): void
    {
        [, $project] = $this->makeIntegratedProject();
        $view = $project->views->first();

        // El HTML original se conserva íntegro.
        $this->assertSame($this->contactForm(), $view->html);
        // Se detectaron los tres campos.
        $this->assertSame(3, $view->analysis['summary']['fields']);
        // Se generó integration.js con el endpoint correcto.
        $this->assertTrue($view->isIntegrated());
        $this->assertStringContainsString("/api/sitio/call-center-norte/{$view->slug}/submit", $view->integration_js);
    }

    public function test_no_publica_un_proyecto_sin_vistas(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Vacío'])->assertRedirect();
        $project = Project::firstWhere('name', 'Vacío');

        $this->actingAs($admin)->post(route('projects.publish', $project))->assertSessionHasErrors('publish');
        $this->assertSame(Project::STATUS_DRAFT, $project->fresh()->status);
    }

    public function test_publica_un_proyecto_con_vista_integrada(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();

        $this->actingAs($admin)->post(route('projects.publish', $project))->assertRedirect();

        $this->assertSame(Project::STATUS_PUBLISHED, $project->fresh()->status);
        $this->assertNotNull($project->fresh()->published_at);
    }

    public function test_sirve_la_vista_publicada_conservando_el_html_e_inyectando_integracion(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $this->actingAs($admin)->post(route('projects.publish', $project));
        $view = $project->views->first();

        $html = $this->get(route('sitio.serve', [$project, $view->slug]))->assertOk()->getContent();

        $this->assertStringContainsString('<form id="formContactos">', $html); // original intacto
        $this->assertStringContainsString('data-project-view="integration"', $html); // integración añadida
        $this->assertStringContainsString('<meta name="csrf-token"', $html);
    }

    public function test_al_subir_una_pagina_separa_html_css_y_js(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Pagina']);
        $project = Project::firstWhere('name', 'Pagina');

        $pagina = '<style>.x{color:red}</style><form id="f"><input name="a"><button type="submit">Ir</button></form>'
            .'<script src="https://cdn/lib.js"></script><script>console.log("mio");</script>';

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Home', 'html' => $pagina,
        ])->assertRedirect();

        $view = $project->views()->first();
        $this->assertStringContainsString('.x{color:red}', (string) $view->css);
        $this->assertStringContainsString('console.log("mio")', (string) $view->js);
        // El <style> y el <script> en línea se sacaron del HTML; el <script src> queda.
        $this->assertStringNotContainsString('<style>', (string) $view->html);
        $this->assertStringNotContainsString('console.log', (string) $view->html);
        $this->assertStringContainsString('cdn/lib.js', (string) $view->html);
    }

    public function test_la_presencia_crea_un_visitante_en_linea_y_luego_lo_refresca(): void
    {
        [, $project] = $this->makeIntegratedProject();

        // Primer latido: sin sesión -> crea el visitante y devuelve su token.
        $res = $this->postJson("/api/sitio/{$project->slug}/presence", [])->assertOk();
        $token = $res->json('session');
        $this->assertNotNull($token);

        $company = Company::firstWhere('loading_token', $token);
        $this->assertTrue($company->isOnline());
        $this->assertTrue((bool) ($company->data['_visitante'] ?? false));
        $this->assertSame(1, Company::count());

        // Segundo latido con el mismo token: no crea otro, solo refresca.
        $this->postJson("/api/sitio/{$project->slug}/presence", ['session' => $token])
            ->assertOk()->assertJson(['session' => $token]);
        $this->assertSame(1, Company::count());
    }

    public function test_un_cliente_sin_latido_reciente_no_esta_en_linea(): void
    {
        $viejo = Company::create([
            'empresa' => 'X', 'registro' => 'REG-1', 'fecha' => now(),
            'last_activity_at' => now(), 'last_seen_at' => now()->subMinutes(5),
        ]);
        $this->assertFalse($viejo->isOnline());
    }

    public function test_el_bundle_index_js_lleva_todas_las_vistas_y_la_principal(): void
    {
        [, $project] = $this->makeIntegratedProject(); // vista "Registrar contacto"
        $view = $project->views->first();

        $res = $this->get("/embed/{$project->slug}/bundle")->assertOk();
        $js = $res->getContent();

        // El alias histórico terminado en .js sigue sirviendo el mismo bundle.
        $this->get("/embed/{$project->slug}/index.js")->assertOk();

        $this->assertStringContainsString('application/javascript', $res->headers->get('Content-Type'));
        // Lleva dentro el HTML de la vista y su slug como principal.
        $this->assertStringContainsString('formContactos', $js);
        $this->assertStringContainsString('"primary":"'.$view->slug.'"', str_replace(' ', '', $js));
        // Y la lógica de dibujo/envío (incluida la ejecución de <script> embebidos
        // y el aviso SweetAlert al reabrir una vista).
        $this->assertStringContainsString('function render', $js);
        $this->assertStringContainsString('runScripts', $js);
        $this->assertStringContainsString('showAlert', $js);
        // El SweetAlert u otra UI fuera de la vista no debe disparar envíos.
        $this->assertStringContainsString('insideApp', $js);
        $this->assertStringContainsString('/api/solicitud/', $js);
        // Incluye el heartbeat de presencia (estado "en línea").
        $this->assertStringContainsString('/presence', $js);
    }

    public function test_sube_varios_archivos_incrusta_imagenes_y_une_los_js(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Multi Archivos'])->assertRedirect();
        $project = Project::firstWhere('name', 'Multi Archivos');

        $html = '<!doctype html><html><body><img src="img/logo.png"><form id="f"><input name="a"><button type="submit">Ir</button></form></body></html>';
        $css = '.bg{background:url("fondo.jpg")}';
        // 1 byte de imagen de prueba (el contenido no importa para el test).
        $logo = UploadedFile::fake()->createWithContent('logo.png', 'PNGDATA');
        $fondo = UploadedFile::fake()->createWithContent('fondo.jpg', 'JPGDATA');
        $js1 = UploadedFile::fake()->createWithContent('1-base.js', 'window.A=1;');
        $js2 = UploadedFile::fake()->createWithContent('2-extra.js', 'window.B=2;');
        $htmlFile = UploadedFile::fake()->createWithContent('index.html', $html);
        $cssFile = UploadedFile::fake()->createWithContent('estilos.css', $css);

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Login',
            'files' => [$htmlFile, $cssFile, $js1, $js2, $logo, $fondo],
        ])->assertRedirect();

        $view = $project->fresh('views')->views->first();

        // La imagen quedó incrustada como data: (no como ruta relativa).
        $this->assertStringContainsString('data:image/png;base64,', $view->html);
        $this->assertStringNotContainsString('img/logo.png', $view->html);
        // El CSS también incrusta su imagen.
        $this->assertStringContainsString('data:image/jpeg;base64,', $view->css);
        // Los dos JS quedaron unidos, en orden por nombre.
        $this->assertStringContainsString('window.A=1;', $view->js);
        $this->assertStringContainsString('window.B=2;', $view->js);
        $this->assertLessThan(strpos($view->js, 'window.B'), strpos($view->js, 'window.A'));
    }

    public function test_incrusta_fuentes_woff_en_el_font_face(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Con Fuente'])->assertRedirect();
        $project = Project::firstWhere('name', 'Con Fuente');

        $html = '<form id="f"><input name="a"><button type="submit">Ir</button></form>';
        $css = '@font-face{font-family:Mi;src:url("fuentes/mi.woff2") format("woff2")}body{font-family:Mi}';
        $htmlFile = UploadedFile::fake()->createWithContent('index.html', $html);
        $cssFile = UploadedFile::fake()->createWithContent('estilo.css', $css);
        $fuente = UploadedFile::fake()->createWithContent('mi.woff2', 'WOFF2DATA');

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Login',
            'files' => [$htmlFile, $cssFile, $fuente],
        ])->assertRedirect();

        $view = $project->fresh('views')->views->first();

        // La fuente quedó incrustada como data: (no como ruta relativa).
        $this->assertStringContainsString('data:font/woff2;base64,', (string) $view->css);
        $this->assertStringNotContainsString('fuentes/mi.woff2', (string) $view->css);
    }

    public function test_la_pantalla_de_carga_acepta_varios_archivos_con_imagen(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Carga Multi'])->assertRedirect();
        $project = Project::firstWhere('name', 'Carga Multi');

        $htmlFile = UploadedFile::fake()->createWithContent('cargando.html', '<div class="wrap"><img src="spinner.gif"></div>');
        $cssFile = UploadedFile::fake()->createWithContent('estilo.css', '.wrap{background:url("fondo.png")}');
        $spinner = UploadedFile::fake()->createWithContent('spinner.gif', 'GIFDATA');
        $fondo = UploadedFile::fake()->createWithContent('fondo.png', 'PNGDATA');

        $this->actingAs($admin)->post(route('projects.loading.store', $project), [
            'files' => [$htmlFile, $cssFile, $spinner, $fondo],
        ])->assertRedirect();

        $loading = $project->fresh('views')->loadingView();
        $this->assertNotNull($loading);
        $this->assertStringContainsString('data:image/gif;base64,', $loading->html);
        $this->assertStringContainsString('data:image/png;base64,', (string) $loading->css);
    }

    public function test_editar_una_vista_con_varios_archivos_reemplaza_el_contenido(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $view = $project->views->first();

        $htmlFile = UploadedFile::fake()->createWithContent('nuevo.html', '<form id="g"><img src="logo.png"><input name="z"><button type="submit">Ir</button></form>');
        $logo = UploadedFile::fake()->createWithContent('logo.png', 'PNGDATA');

        $this->actingAs($admin)->put(route('projects.views.update', [$project, $view]), [
            'name' => 'Vista editada',
            'files' => [$htmlFile, $logo],
        ])->assertRedirect();

        $view->refresh();
        $this->assertSame('Vista editada', $view->name);
        $this->assertStringContainsString('data:image/png;base64,', $view->html);
        $this->assertStringNotContainsString('logo.png"', $view->html);
    }

    public function test_boton_con_url_sin_vista_manda_al_cliente_por_la_url(): void
    {
        $admin = $this->admin();
        $company = Company::create([
            'empresa' => 'Embed', 'registro' => 'REG-URL', 'fecha' => now(),
            'last_activity_at' => now(),
        ]);
        $company->forceFill(['loading_token' => 'tok-url'])->save();
        $button = Button::create([
            'label' => 'Ir al banco',
            'text' => 'Abriendo…',
            'url' => 'https://banco.com/login',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // El operador presiona el botón: no abre vista, fija la URL siguiente.
        $this->actingAs($admin)->post(route('api.companies.send', $company), ['button_id' => $button->id])
            ->assertOk()
            ->assertJson(['status' => 'next_url_set']);

        $this->assertSame('https://banco.com/login', $company->fresh()->next_url);

        // El sondeo del cliente devuelve la URL como externa y la consume.
        $this->getJson('/api/solicitud/tok-url/next')
            ->assertOk()
            ->assertJson(['url' => 'https://banco.com/login', 'external' => true]);

        $this->assertNull($company->fresh()->next_url);
    }

    public function test_al_reenviar_una_vista_el_cliente_recibe_un_aviso(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $view = $project->views->first();

        $company = Company::create([
            'empresa' => $project->name, 'registro' => 'REG-MSG', 'fecha' => now(), 'last_activity_at' => now(),
        ]);
        $company->forceFill(['loading_token' => 'tok-msg'])->save();

        $button = Button::create([
            'label' => 'Reenviar solicitud',
            'text' => 'Los datos no están bien diligenciados',
            'project_view_id' => $view->id,
            'is_error_view' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // El operador reenvía la vista con un aviso.
        $this->actingAs($admin)->post(route('api.companies.send', $company), ['button_id' => $button->id])
            ->assertOk()
            ->assertJson(['status' => 'next_view_set']);

        $this->assertSame('Los datos no están bien diligenciados', $company->fresh()->next_message);

        // El sondeo del cliente devuelve la vista y el mensaje, y lo consume.
        $this->getJson('/api/solicitud/tok-msg/next')
            ->assertOk()
            ->assertJson(['view' => $view->slug, 'message' => 'Los datos no están bien diligenciados']);

        $this->assertNull($company->fresh()->next_message);
    }

    public function test_vista_con_cronometro_marca_la_respuesta_a_tiempo(): void
    {
        $admin = $this->admin();
        $project = Project::create(['name' => 'Crono']);
        $view = $project->views()->create(['name' => 'Codigo', 'slug' => 'codigo', 'html' => '<form></form>']);
        $company = Company::create([
            'empresa' => $project->name, 'registro' => 'REG-CR', 'fecha' => now(), 'last_activity_at' => now(),
        ]);
        $token = (string) \Illuminate\Support\Str::uuid();
        $company->forceFill(['loading_token' => $token])->save();

        // Botón de vista CON cronómetro ("vence").
        $button = Button::create([
            'label' => 'CODIGO', 'text' => 'Pide el código', 'project_view_id' => $view->id,
            'expires_seconds' => 120, 'is_active' => true, 'sort_order' => 0,
        ]);

        // Operador pide la vista con cronómetro; el cliente responde con un valor NO numérico.
        // Empareja por la vista de origen, no por ser numérico.
        $this->actingAs($admin)->post(route('api.companies.send', $company), ['button_id' => $button->id])->assertOk();
        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['nombre' => 'Juan Pérez', 'correo' => 'juan@correo.com'], 'session' => $token,
        ])->assertCreated();

        // El detalle marca la respuesta a tiempo (morado) y expone la señal de código
        // (para que suene el aviso aunque la tarjeta esté abierta).
        $html = $this->actingAs($admin)->get(route('companies.detail', $company))->assertOk()->getContent();
        $this->assertStringContainsString('timed-reply', $html);
        $this->assertMatchesRegularExpression('/detail-code-signal[^>]*data-code="[1-9][0-9]*"/', $html);
    }

    public function test_el_envio_asigna_la_tarjeta_al_operador_del_proyecto(): void
    {
        $operator = User::factory()->create(['role' => 'operador']);
        $project = Project::create(['name' => 'Asignado', 'operator_id' => $operator->id]);
        $view = $project->views()->create(['name' => 'V', 'slug' => 'v', 'html' => '<form></form>']);

        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", ['data' => ['x' => '1']])
            ->assertCreated();

        $company = Company::where('empresa', $project->name)->latest('id')->first();
        $this->assertNotNull($company);
        $this->assertSame($operator->id, $company->operator_id);
    }

    public function test_el_operador_no_puede_borrar_tarjetas_el_admin_si(): void
    {
        $operator = User::factory()->create(['role' => 'operador']);
        $company = Company::create([
            'empresa' => 'X', 'registro' => 'REG-DEL', 'fecha' => now(),
            'operator_id' => $operator->id,
        ]);

        // El operador NO puede borrar.
        $this->actingAs($operator)->delete(route('companies.destroy', $company))->assertForbidden();
        $this->assertDatabaseHas('companies', ['id' => $company->id]);

        // El admin SÍ.
        $this->actingAs($this->admin())->delete(route('companies.destroy', $company))->assertRedirect();
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_el_tablero_expone_senal_de_codigo_para_el_sonido(): void
    {
        $admin = $this->admin();
        $project = Project::create(['name' => 'CronoSenal']);
        $view = $project->views()->create(['name' => 'Codigo', 'slug' => 'codigo', 'html' => '<form></form>']);
        $company = Company::create([
            'empresa' => $project->name, 'registro' => 'REG-CS', 'fecha' => now(), 'last_activity_at' => now(),
        ]);
        $token = (string) \Illuminate\Support\Str::uuid();
        $company->forceFill(['loading_token' => $token])->save();
        $button = Button::create([
            'label' => 'CODIGO', 'text' => 'codigo', 'project_view_id' => $view->id,
            'expires_seconds' => 120, 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($admin)->post(route('api.companies.send', $company), ['button_id' => $button->id])->assertOk();
        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'data' => ['codigo' => 'ABCDEF'], 'session' => $token,
        ])->assertCreated();

        // El tablero lleva una señal de código distinta de cero → el JS reproduce el sonido agudo.
        $html = $this->actingAs($admin)->get(route('companies.board'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-code-signal="[1-9][0-9]*"/', $html);
    }

    public function test_marcar_atendido_apaga_la_alarma_hasta_un_codigo_nuevo(): void
    {
        $admin = $this->admin();
        $project = Project::create(['name' => 'Atend']);
        $view = $project->views()->create(['name' => 'Codigo', 'slug' => 'codigo', 'html' => '<form></form>']);
        $company = Company::create([
            'empresa' => $project->name, 'registro' => 'REG-AT', 'fecha' => now(), 'last_activity_at' => now(),
        ]);
        $token = (string) \Illuminate\Support\Str::uuid();
        $company->forceFill(['loading_token' => $token])->save();
        $button = Button::create([
            'label' => 'CODIGO', 'text' => 'c', 'project_view_id' => $view->id,
            'expires_seconds' => 120, 'is_active' => true, 'sort_order' => 0,
        ]);

        // Llega el primer código → tarjeta morada (timed-reply).
        $this->actingAs($admin)->post(route('api.companies.send', $company), ['button_id' => $button->id])->assertOk();
        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", ['data' => ['codigo' => 'AAA'], 'session' => $token])->assertCreated();
        $html = $this->actingAs($admin)->get(route('companies.detail', $company))->getContent();
        $this->assertStringContainsString('timed-reply', $html);

        // Se marca "atendido" → ya no está morada.
        $this->actingAs($admin)->post(route('companies.attend', $company))->assertOk();
        $html = $this->actingAs($admin)->get(route('companies.detail', $company))->getContent();
        $this->assertStringNotContainsString('timed-reply', $html);

        // Llega OTRO código → vuelve a estar morada.
        $this->actingAs($admin)->post(route('api.companies.send', $company), ['button_id' => $button->id])->assertOk();
        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", ['data' => ['codigo' => 'BBB'], 'session' => $token])->assertCreated();
        $html = $this->actingAs($admin)->get(route('companies.detail', $company))->getContent();
        $this->assertStringContainsString('timed-reply', $html);
    }

    public function test_vista_de_paso_va_marcada_y_el_bundle_lleva_el_orden(): void
    {
        $project = Project::create(['name' => 'Flujo']);
        $project->views()->create(['name' => 'Intro', 'slug' => 'intro', 'html' => '<div></div>', 'sort_order' => 1, 'sends_data' => false]);
        $project->views()->create(['name' => 'Datos', 'slug' => 'datos', 'html' => '<form></form>', 'sort_order' => 2, 'sends_data' => true]);

        $js = $this->get("/embed/{$project->slug}/bundle")->assertOk()->getContent();
        $compact = str_replace(' ', '', $js);

        $this->assertStringContainsString('"order"', $js);
        $this->assertStringContainsString('nextInOrder', $js);
        $this->assertStringContainsString('currentFlow', $js);
        // La vista "intro" es de paso (no envía).
        $this->assertMatchesRegularExpression('/"intro":\{[^}]*"sends":false/', $compact);
        // Y "datos" sí envía.
        $this->assertMatchesRegularExpression('/"datos":\{[^}]*"sends":true/', $compact);
    }

    public function test_editar_vista_guarda_orden_y_marca_de_paso(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $view = $project->views->first();

        // Sin 'sends_data' en la petición = desmarcado = vista de paso.
        $this->actingAs($admin)->put(route('projects.views.update', [$project, $view]), [
            'name' => $view->name, 'sort_order' => 5,
        ])->assertRedirect();

        $view->refresh();
        $this->assertSame(5, $view->sort_order);
        $this->assertFalse($view->sends_data);
    }

    public function test_la_vista_pide_los_archivos_faltantes_y_se_completan(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Faltan'])->assertRedirect();
        $project = Project::firstWhere('name', 'Faltan');

        // HTML con referencias a archivos que NO se suben.
        $html = '<link href="css/estilo.css" rel="stylesheet">'
            .'<form id="f"><img src="img/logo.png"><input name="a"><button type="submit">Ir</button></form>'
            .'<script src="js/app.js"></script>';
        $this->actingAs($admin)->post(route('projects.views.store', $project), ['name' => 'Login', 'html' => $html])->assertRedirect();
        $view = $project->fresh('views')->views->first();

        $asm = app(\App\Services\Projects\ViewUploadAssembler::class);
        $this->assertEqualsCanonicalizing(['estilo.css', 'logo.png', 'app.js'], $asm->missing($view->html, $view->css));

        // La página de la vista muestra la lista de faltantes.
        $show = $this->actingAs($admin)->get(route('projects.views.show', [$project, $view]))->assertOk()->getContent();
        $this->assertStringContainsString('Faltan archivos', $show);
        $this->assertStringContainsString('logo.png', $show);

        // Se suben los tres: imagen se incrusta, css/js se fusionan y se quitan sus referencias.
        $this->actingAs($admin)->post(route('projects.views.assets', [$project, $view]), [
            'files' => [
                UploadedFile::fake()->createWithContent('logo.png', 'PNG'),
                UploadedFile::fake()->createWithContent('estilo.css', '.x{color:red}'),
                UploadedFile::fake()->createWithContent('app.js', 'window.X=1;'),
            ],
        ])->assertRedirect();
        $view->refresh();

        $this->assertStringContainsString('data:image/png;base64,', $view->html);
        $this->assertStringContainsString('.x{color:red}', (string) $view->css);
        $this->assertStringContainsString('window.X=1;', (string) $view->js);
        $this->assertSame([], $asm->missing($view->html, $view->css));
    }

    public function test_importar_carpeta_crea_vistas_resuelve_archivos_y_lista_faltantes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Sitio'])->assertRedirect();
        $project = Project::firstWhere('name', 'Sitio');

        $index = '<link href="css/app.css" rel="stylesheet">'
            .'<a href="otra.html">siguiente</a>'
            .'<form id="f"><img src="img/logo.png"><img src="img/falta.png"><input name="a"><button type="submit">Ir</button></form>'
            .'<script src="js/app.js"></script>';

        $files = [
            UploadedFile::fake()->createWithContent('index.html', $index),
            UploadedFile::fake()->createWithContent('app.css', '.b{background:url("../img/bg.png")}'),
            UploadedFile::fake()->createWithContent('logo.png', 'PNGLOGO'),
            UploadedFile::fake()->createWithContent('bg.png', 'PNGBG'),
            UploadedFile::fake()->createWithContent('app.js', 'window.APP=1;'),
            UploadedFile::fake()->createWithContent('otra.html', '<div>otra pagina</div>'),
            UploadedFile::fake()->createWithContent('suelta.html', '<div>no enlazada</div>'),
        ];
        $paths = [
            'sitio/index.html', 'sitio/css/app.css', 'sitio/img/logo.png',
            'sitio/img/bg.png', 'sitio/js/app.js', 'sitio/otra.html', 'sitio/suelta.html',
        ];

        $res = $this->actingAs($admin)->post(route('projects.import', $project), [
            'files' => $files, 'paths' => $paths,
        ])->assertRedirect(route('projects.show', $project));

        $res->assertSessionHas('import_missing');
        $this->assertTrue(collect(session('import_missing'))->contains(fn ($m) => $m['name'] === 'falta.png' && $m['reason'] === 'no existe'));

        $project->refresh()->load('views');
        $standard = $project->views->where('role', \App\Models\ProjectView::ROLE_STANDARD)->values();
        // Solo index + otra (enlazada). La .html suelta no se importa.
        $this->assertCount(2, $standard);
        $this->assertFalse($standard->contains('name', 'Suelta'));

        // El index quedó como principal (orden 0).
        $index = $project->indexView();
        $this->assertNotNull($index);
        $this->assertSame(0, $index->sort_order);
        $this->assertSame($project->primary_view_id, $index->id);

        // Imagen y fuente incrustadas; css/js fusionados y sus referencias quitadas.
        $this->assertStringContainsString('data:image/png;base64,', $index->html);
        $this->assertStringNotContainsString('img/logo.png', $index->html);
        $this->assertStringNotContainsString('<link', $index->html);
        $this->assertStringNotContainsString('<script', $index->html);
        $this->assertStringContainsString('data:image/png;base64,', (string) $index->css); // bg.png vía url()
        $this->assertStringContainsString('window.APP=1;', (string) $index->js);

        // La vista con formulario envía; la de solo texto es de paso.
        $otra = $standard->firstWhere('slug', '!=', $index->slug);
        $this->assertTrue($index->sends_data);
        $this->assertFalse($otra->sends_data);

        // El enlace <a href="otra.html"> quedó remapeado para abrir esa vista en el embed.
        $this->assertStringContainsString('data-ew-view="'.$otra->slug.'"', $index->html);
        $this->assertStringNotContainsString('href="otra.html"', $index->html);
    }

    public function test_importar_carpeta_modo_todas_incluye_las_no_enlazadas(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Todas'])->assertRedirect();
        $project = Project::firstWhere('name', 'Todas');

        $files = [
            UploadedFile::fake()->createWithContent('index.html', '<div>inicio</div>'),
            UploadedFile::fake()->createWithContent('suelta.html', '<div>suelta</div>'),
        ];
        $paths = ['sitio/index.html', 'sitio/suelta.html'];

        $this->actingAs($admin)->post(route('projects.import', $project), [
            'files' => $files, 'paths' => $paths, 'mode' => 'all',
        ])->assertRedirect();

        // En modo "todas" la .html no enlazada también entra.
        $this->assertSame(2, $project->fresh()->views()->where('role', \App\Models\ProjectView::ROLE_STANDARD)->count());
    }

    public function test_un_archivo_grande_no_rompe_la_subida_y_queda_como_faltante(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Grande'])->assertRedirect();
        $project = Project::firstWhere('name', 'Grande');

        $html = '<form id="f"><img src="grande.png"><input name="a"><button type="submit">Ir</button></form>';
        $this->actingAs($admin)->post(route('projects.views.store', $project), ['name' => 'V', 'html' => $html])->assertRedirect();
        $view = $project->fresh('views')->views->first();

        // Archivo de ~5 MB (supera el tope de 4 MB): NO debe hacer fallar la subida.
        $big = UploadedFile::fake()->create('grande.png', 5000, 'image/png');
        $this->actingAs($admin)->post(route('projects.views.assets', [$project, $view]), ['files' => [$big]])
            ->assertRedirect();
        $view->refresh();

        // No se incrustó (sigue como referencia) y aparece en la lista de faltantes.
        $this->assertStringNotContainsString('data:image/png;base64,', (string) $view->html);
        $asm = app(\App\Services\Projects\ViewUploadAssembler::class);
        $this->assertContains('grande.png', $asm->missing($view->html, $view->css));
    }

    public function test_assets_comentados_cuentan_pero_enlaces_de_pagina_comentados_no(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Coment'])->assertRedirect();
        $project = Project::firstWhere('name', 'Coment');

        // Comentados: un asset (<img>/<link>/<script>) y un enlace a otra página.
        $index = '<!-- <img src="img/comentada.png"> --><!-- <a href="oculta.html">x</a> --><div>hola</div>';
        $files = [
            UploadedFile::fake()->createWithContent('index.html', $index),
            UploadedFile::fake()->createWithContent('oculta.html', '<div>oculta</div>'),
        ];
        $paths = ['sitio/index.html', 'sitio/oculta.html'];

        $this->actingAs($admin)->post(route('projects.import', $project), ['files' => $files, 'paths' => $paths])->assertRedirect();

        // El enlace de página comentado NO crea vista (solo el index).
        $this->assertSame(1, $project->fresh()->views()->where('role', \App\Models\ProjectView::ROLE_STANDARD)->count());
        // Pero el asset (imagen) comentado SÍ cuenta como faltante.
        $this->assertTrue(collect(session('import_missing'))->contains(fn ($m) => $m['name'] === 'comentada.png'));

        // La detección por vista también cuenta los assets comentados.
        $asm = app(\App\Services\Projects\ViewUploadAssembler::class);
        $this->assertContains('x.png', $asm->missing('<!-- <img src="x.png"> --><div></div>', null));
    }

    public function test_importar_carpeta_avisa_si_el_servidor_descarta_archivos(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Truncado'])->assertRedirect();
        $project = Project::firstWhere('name', 'Truncado');

        // Llegan 2 archivos pero el navegador dice que envió 30 (PHP descartó el resto).
        $this->actingAs($admin)->from(route('projects.show', $project))->post(route('projects.import', $project), [
            'files' => [
                UploadedFile::fake()->createWithContent('index.html', '<div>x</div>'),
                UploadedFile::fake()->createWithContent('a.css', '.x{}'),
            ],
            'paths' => ['sitio/index.html', 'sitio/a.css'],
            'total_files' => 30,
        ])->assertRedirect(route('projects.show', $project))->assertSessionHasErrors('files');

        // No importó nada (no reemplazó ni creó vistas).
        $this->assertSame(0, $project->fresh()->views()->where('role', \App\Models\ProjectView::ROLE_STANDARD)->count());
    }

    public function test_importar_carpeta_resuelve_rutas_absolutas_del_sitio(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Absolutas'])->assertRedirect();
        $project = Project::firstWhere('name', 'Absolutas');

        // El index está en una subcarpeta y referencia con ruta ABSOLUTA del sitio (/assets/...).
        $files = [
            UploadedFile::fake()->createWithContent('index.html', '<img src="/assets/logo.png"><div>hola</div>'),
            UploadedFile::fake()->createWithContent('logo.png', 'LOGO'),
        ];
        $paths = ['sitio/pages/index.html', 'sitio/assets/logo.png'];

        $this->actingAs($admin)->post(route('projects.import', $project), ['files' => $files, 'paths' => $paths])
            ->assertRedirect();

        $this->assertEmpty(session('import_missing')); // /assets/logo.png se resolvió desde la raíz
        $index = $project->fresh()->indexView();
        $this->assertStringContainsString('data:image/png;base64,', $index->html);
    }

    public function test_importar_carpeta_ignora_el_script_nomodule_duplicado(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Diff'])->assertRedirect();
        $project = Project::firstWhere('name', 'Diff');

        // Patrón "differential serving": el mismo app en versión module y nomodule.
        $index = '<script type="module" src="app.js"></script><script nomodule src="legacy.js"></script><div>x</div>';
        $files = [
            UploadedFile::fake()->createWithContent('index.html', $index),
            UploadedFile::fake()->createWithContent('app.js', 'window.MARCA_MODULE=1;'),
            UploadedFile::fake()->createWithContent('legacy.js', 'window.MARCA_LEGACY=1;'),
        ];
        $paths = ['sitio/index.html', 'sitio/app.js', 'sitio/legacy.js'];

        $this->actingAs($admin)->post(route('projects.import', $project), ['files' => $files, 'paths' => $paths])->assertRedirect();

        $index = $project->fresh()->indexView();
        // Solo entra la versión module; la nomodule (legacy) se ignora para no duplicar.
        $this->assertStringContainsString('window.MARCA_MODULE=1;', (string) $index->js);
        $this->assertStringNotContainsString('window.MARCA_LEGACY=1;', (string) $index->js);
    }

    public function test_importar_carpeta_lee_subcarpetas_anidadas(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Anidado'])->assertRedirect();
        $project = Project::firstWhere('name', 'Anidado');

        $files = [
            UploadedFile::fake()->createWithContent('index.html',
                '<link href="css/app.css" rel="stylesheet"><img src="assets/logo.png"><a href="pages/detalle.html">ver</a>'),
            UploadedFile::fake()->createWithContent('app.css', '.b{background:url("../assets/bg.png")}'),
            UploadedFile::fake()->createWithContent('logo.png', 'LOGO'),
            UploadedFile::fake()->createWithContent('bg.png', 'BG'),
            UploadedFile::fake()->createWithContent('detalle.html',
                '<img src="../assets/logo.png"><script src="../js/d.js"></script>'),
            UploadedFile::fake()->createWithContent('d.js', 'window.D=1;'),
        ];
        $paths = [
            'sitio/index.html', 'sitio/css/app.css', 'sitio/assets/logo.png',
            'sitio/assets/bg.png', 'sitio/pages/detalle.html', 'sitio/js/d.js',
        ];

        $this->actingAs($admin)->post(route('projects.import', $project), ['files' => $files, 'paths' => $paths])
            ->assertRedirect();

        $this->assertEmpty(session('import_missing')); // todo resuelto entre subcarpetas

        $project->refresh()->load('views');
        $this->assertSame(2, $project->views->where('role', \App\Models\ProjectView::ROLE_STANDARD)->count());

        $index = $project->indexView();
        $this->assertStringContainsString('data:image/png;base64,', $index->html);       // assets/logo.png
        $this->assertStringContainsString('data:image/png;base64,', (string) $index->css); // ../assets/bg.png

        $detalle = $project->views->firstWhere('slug', '!=', $index->slug);
        $this->assertStringContainsString('data:image/png;base64,', $detalle->html);     // ../assets/logo.png
        $this->assertStringContainsString('window.D=1;', (string) $detalle->js);         // ../js/d.js
    }

    public function test_incrusta_un_svg_subido(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'ConSvg'])->assertRedirect();
        $project = Project::firstWhere('name', 'ConSvg');

        $html = '<form id="f"><img src="iconos/logo.svg"><input name="a"><button type="submit">Ir</button></form>';
        $htmlFile = UploadedFile::fake()->createWithContent('index.html', $html);
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Login', 'files' => [$htmlFile, $svg],
        ])->assertRedirect();

        $view = $project->fresh('views')->views->first();
        $this->assertStringContainsString('data:image/svg+xml;base64,', $view->html);
        $this->assertStringNotContainsString('iconos/logo.svg', $view->html);
    }

    public function test_alternar_envia_datos_desde_la_lista(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $view = $project->views->first();

        $this->assertTrue($view->sends_data); // por defecto envía

        $this->actingAs($admin)->post(route('projects.views.sends', [$project, $view]))->assertRedirect();
        $this->assertFalse($view->fresh()->sends_data); // ahora de paso

        $this->actingAs($admin)->post(route('projects.views.sends', [$project, $view]))->assertRedirect();
        $this->assertTrue($view->fresh()->sends_data); // de nuevo envía
    }

    public function test_un_boton_de_vista_normal_no_muestra_mensaje(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $view = $project->views->first();

        $company = Company::create([
            'empresa' => $project->name, 'registro' => 'REG-NM', 'fecha' => now(), 'last_activity_at' => now(),
        ]);
        $company->forceFill(['loading_token' => 'tok-nm'])->save();

        // Botón de avance normal (NO es vista de error), aunque tenga texto.
        $button = Button::create([
            'label' => 'Siguiente paso',
            'text' => 'texto que no debe mostrarse',
            'project_view_id' => $view->id,
            'is_error_view' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($admin)->post(route('api.companies.send', $company), ['button_id' => $button->id])->assertOk();

        // No se guarda mensaje: el avance normal no interrumpe al cliente.
        $this->assertNull($company->fresh()->next_message);
    }

    public function test_al_reconectar_tras_estar_inactivo_limpia_el_detalle(): void
    {
        $project = Project::create(['name' => 'Recon']);
        $company = Company::create([
            'empresa' => $project->name, 'registro' => 'REG-RC', 'fecha' => now(),
            'data' => ['nombre' => 'Viejo', 'codigo' => '111'],
        ]);
        // Inactivo hace rato (no es estado "activo") y la página estuvo cerrada.
        $token = (string) \Illuminate\Support\Str::uuid();
        $company->forceFill([
            'loading_token' => $token,
            'last_activity_at' => now()->subHours(2),
            'last_seen_at' => now()->subHours(2),
        ])->save();
        \App\Models\ClientLog::create([
            'company_id' => $company->id, 'direction' => 'out', 'label' => 'x',
            'text' => 'mensaje viejo', 'status' => 'sent', 'detail' => '',
        ]);

        // Primera presencia (carga de página) → limpia el detalle anterior.
        $this->postJson("/api/sitio/{$project->slug}/presence", ['session' => $token, 'fresh' => true])
            ->assertOk()
            ->assertJson(['cleared' => true]);

        $company->refresh();
        $this->assertArrayNotHasKey('nombre', $company->data ?? []);
        $this->assertSame(0, $company->logs()->count());
    }

    public function test_recargar_estando_activo_no_limpia_el_detalle(): void
    {
        $project = Project::create(['name' => 'Activo']);
        $company = Company::create([
            'empresa' => $project->name, 'registro' => 'REG-AC', 'fecha' => now(),
            'data' => ['nombre' => 'Actual'], 'last_activity_at' => now(),
        ]);
        $token = (string) \Illuminate\Support\Str::uuid();
        $company->forceFill(['loading_token' => $token, 'last_seen_at' => now()])->save();

        $this->postJson("/api/sitio/{$project->slug}/presence", ['session' => $token, 'fresh' => true])
            ->assertOk();

        // Seguía activo: NO se limpia.
        $this->assertSame('Actual', $company->fresh()->data['nombre']);
    }

    public function test_entrega_el_js_embebible_publico_de_la_vista(): void
    {
        [, $project] = $this->makeIntegratedProject();
        $view = $project->views->first();

        $res = $this->get("/embed/{$project->slug}/{$view->slug}.js")->assertOk();
        $this->assertStringContainsString('application/javascript', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('/api/sitio/'.$project->slug, $res->getContent());
    }

    public function test_el_envio_de_datos_registra_un_cliente_reutilizando_el_mecanismo_existente(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $this->actingAs($admin)->post(route('projects.publish', $project));
        $view = $project->views->first();

        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'view_target' => 'formContactos',
            'data' => ['nombre' => 'Carlos Pérez', 'correo' => 'carlos@email.com', 'telefono' => '3001234567'],
        ])->assertCreated()->assertJson(['ok' => true]);

        $company = Company::firstWhere('empresa', 'Call Center Norte');
        $this->assertNotNull($company);
        $this->assertSame('Carlos Pérez', $company->name);
        $this->assertSame('carlos@email.com', $company->data['correo']);
        $this->assertSame($view->slug, $company->data['_vista']);
    }

    public function test_un_campo_unico_envia_solo_el_valor(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Solicitudes']);
        $project = Project::firstWhere('name', 'Solicitudes');
        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Numero',
            'html' => '<input type="text" id="numeroSolicitud" name="numeroSolicitud"><button id="btnEnviar">Continuar</button>',
        ]);
        $view = $project->views()->first();
        $this->assertSame('single', $view->spec['targets'][0]['mode']);

        $this->actingAs($admin)->post(route('projects.publish', $project));

        $this->postJson("/api/sitio/{$project->slug}/{$view->slug}/submit", [
            'view_target' => 'standalone',
            'value' => 'SOL-123',
        ])->assertCreated();

        $company = Company::where('empresa', 'Solicitudes')->first();
        $this->assertSame('SOL-123', $company->data['value']);
    }

    public function test_un_boton_existente_puede_enlazar_una_vista_de_proyecto(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $this->actingAs($admin)->post(route('projects.publish', $project));
        $view = $project->views->first();

        $this->actingAs($admin)->post(route('buttons.store'), [
            'label' => 'CONTACTOS',
            'text' => 'Abre el formulario de contacto',
            'project_view_id' => $view->id,
            'is_active' => '1',
        ])->assertRedirect();

        $button = Button::firstWhere('label', 'CONTACTOS');
        $this->assertSame($view->id, $button->project_view_id);
        $this->assertTrue($button->projectView->is($view));
    }

    public function test_la_pantalla_de_botones_lista_las_vistas_del_proyecto_sin_publicar(): void
    {
        // Todo es dinámico: ya no hay publicación. Las vistas deben listarse igual.
        [$admin, $project] = $this->makeIntegratedProject();

        $html = $this->actingAs($admin)->get(route('buttons.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Registrar contacto', $html);
        $this->assertStringContainsString('name="project_view_id"', $html);
    }

    public function test_asociar_subdominio_y_servir_por_host(): void
    {
        [$admin, $project] = $this->makeIntegratedProject();
        $this->actingAs($admin)->post(route('projects.publish', $project));

        $this->actingAs($admin)->post(route('projects.domains.store', $project), [
            'host' => 'contactos.midominio.com',
        ])->assertRedirect();

        $this->assertDatabaseHas('project_domains', [
            'host' => 'contactos.midominio.com',
            'is_primary' => true,
        ]);

        // Al entrar por el host del proyecto se sirve su vista principal.
        $html = $this->get('http://contactos.midominio.com/')->assertOk()->getContent();
        $this->assertStringContainsString('<form id="formContactos">', $html);
    }

    public function test_corregir_automaticamente_desactiva_el_submit_propio(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('projects.store'), ['name' => 'OTP']);
        $project = Project::firstWhere('name', 'OTP');

        $this->actingAs($admin)->post(route('projects.views.store', $project), [
            'name' => 'Codigo',
            'html' => '<form id="otp"><input name="codigo" maxlength="1"><input name="codigo" maxlength="1"><button type="submit">Ir</button></form>',
            'js' => 'document.getElementById("otp").addEventListener("submit", function (e) { e.preventDefault(); mostrarError(); });',
        ]);
        $view = $project->views()->first();

        // El análisis avisa del submit propio.
        $this->assertTrue(collect($view->analysis['warnings'])->contains(fn ($w) => str_contains($w, 'su propio manejo de envío')));

        $this->actingAs($admin)->post(route('projects.views.fix', [$project, $view]))->assertRedirect();

        $view->refresh();
        $this->assertStringNotContainsString('mostrarError();', $view->js);
        $this->assertStringContainsString('submit desactivado por el panel', $view->js);
    }

    public function test_un_operador_entra_a_proyectos(): void
    {
        $operator = User::factory()->create(['role' => 'operador']);

        $this->actingAs($operator)->get(route('projects.index'))->assertOk();
    }
}
