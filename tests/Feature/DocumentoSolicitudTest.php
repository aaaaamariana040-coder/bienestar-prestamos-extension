<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\DocumentoSolicitud;
use App\Models\Empleado;
use App\Models\SolicitudCredito;
use App\Models\TipoDocumento;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DocumentoSolicitudTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;

    private SolicitudCredito $solicitud;

    private TipoDocumento $tipoDocumento;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->usuario = Usuario::factory()->create();
        $this->solicitud = SolicitudCredito::factory()->create();
        $this->tipoDocumento = TipoDocumento::factory()->create(['nombre' => 'Certificado laboral']);
        $this->actingAs($this->usuario);
    }

    /**
     * @param  array<int, UploadedFile>  $archivos
     */
    private function subir(array $archivos, ?SolicitudCredito $solicitud = null): TestResponse
    {
        return $this->post(route('documentos.store', $solicitud ?? $this->solicitud), [
            'id_tipo_documento' => $this->tipoDocumento->id_tipo_documento,
            'archivos' => $archivos,
        ]);
    }

    public function test_sube_un_archivo_valido_con_nombre_interno_aleatorio_en_el_disco_privado(): void
    {
        $this->subir([UploadedFile::fake()->create('certificado laboral.pdf', 200, 'application/pdf')])
            ->assertRedirect(route('solicitudes.show', $this->solicitud))
            ->assertSessionHas('status', 'Documento subido correctamente.');

        $documento = DocumentoSolicitud::firstOrFail();

        $this->assertSame($this->solicitud->id_solicitud, $documento->id_solicitud);
        $this->assertSame($this->tipoDocumento->id_tipo_documento, $documento->id_tipo_documento);
        $this->assertSame('certificado laboral.pdf', $documento->nombre_original);
        $this->assertSame('application/pdf', $documento->tipo_mime);
        $this->assertSame($this->usuario->cedula, $documento->cedula_usu);
        $this->assertGreaterThan(0, $documento->tamano);
        $this->assertStringStartsWith("documentos-solicitud/{$this->solicitud->id_solicitud}/", $documento->ruta);
        $this->assertStringNotContainsString('certificado', $documento->ruta);
        $this->assertMatchesRegularExpression('#\.pdf$#', $documento->ruta);
        Storage::disk('local')->assertExists($documento->ruta);
    }

    public function test_sube_varios_archivos_pdf_jpg_y_png_a_la_vez(): void
    {
        $this->subir([
            UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->image('foto.jpg'),
            UploadedFile::fake()->image('escaneo.png'),
        ])->assertSessionHas('status', '3 documentos subidos correctamente.');

        $this->assertSame(3, DocumentoSolicitud::count());
        $this->assertEqualsCanonicalizing(['image/jpeg', 'image/png', 'application/pdf'], DocumentoSolicitud::pluck('tipo_mime')->all());
    }

    public function test_rechaza_un_tipo_de_archivo_no_permitido_y_no_guarda_nada(): void
    {
        $this->from(route('solicitudes.show', $this->solicitud))
            ->subir([UploadedFile::fake()->create('programa.exe', 10, 'application/x-msdownload')])
            ->assertRedirect(route('solicitudes.show', $this->solicitud))
            ->assertSessionHasErrors(['archivos.0' => '«programa.exe» no es un tipo permitido: solo se aceptan archivos PDF, JPG o PNG.']);

        $this->assertSame(0, DocumentoSolicitud::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rechaza_un_archivo_con_extension_pdf_pero_contenido_distinto(): void
    {
        $this->subir([UploadedFile::fake()->create('falso.pdf', 10, 'text/plain')])
            ->assertSessionHasErrors('archivos.0');

        $this->assertSame(0, DocumentoSolicitud::count());
    }

    public function test_rechaza_un_archivo_de_mas_de_5_mb(): void
    {
        $this->subir([UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')])
            ->assertSessionHasErrors(['archivos.0' => '«grande.pdf» supera el tamaño máximo de 5 MB.']);

        $this->subir([UploadedFile::fake()->create('justo.pdf', 5120, 'application/pdf')])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DocumentoSolicitud::count());
    }

    public function test_exige_tipo_de_documento_y_al_menos_un_archivo(): void
    {
        $this->post(route('documentos.store', $this->solicitud), [])
            ->assertSessionHasErrors([
                'id_tipo_documento' => 'Elige el tipo de documento.',
                'archivos' => 'Elige al menos un archivo.',
            ]);
    }

    public function test_rechaza_un_tipo_de_documento_inactivo(): void
    {
        $this->tipoDocumento->update(['activo' => false]);

        $this->subir([UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('id_tipo_documento');
    }

    public function test_rechaza_el_archivo_numero_16_de_la_solicitud(): void
    {
        DocumentoSolicitud::factory()->count(14)->create(['id_solicitud' => $this->solicitud->id_solicitud, 'cedula_usu' => $this->usuario->cedula]);

        $this->subir([UploadedFile::fake()->create('15.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame(15, $this->solicitud->documentos()->count());

        $this->subir([UploadedFile::fake()->create('16.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors(['archivos' => 'Una solicitud admite como máximo 15 documentos; ya tiene 15 y intentas subir 1.']);

        $this->assertSame(15, $this->solicitud->documentos()->count());
    }

    public function test_rechaza_subir_mas_de_15_archivos_en_un_solo_envio(): void
    {
        $archivos = array_map(fn (int $n): UploadedFile => UploadedFile::fake()->create("{$n}.pdf", 10, 'application/pdf'), range(1, 16));

        $this->subir($archivos)->assertSessionHasErrors('archivos');

        $this->assertSame(0, DocumentoSolicitud::count());
    }

    public function test_el_limite_es_por_solicitud(): void
    {
        DocumentoSolicitud::factory()->count(15)->create(['id_solicitud' => $this->solicitud->id_solicitud, 'cedula_usu' => $this->usuario->cedula]);
        $otra = SolicitudCredito::factory()->create();

        $this->subir([UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], $otra)->assertSessionHasNoErrors();

        $this->assertSame(1, $otra->documentos()->count());
    }

    public function test_descarga_con_sesion_devuelve_el_archivo_con_su_nombre_original(): void
    {
        $this->subir([UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf')]);
        $documento = DocumentoSolicitud::firstOrFail();

        $this->get(route('documentos.download', $documento))
            ->assertOk()
            ->assertDownload('contrato.pdf');
    }

    public function test_descarga_sin_sesion_redirige_al_login(): void
    {
        $this->subir([UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf')]);
        $documento = DocumentoSolicitud::firstOrFail();

        $this->app['auth']->logout();

        $this->get(route('documentos.download', $documento))->assertRedirect(route('login'));
        $this->post(route('documentos.store', $this->solicitud), [])->assertRedirect(route('login'));
        $this->delete(route('documentos.destroy', $documento))->assertRedirect(route('login'));
        $this->assertSame(1, DocumentoSolicitud::count());
    }

    public function test_descarga_de_un_archivo_que_ya_no_existe_da_404(): void
    {
        $documento = DocumentoSolicitud::factory()->create(['id_solicitud' => $this->solicitud->id_solicitud, 'cedula_usu' => $this->usuario->cedula]);

        $this->get(route('documentos.download', $documento))->assertNotFound();
    }

    public function test_los_archivos_no_quedan_en_el_disco_publico(): void
    {
        $this->subir([UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf')]);
        $documento = DocumentoSolicitud::firstOrFail();

        Storage::disk('public')->assertMissing($documento->ruta);
        $this->assertSame('local', config('filesystems.default'));
        $this->assertStringContainsString('storage/app/private', str_replace(DIRECTORY_SEPARATOR, '/', config('filesystems.disks.local.root')));
    }

    public function test_elimina_el_documento_y_su_archivo(): void
    {
        $this->subir([UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf')]);
        $documento = DocumentoSolicitud::firstOrFail();

        $this->delete(route('documentos.destroy', $documento))
            ->assertRedirect(route('solicitudes.show', $this->solicitud))
            ->assertSessionHas('status', 'Documento eliminado.');

        $this->assertDatabaseMissing('documentos_solicitud', ['id_documento' => $documento->id_documento]);
        Storage::disk('local')->assertMissing($documento->ruta);
    }

    public function test_la_auditoria_registra_quien_sube_y_quien_elimina(): void
    {
        $this->subir([UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf')]);
        $documento = DocumentoSolicitud::firstOrFail();

        $otroFuncionario = Usuario::factory()->create();
        $this->actingAs($otroFuncionario)->delete(route('documentos.destroy', $documento));

        $subida = Auditoria::where('funcion_realizada', 'Subida de documento de solicitud')->firstOrFail();
        $this->assertSame($this->usuario->cedula, $subida->cedula_usu);
        $this->assertStringContainsString("Solicitud #{$this->solicitud->id_solicitud}", $subida->detalle);
        $this->assertStringContainsString('Certificado laboral: contrato.pdf', $subida->detalle);

        $eliminacion = Auditoria::where('funcion_realizada', 'Eliminación de documento de solicitud')->firstOrFail();
        $this->assertSame($otroFuncionario->cedula, $eliminacion->cedula_usu);
        $this->assertStringContainsString('contrato.pdf', $eliminacion->detalle);
    }

    public function test_borrar_la_solicitud_borra_en_cascada_sus_documentos(): void
    {
        DocumentoSolicitud::factory()->count(2)->create(['id_solicitud' => $this->solicitud->id_solicitud, 'cedula_usu' => $this->usuario->cedula]);

        $this->solicitud->delete();

        $this->assertSame(0, DocumentoSolicitud::count());
    }

    public function test_el_detalle_de_la_solicitud_lista_los_documentos_con_descargar_y_eliminar(): void
    {
        $this->subir([UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf')]);
        $documento = DocumentoSolicitud::firstOrFail();

        $this->get(route('solicitudes.show', $this->solicitud))
            ->assertOk()
            ->assertSee('Documentos')
            ->assertSee('contrato.pdf')
            ->assertSee('Certificado laboral')
            ->assertSee(route('documentos.download', $documento), false)
            ->assertSee('Subir documentos');
    }

    public function test_al_crear_la_solicitud_se_pueden_adjuntar_documentos(): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->get(route('solicitudes.create'))->assertOk()->assertSee('Documentos (opcional)')->assertSee('multipart/form-data', false);

        $this->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
            'id_tipo_documento' => $this->tipoDocumento->id_tipo_documento,
            'archivos' => [UploadedFile::fake()->create('cedula.pdf', 50, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $solicitud = SolicitudCredito::where('cedula_empleado', $empleado->cedula)->firstOrFail();
        $this->assertSame(1, $solicitud->documentos()->count());
    }

    public function test_crear_la_solicitud_sin_documentos_sigue_funcionando(): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('solicitudes_credito', ['cedula_empleado' => $empleado->cedula]);
        $this->assertSame(0, DocumentoSolicitud::count());
    }

    public function test_un_archivo_invalido_al_crear_la_solicitud_no_la_crea(): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
            'id_tipo_documento' => $this->tipoDocumento->id_tipo_documento,
            'archivos' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ])->assertSessionHasErrors('archivos.0');

        $this->assertDatabaseMissing('solicitudes_credito', ['cedula_empleado' => $empleado->cedula]);
    }
}
