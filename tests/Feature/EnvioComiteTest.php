<?php

namespace Tests\Feature;

use App\Enums\EstadoSolicitud;
use App\Mail\SolicitudAlComiteMail;
use App\Mail\Transport\LogResumenTransport;
use App\Models\Auditoria;
use App\Models\CuantiaSmmlv;
use App\Models\DocumentoSolicitud;
use App\Models\Empleado;
use App\Models\EnvioComite;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoDocumento;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalculoFinancieroService;
use App\Services\EnvioComiteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EnvioComiteTest extends TestCase
{
    use RefreshDatabase;

    private const SALARIO = 4_321_000;

    private Usuario $usuario;

    private TipoDocumento $tipoDocumento;

    private TipoPrestamo $modalidad;

    private Empleado $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'comite.destinatarios' => ['comite@example.test', 'secretaria@example.test'],
            'comite.copia' => ['bienestar@example.test'],
            'comite.asunto' => 'Solicitud #{solicitud} — {modalidad} — {empleado}',
        ]);

        $this->usuario = Usuario::factory()->create();
        $this->actingAs($this->usuario);
        $this->tipoDocumento = TipoDocumento::factory()->create(['nombre' => 'Carta de solicitud']);

        $anio = now()->year;
        SmmlvAnual::factory()->create(['anio' => $anio, 'valor' => 1_300_000]);
        $this->modalidad = TipoPrestamo::factory()->create(['nombre' => 'Educación', 'plazo_maximo' => 72]);
        TasaInt::factory()->create(['id_tipo' => $this->modalidad->id_tipo, 'anio' => $anio, 'tasa_anual' => 5.1]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $this->modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 17.5]);
        PresupuestoAnual::factory()->create(['id_tipo' => $this->modalidad->id_tipo, 'anio' => $anio, 'monto_asignado' => 10_000_000_000]);

        $this->empleado = Empleado::factory()->create([
            'nombre_completo' => 'Persona Ficticia Uno',
            'cedula' => '1234567890',
            'salario' => self::SALARIO,
            'capacidad_endeudamiento' => 2_000_000,
            'fecha_vinculacion' => now()->subYears(5)->toDateString(),
        ]);
    }

    private function solicitud(?EstadoSolicitud $estado = null, ?Empleado $empleado = null, float $monto = 4_000_000): SolicitudCredito
    {
        return SolicitudCredito::factory()->create([
            'cedula_empleado' => ($empleado ?? $this->empleado)->cedula,
            'id_tipo' => $this->modalidad->id_tipo,
            'monto_solicitado' => $monto,
            'plazo_solicitado' => 72,
            'fecha_solicitud' => now()->toDateString(),
            'estado' => $estado ?? EstadoSolicitud::Radicada,
        ]);
    }

    private function documento(SolicitudCredito $solicitud, string $nombre = 'carta.pdf', int $bytes = 1_000, bool $conArchivo = true, string $contenido = '%PDF-contenido-de-prueba'): DocumentoSolicitud
    {
        $ruta = "documentos-solicitud/{$solicitud->id_solicitud}/".Str::random(20).'.pdf';

        if ($conArchivo) {
            Storage::disk('local')->put($ruta, $contenido);
        }

        return DocumentoSolicitud::factory()->create([
            'id_solicitud' => $solicitud->id_solicitud,
            'id_tipo_documento' => $this->tipoDocumento->id_tipo_documento,
            'nombre_original' => $nombre,
            'ruta' => $ruta,
            'tamano' => $bytes,
            'tipo_mime' => 'application/pdf',
            'cedula_usu' => $this->usuario->cedula,
        ]);
    }

    /**
     * @param  list<int>  $documentos
     */
    private function enviar(SolicitudCredito $solicitud, array $documentos = [], bool $confirmarReenvio = false): TestResponse
    {
        return $this->withSession(['_token' => 'token-de-prueba'])->post(route('solicitudes.comite.enviar', $solicitud), array_filter([
            '_token' => 'token-de-prueba',
            'documentos' => $documentos,
            'confirmar_reenvio' => $confirmarReenvio ? 1 : null,
        ]));
    }

    private function usarCorreoReal(): void
    {
        config(['mail.default' => 'smtp']);
    }

    // ───────── Permiso y acceso ─────────

    public function test_administrador_y_funcionario_pueden_ver_la_vista_previa(): void
    {
        $solicitud = $this->solicitud();

        foreach ([Usuario::factory()->administrador()->create(), Usuario::factory()->create()] as $usuario) {
            $this->actingAs($usuario)->get(route('solicitudes.comite.previa', $solicitud))->assertOk();
        }
    }

    public function test_sin_sesion_o_con_cuenta_deshabilitada_se_redirige_al_login(): void
    {
        $solicitud = $this->solicitud();
        $this->app['auth']->logout();

        $this->get(route('solicitudes.comite.previa', $solicitud))->assertRedirect(route('login'));
        $this->post(route('solicitudes.comite.enviar', $solicitud))->assertRedirect(route('login'));

        $this->actingAs(Usuario::factory()->create(['habilitado' => false]))
            ->get(route('solicitudes.comite.previa', $solicitud))->assertRedirect(route('login'));
    }

    public function test_el_permiso_es_de_la_cuenta_y_sin_el_no_hay_boton_ni_acceso(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();
        $this->usuario = Usuario::factory()->conPermisos(['solicitudes.ver', 'solicitudes.aprobar'])->create();
        $this->actingAs($this->usuario);

        $this->assertFalse($this->usuario->tienePermiso('solicitudes.enviar-comite'));
        $this->get(route('solicitudes.show', $solicitud))->assertOk()->assertDontSee('id="enviar-al-comite"', false);
        $this->get(route('solicitudes.comite.previa', $solicitud))->assertForbidden();
        $this->enviar($solicitud)->assertForbidden();

        Mail::assertNothingSent();
        $this->assertSame(0, EnvioComite::count());
    }

    public function test_el_permiso_es_un_gate_y_depende_de_la_cuenta_habilitada(): void
    {
        $this->assertTrue($this->usuario->tienePermiso('solicitudes.enviar-comite'));
        $this->assertTrue(Gate::forUser($this->usuario)->allows('solicitudes.enviar-comite'));
        $this->assertFalse(Usuario::factory()->create(['habilitado' => false])->tienePermiso('solicitudes.enviar-comite'));
        $this->assertFalse($this->usuario->tienePermiso('permiso.que-no-existe'));
    }

    // ───────── Estado de la solicitud ─────────

    public function test_el_boton_aparece_solo_en_radicada_y_la_vista_previa_rechaza_otros_estados(): void
    {
        $radicada = $this->solicitud();
        $aprobada = $this->solicitud(EstadoSolicitud::Aprobada);
        $rechazada = $this->solicitud(EstadoSolicitud::Rechazada);

        $this->get(route('solicitudes.show', $radicada))->assertSee('id="enviar-al-comite"', false)->assertSee('Enviar al comité');

        foreach ([$aprobada, $rechazada] as $solicitud) {
            $this->get(route('solicitudes.show', $solicitud))->assertOk()->assertDontSee('id="enviar-al-comite"', false);
            $this->get(route('solicitudes.comite.previa', $solicitud))
                ->assertRedirect(route('solicitudes.show', $solicitud))
                ->assertSessionHas('error');
        }
    }

    public function test_el_servidor_rechaza_el_envio_de_una_solicitud_que_no_esta_radicada(): void
    {
        Mail::fake();
        $aprobada = $this->solicitud(EstadoSolicitud::Aprobada);

        $this->enviar($aprobada)
            ->assertRedirect(route('solicitudes.comite.previa', $aprobada))
            ->assertSessionHas('error', 'Solo se puede enviar al comité una solicitud Radicada; esta está Aprobada.');

        Mail::assertNothingSent();
        $this->assertSame(0, EnvioComite::count());
        $this->assertSame(0, Auditoria::count());
    }

    // ───────── Vista previa ─────────

    public function test_la_vista_previa_muestra_destinatarios_asunto_cuerpo_y_adjuntos_sin_enviar_ni_guardar(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();
        $this->documento($solicitud, 'carta.pdf', 2_048);

        $respuesta = $this->get(route('solicitudes.comite.previa', $solicitud))->assertOk();

        $respuesta->assertSee('comite@example.test, secretaria@example.test');
        $respuesta->assertSee('bienestar@example.test');
        $respuesta->assertSee("Solicitud #{$solicitud->id_solicitud} — Educación — Persona Ficticia Uno");
        $respuesta->assertSee('carta.pdf');
        $respuesta->assertSee('Persona Ficticia Uno');
        $respuesta->assertSee('Enviar al comité');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(0, EnvioComite::count());
        $this->assertSame(0, Auditoria::count());
    }

    public function test_la_vista_previa_indica_que_es_simulado_con_el_mailer_log_o_array(): void
    {
        $solicitud = $this->solicitud();

        $this->get(route('solicitudes.comite.previa', $solicitud))->assertSee('Simulado (no se envió)');

        $this->usarCorreoReal();
        $this->get(route('solicitudes.comite.previa', $solicitud))->assertDontSee('Simulado (no se envió)');
    }

    public function test_sin_documentos_se_puede_enviar_con_un_aviso(): void
    {
        $solicitud = $this->solicitud();

        $this->get(route('solicitudes.comite.previa', $solicitud))
            ->assertOk()
            ->assertSee('La solicitud se enviará sin documentos adjuntos.')
            ->assertDontSee('No se puede enviar:');
    }

    public function test_el_interruptor_exigir_documentos_bloquea_el_envio_sin_ellos(): void
    {
        Mail::fake();
        config(['comite.adjuntos.exigir_documentos' => true]);
        $solicitud = $this->solicitud();

        $this->get(route('solicitudes.comite.previa', $solicitud))->assertSee('Se exigen documentos adjuntos y no hay ninguno seleccionado.');
        $this->enviar($solicitud)->assertSessionHas('error', 'Se exigen documentos adjuntos y no hay ninguno seleccionado.');

        Mail::assertNothingSent();
    }

    public function test_el_formulario_evita_el_doble_clic_y_se_bloquea_si_hay_impedimentos(): void
    {
        $solicitud = $this->solicitud();

        $this->get(route('solicitudes.comite.previa', $solicitud))
            ->assertSee('boton.disabled = true', false)
            ->assertSee('id="boton-enviar-comite"', false);

        config(['comite.destinatarios' => []]);

        $this->get(route('solicitudes.comite.previa', $solicitud))
            ->assertSeeInOrder(['id="boton-enviar-comite"', 'disabled'], false);
    }

    // ───────── Envío ─────────

    public function test_enviar_manda_un_correo_con_destinatarios_copia_asunto_y_adjuntos_y_deja_el_registro(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();
        $carta = $this->documento($solicitud, 'carta.pdf', 2_048);
        $cedula = $this->documento($solicitud, 'cedula.pdf', 1_024);

        $this->enviar($solicitud, [$carta->id_documento, $cedula->id_documento])
            ->assertRedirect(route('solicitudes.show', $solicitud))
            ->assertSessionHas('status');

        Mail::assertSent(SolicitudAlComiteMail::class, 1);
        Mail::assertSent(SolicitudAlComiteMail::class, fn (SolicitudAlComiteMail $correo): bool => $correo->hasTo('comite@example.test')
            && $correo->hasTo('secretaria@example.test')
            && $correo->hasCc('bienestar@example.test')
            && $correo->asunto === "Solicitud #{$solicitud->id_solicitud} — Educación — Persona Ficticia Uno"
            && count($correo->attachments()) === 2);

        $envio = EnvioComite::sole();
        $this->assertSame($solicitud->id_solicitud, $envio->id_solicitud);
        $this->assertSame($this->usuario->cedula, $envio->cedula_usu);
        $this->assertSame(['comite@example.test', 'secretaria@example.test'], $envio->destinatarios);
        $this->assertSame(['bienestar@example.test'], $envio->copia);
        $this->assertSame("Solicitud #{$solicitud->id_solicitud} — Educación — Persona Ficticia Uno", $envio->asunto);
        $this->assertSame(
            [['id' => $carta->id_documento, 'nombre' => 'carta.pdf', 'bytes' => 2_048, 'mime' => 'application/pdf'], ['id' => $cedula->id_documento, 'nombre' => 'cedula.pdf', 'bytes' => 1_024, 'mime' => 'application/pdf']],
            $envio->adjuntos,
        );
        $this->assertFalse($envio->es_reenvio);
        $this->assertNotNull($envio->enviado_en);

        $auditoria = Auditoria::where('funcion_realizada', 'Envío de solicitud al comité')->sole();
        $this->assertSame($this->usuario->cedula, $auditoria->cedula_usu);
        $this->assertStringContainsString('carta.pdf', $auditoria->detalle);
        $this->assertStringContainsString('comite@example.test', $auditoria->detalle);
    }

    public function test_un_envio_con_correo_real_no_es_simulado_y_con_log_o_array_si(): void
    {
        Mail::fake();
        $simulada = $this->solicitud();
        $real = $this->solicitud();

        $this->enviar($simulada)->assertSessionHas('status', 'Envío simulado (no se envió): el correo quedó registrado en el log y en el historial de envíos.');
        $this->usarCorreoReal();
        $this->enviar($real)->assertSessionHas('status', 'Solicitud enviada al comité correctamente.');

        $this->assertTrue(EnvioComite::where('id_solicitud', $simulada->id_solicitud)->sole()->simulado);
        $this->assertFalse(EnvioComite::where('id_solicitud', $real->id_solicitud)->sole()->simulado);
        $this->assertSame('smtp', EnvioComite::where('id_solicitud', $real->id_solicitud)->sole()->mailer);
    }

    public function test_cada_solicitud_sale_en_su_propio_correo(): void
    {
        Mail::fake();
        $primera = $this->solicitud();
        $segunda = $this->solicitud();

        $this->enviar($primera);
        $this->enviar($segunda);

        Mail::assertSent(SolicitudAlComiteMail::class, 2);
        Mail::assertSent(SolicitudAlComiteMail::class, fn (SolicitudAlComiteMail $correo): bool => $correo->datos['id_solicitud'] === $primera->id_solicitud);
        Mail::assertSent(SolicitudAlComiteMail::class, fn (SolicitudAlComiteMail $correo): bool => $correo->datos['id_solicitud'] === $segunda->id_solicitud);
    }

    public function test_enviar_no_cambia_el_estado_y_aprobar_o_rechazar_siguen_disponibles(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();

        $this->enviar($solicitud);

        $this->assertSame(EstadoSolicitud::Radicada, $solicitud->fresh()->estado);
        $this->assertTrue(Gate::allows('aprobar', $solicitud->fresh()) && Gate::allows('rechazar', $solicitud->fresh()));
        $this->get(route('solicitudes.show', $solicitud))->assertSee('Enviada al comité')->assertSee('Aprobar')->assertSee('Rechazar');

        $this->patch(route('solicitudes.rechazar', $solicitud), ['motivo_rechazo' => 'Motivo de prueba'])->assertRedirect();
        $this->assertSame(EstadoSolicitud::Rechazada, $solicitud->fresh()->estado);
    }

    public function test_el_listado_y_el_detalle_muestran_la_marca_y_el_historial(): void
    {
        Mail::fake();
        $enviada = $this->solicitud();
        $this->solicitud(empleado: Empleado::factory()->create(['nombre_completo' => 'Persona Ficticia Dos']));
        $this->documento($enviada, 'carta.pdf');
        $this->enviar($enviada, [DocumentoSolicitud::first()->id_documento]);

        $this->get(route('solicitudes.index'))->assertOk()->assertSee('Enviada al comité')->assertSee('simulado (no se envió)');

        $this->get(route('solicitudes.show', $enviada))
            ->assertSee('Envíos al comité')
            ->assertSee('id="historial-envios-comite"', false)
            ->assertSee($this->usuario->nombre_completo)
            ->assertSee('comite@example.test, secretaria@example.test')
            ->assertSee('carta.pdf')
            ->assertSee('simulado (no se envió)');
    }

    // ───────── Reenvío y doble envío ─────────

    public function test_reenviar_exige_confirmar_y_queda_registrado_como_reenvio(): void
    {
        Mail::fake();
        config(['comite.segundos_entre_envios' => 0]);
        $solicitud = $this->solicitud();

        $this->enviar($solicitud)->assertSessionHas('status');
        Mail::assertSent(SolicitudAlComiteMail::class, 1);

        $this->get(route('solicitudes.comite.previa', $solicitud))->assertSee('Confirmo el reenvío')->assertSee('Reenviar al comité');
        $this->get(route('solicitudes.show', $solicitud))->assertSee('Reenviar al comité');

        $this->enviar($solicitud)->assertSessionHas('error');
        $this->assertStringContainsString('Confirmo el reenvío', session('error'));
        Mail::assertSent(SolicitudAlComiteMail::class, 1);
        $this->assertSame(1, EnvioComite::count());

        $this->enviar($solicitud, confirmarReenvio: true)->assertSessionHas('status');

        Mail::assertSent(SolicitudAlComiteMail::class, 2);
        $this->assertSame(2, EnvioComite::count());
        $this->assertTrue($solicitud->envios()->first()->es_reenvio);
        $this->assertFalse(EnvioComite::orderBy('id_envio')->first()->es_reenvio);
        $this->assertSame(1, Auditoria::where('funcion_realizada', 'Envío de solicitud al comité')->count());
        $this->assertSame(1, Auditoria::where('funcion_realizada', 'Reenvío de solicitud al comité')->count());
    }

    public function test_la_primera_vista_previa_no_pide_confirmar_reenvio(): void
    {
        $this->get(route('solicitudes.comite.previa', $this->solicitud()))->assertDontSee('Confirmo el reenvío');
    }

    public function test_un_segundo_envio_inmediato_se_rechaza_aunque_se_confirme_el_reenvio(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();

        $this->enviar($solicitud)->assertSessionHas('status');
        $this->enviar($solicitud, confirmarReenvio: true)->assertSessionHas('error');

        $this->assertStringContainsString('doble clic', session('error'));
        Mail::assertSent(SolicitudAlComiteMail::class, 1);
        $this->assertSame(1, EnvioComite::count());
    }

    // ───────── Fallos del correo ─────────

    public function test_si_el_transporte_falla_no_hay_registro_ni_marca_y_queda_en_la_auditoria(): void
    {
        Mail::extend('falla', fn (array $config) => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('El servidor rechazó la conexión');
            }

            public function __toString(): string
            {
                return 'falla';
            }
        });
        config(['mail.mailers.falla' => ['transport' => 'falla'], 'mail.default' => 'falla']);
        $solicitud = $this->solicitud();

        $this->enviar($solicitud)
            ->assertRedirect(route('solicitudes.comite.previa', $solicitud))
            ->assertSessionHas('error');

        $this->assertStringContainsString('NO quedó marcada como enviada', session('error'));
        $this->assertSame(0, EnvioComite::count());

        $auditoria = Auditoria::sole();
        $this->assertSame('Envío de solicitud al comité fallido', $auditoria->funcion_realizada);
        $this->assertStringContainsString("Solicitud #{$solicitud->id_solicitud}", $auditoria->detalle);
        $this->assertStringContainsString('TransportException', $auditoria->detalle);
        $this->assertStringNotContainsString('rechazó la conexión', $auditoria->detalle);

        $this->get(route('solicitudes.show', $solicitud))->assertDontSee('Enviada al comité');
    }

    // ───────── Configuración ─────────

    public function test_sin_destinatarios_configurados_se_bloquea_con_un_mensaje_claro(): void
    {
        Mail::fake();
        config(['comite.destinatarios' => []]);
        $solicitud = $this->solicitud();

        $mensaje = 'No hay destinatarios del comité configurados (COMITE_DESTINATARIOS): configúralos antes de enviar.';
        $this->get(route('solicitudes.comite.previa', $solicitud))->assertSee($mensaje);
        $this->enviar($solicitud)->assertSessionHas('error', $mensaje);

        Mail::assertNothingSent();
        $this->assertSame(0, EnvioComite::count());
    }

    public function test_en_produccion_con_log_o_array_el_envio_se_rechaza_como_correo_no_configurado(): void
    {
        Mail::fake();
        $this->app->detectEnvironment(fn () => 'production');
        config(['comite.destinatarios' => ['comite@institucion.edu.co'], 'comite.copia' => [], 'comite.remitente.direccion' => 'bienestar@institucion.edu.co']);
        $solicitud = $this->solicitud();

        foreach (['log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);

            $this->enviar($solicitud)->assertSessionHas('error');
            $this->assertStringContainsString('Correo no configurado', session('error'));
        }

        Mail::assertNothingSent();
        $this->assertSame(0, EnvioComite::count());
        $this->get(route('solicitudes.show', $solicitud))->assertDontSee('Enviada al comité');
    }

    public function test_en_produccion_con_direcciones_provisionales_se_rechaza_y_con_las_reales_se_envia(): void
    {
        Mail::fake();
        $this->app->detectEnvironment(fn () => 'production');
        $this->usarCorreoReal();
        $solicitud = $this->solicitud();

        $this->enviar($solicitud)->assertSessionHas('error');
        $this->assertStringContainsString('provisionales (.test)', session('error'));
        Mail::assertNothingSent();

        config(['comite.destinatarios' => ['comite@institucion.edu.co'], 'comite.copia' => ['bienestar@institucion.edu.co'], 'comite.remitente.direccion' => 'bienestar@institucion.edu.co']);
        $this->enviar($solicitud)->assertSessionHas('status', 'Solicitud enviada al comité correctamente.');

        Mail::assertSent(SolicitudAlComiteMail::class, 1);
        $this->assertFalse(EnvioComite::sole()->simulado);
    }

    public function test_el_correo_es_real_solo_con_un_transporte_que_entrega(): void
    {
        $servicio = app(EnvioComiteService::class);

        foreach (['log' => false, 'array' => false, 'smtp' => true, 'ses' => true, 'failover' => true] as $mailer => $esperado) {
            config(['mail.default' => $mailer]);

            $this->assertSame($esperado, $servicio->esCorreoReal(), $mailer);
        }
    }

    public function test_el_asunto_y_los_textos_usan_marcadores_y_el_asunto_no_lleva_saltos_de_linea(): void
    {
        Mail::fake();
        config([
            'comite.asunto' => "Solicitud {solicitud}\r\nBcc: otro@example.test {empleado} ({cedula}) {monto}",
            'comite.texto_introduccion' => 'Introducción para {empleado}.',
            'comite.texto_cierre' => 'Cierre de la solicitud {solicitud}.',
        ]);
        $solicitud = $this->solicitud();

        $this->enviar($solicitud);

        Mail::assertSent(SolicitudAlComiteMail::class, function (SolicitudAlComiteMail $correo) use ($solicitud): bool {
            $this->assertSame("Solicitud {$solicitud->id_solicitud} Bcc: otro@example.test Persona Ficticia Uno (1234567890) \$4.000.000", $correo->asunto);
            $this->assertStringNotContainsString("\n", $correo->asunto);
            $this->assertStringContainsString('Introducción para Persona Ficticia Uno.', $correo->render());
            $this->assertStringContainsString("Cierre de la solicitud {$solicitud->id_solicitud}.", $correo->render());

            return true;
        });
    }

    // ───────── Cuerpo ─────────

    public function test_el_cuerpo_trae_empleado_cedula_modalidad_monto_plazo_tasa_cuota_y_capacidad_pero_no_el_salario(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();
        $calculo = app(CalculoFinancieroService::class);
        $cuota = $calculo->cuotaFija(4_000_000, $calculo->tasaPeriodicaQuincenal(5.1), 72);

        $this->enviar($solicitud);

        Mail::assertSent(SolicitudAlComiteMail::class, function (SolicitudAlComiteMail $correo) use ($solicitud, $cuota): bool {
            $cuerpo = $correo->render();

            foreach (['Persona Ficticia Uno', '1234567890', 'Educación', '$4.000.000,00', '72 quincenas', '5,1000 % E.A.', '$'.number_format($cuota, 2, ',', '.'), '$'.number_format($cuota * 2, 2, ',', '.'), '$2.000.000,00'] as $esperado) {
                $this->assertStringContainsString($esperado, $cuerpo);
            }

            $this->assertStringNotContainsString('4.321.000', $cuerpo);
            $this->assertStringNotContainsString('4321000', $cuerpo);
            $this->assertSame($cuota, $correo->datos['cuota_quincenal']);
            $this->assertArrayNotHasKey('salario', $correo->datos);
            $this->assertSame($solicitud->id_solicitud, $correo->datos['id_solicitud']);

            return true;
        });

        $datos = EnvioComite::sole()->datos;
        $this->assertSame(5.1, $datos['tasa_total']);
        $this->assertSame($cuota, $datos['cuota_quincenal']);
        $this->assertSame(2_000_000.0, (float) $datos['capacidad_total']);
        $this->assertArrayNotHasKey('salario', $datos);
    }

    public function test_la_capacidad_del_cuerpo_resta_los_prestamos_vigentes(): void
    {
        Mail::fake();
        Prestamo::factory()->create(['cedula_empleado' => $this->empleado->cedula, 'cuota_periodica' => 100_000]);
        $solicitud = $this->solicitud();

        $this->enviar($solicitud);

        $datos = EnvioComite::sole()->datos;
        $this->assertSame(200_000.0, (float) $datos['capacidad_comprometida']);
        $this->assertSame(1_800_000.0, (float) $datos['capacidad_disponible']);
    }

    public function test_las_observaciones_de_incumplimientos_van_en_el_cuerpo_y_el_interruptor_las_quita(): void
    {
        Mail::fake();
        // 17,5 SMMLV de cuantía son 22.750.000: este monto la supera.
        $solicitud = $this->solicitud(monto: 30_000_000);

        $this->enviar($solicitud);
        Mail::assertSent(SolicitudAlComiteMail::class, function (SolicitudAlComiteMail $correo): bool {
            $this->assertStringContainsString('Observaciones del sistema', $correo->render());
            $this->assertStringContainsString('supera la cuantía máxima permitida', $correo->render());
            $this->assertNotEmpty($correo->datos['observaciones']);

            return true;
        });

        config(['comite.incluir_observaciones' => false, 'comite.segundos_entre_envios' => 0]);
        $this->enviar($solicitud, confirmarReenvio: true);
        Mail::assertSent(SolicitudAlComiteMail::class, fn (SolicitudAlComiteMail $correo): bool => $correo->datos['observaciones'] === [] && ! str_contains($correo->render(), 'Observaciones del sistema'));
    }

    public function test_una_solicitud_sin_observaciones_no_muestra_la_seccion(): void
    {
        Mail::fake();
        $this->enviar($this->solicitud());

        Mail::assertSent(SolicitudAlComiteMail::class, fn (SolicitudAlComiteMail $correo): bool => ! str_contains($correo->render(), 'Observaciones del sistema'));
    }

    public function test_sin_tasa_registrada_el_correo_sale_y_la_tasa_y_la_cuota_figuran_como_no_disponibles(): void
    {
        Mail::fake();
        TasaInt::query()->delete();
        $solicitud = $this->solicitud();

        $this->enviar($solicitud)->assertSessionHas('status');

        Mail::assertSent(SolicitudAlComiteMail::class, function (SolicitudAlComiteMail $correo): bool {
            $this->assertNull($correo->datos['tasa_total']);
            $this->assertNull($correo->datos['cuota_quincenal']);
            $this->assertStringContainsString('No registrada', $correo->render());
            $this->assertStringContainsString('No disponible', $correo->render());

            return true;
        });
    }

    // ───────── Adjuntos ─────────

    public function test_solo_se_adjuntan_los_documentos_seleccionados_y_nunca_los_de_otra_solicitud(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();
        $incluido = $this->documento($solicitud, 'incluido.pdf');
        $this->documento($solicitud, 'omitido.pdf');
        $ajeno = $this->documento($this->solicitud(), 'ajeno.pdf');

        $this->enviar($solicitud, [$incluido->id_documento, $ajeno->id_documento])->assertSessionHas('status');

        Mail::assertSent(SolicitudAlComiteMail::class, fn (SolicitudAlComiteMail $correo): bool => count($correo->attachments()) === 1
            && $correo->documentos->pluck('nombre_original')->all() === ['incluido.pdf']);
        $this->assertSame(['incluido.pdf'], array_column(EnvioComite::sole()->adjuntos, 'nombre'));
    }

    public function test_la_vista_previa_actualizada_solo_cuenta_los_marcados(): void
    {
        $solicitud = $this->solicitud();
        $primero = $this->documento($solicitud, 'primero.pdf', 1_048_576);
        $this->documento($solicitud, 'segundo.pdf', 2_097_152);

        $this->get(route('solicitudes.comite.previa', $solicitud))->assertSee('3,0 MB');
        $this->get(route('solicitudes.comite.previa', [$solicitud, 'actualizar' => 1, 'documentos' => [$primero->id_documento]]))
            ->assertSee('1,0 MB')
            ->assertSee('Hay documentos de la solicitud que no se adjuntarán a este correo.');
        $this->get(route('solicitudes.comite.previa', [$solicitud, 'actualizar' => 1]))->assertSee('La solicitud se enviará sin documentos adjuntos.');
    }

    public function test_enviar_sin_marcar_documentos_sale_sin_adjuntos(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();
        $this->documento($solicitud);

        $this->enviar($solicitud)->assertSessionHas('status');

        Mail::assertSent(SolicitudAlComiteMail::class, fn (SolicitudAlComiteMail $correo): bool => count($correo->attachments()) === 0
            && str_contains($correo->render(), 'sin documentos adjuntos'));
        $this->assertSame([], EnvioComite::sole()->adjuntos);
    }

    public function test_un_documento_cuyo_archivo_ya_no_existe_bloquea_el_envio(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();
        $faltante = $this->documento($solicitud, 'perdido.pdf', conArchivo: false);

        $this->get(route('solicitudes.comite.previa', $solicitud))->assertSee('Archivo no disponible')->assertSee('Falta el archivo de: perdido.pdf');
        $this->enviar($solicitud, [$faltante->id_documento])->assertSessionHas('error');
        $this->assertStringContainsString('perdido.pdf', session('error'));

        Mail::assertNothingSent();
        $this->assertSame(0, EnvioComite::count());
    }

    public function test_el_limite_de_tamano_total_se_aplica_y_es_configurable(): void
    {
        Mail::fake();
        $solicitud = $this->solicitud();
        $uno = $this->documento($solicitud, 'uno.pdf', 6 * 1024 * 1024);
        $dos = $this->documento($solicitud, 'dos.pdf', 5 * 1024 * 1024);
        $ids = [$uno->id_documento, $dos->id_documento];

        // 11 MB con el límite por defecto de 15 MB: pasa.
        $this->assertSame(15.0, (float) config('comite.adjuntos.maximo_total_mb'));
        $this->enviar($solicitud, $ids)->assertSessionHas('status');
        Mail::assertSent(SolicitudAlComiteMail::class, 1);

        config(['comite.adjuntos.maximo_total_mb' => 10, 'comite.segundos_entre_envios' => 0]);
        $this->get(route('solicitudes.comite.previa', $solicitud))->assertSee('Los adjuntos pesan 11,0 MB y el límite del correo es 10,0 MB');
        $this->enviar($solicitud, $ids, confirmarReenvio: true)->assertSessionHas('error');
        Mail::assertSent(SolicitudAlComiteMail::class, 1);

        // Justo en el límite todavía se puede.
        config(['comite.adjuntos.maximo_total_mb' => 11]);
        $this->enviar($solicitud, $ids, confirmarReenvio: true)->assertSessionHas('status');
        Mail::assertSent(SolicitudAlComiteMail::class, 2);
    }

    // ───────── Privacidad: transporte log-resumen ─────────

    public function test_el_transporte_de_resumen_registra_solo_destinatarios_asunto_y_adjuntos(): void
    {
        $registros = new class extends AbstractLogger
        {
            /** @var list<array{mensaje: string, contexto: array<string, mixed>}> */
            public array $lista = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lista[] = ['mensaje' => (string) $message, 'contexto' => $context];
            }
        };

        $correo = (new Email)
            ->from('bienestar@example.test')
            ->to(new Address('comite@example.test'))
            ->cc('bienestar@example.test')
            ->subject('Asunto de prueba')
            ->html('<p>CUERPO-CONFIDENCIAL cédula 1234567890</p>')
            ->attach('CONTENIDO-SECRETO-DEL-ADJUNTO', 'soporte.pdf', 'application/pdf');

        (new LogResumenTransport($registros))->send($correo);

        $this->assertCount(1, $registros->lista);
        $this->assertSame('Correo simulado (no se envió)', $registros->lista[0]['mensaje']);
        $this->assertSame(
            ['para' => ['comite@example.test'], 'copia' => ['bienestar@example.test'], 'asunto' => 'Asunto de prueba', 'adjuntos' => [['nombre' => 'soporte.pdf', 'bytes' => strlen('CONTENIDO-SECRETO-DEL-ADJUNTO')]]],
            $registros->lista[0]['contexto'],
        );

        $todo = json_encode($registros->lista);
        $this->assertStringNotContainsString('CONFIDENCIAL', $todo);
        $this->assertStringNotContainsString('1234567890', $todo);
        $this->assertStringNotContainsString('SECRETO', $todo);
    }

    public function test_con_el_mailer_log_el_envio_real_de_la_app_escribe_solo_el_resumen(): void
    {
        config([
            'logging.channels.correo_prueba' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'correo_prueba',
        ]);
        $solicitud = $this->solicitud();
        $this->documento($solicitud, 'soporte.pdf', 1_000, contenido: 'CONTENIDO-SECRETO-DEL-ADJUNTO');
        $idDocumento = DocumentoSolicitud::sole()->id_documento;

        $this->enviar($solicitud, [$idDocumento])->assertSessionHas('status');

        /** @var TestHandler $manejador */
        $manejador = Log::channel('correo_prueba')->getLogger()->getHandlers()[0];
        $this->assertTrue($manejador->hasInfoThatContains('Correo simulado (no se envió)'));
        $this->assertCount(1, $manejador->getRecords());

        $registro = $manejador->getRecords()[0];
        $this->assertSame(['comite@example.test', 'secretaria@example.test'], $registro->context['para']);
        $this->assertSame("Solicitud #{$solicitud->id_solicitud} — Educación — Persona Ficticia Uno", $registro->context['asunto']);
        $this->assertSame([['nombre' => 'soporte.pdf', 'bytes' => strlen('CONTENIDO-SECRETO-DEL-ADJUNTO')]], $registro->context['adjuntos']);

        $todo = json_encode($registro->context).$registro->message;
        $this->assertStringNotContainsString('SECRETO', $todo);
        $this->assertStringNotContainsString('1234567890', $todo);
        $this->assertStringNotContainsString('5,1000', $todo);
        $this->assertTrue(EnvioComite::sole()->simulado);
    }
}
