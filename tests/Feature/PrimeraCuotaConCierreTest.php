<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\CalendarioQuincenalService;
use App\Services\DesembolsoService;
use App\Services\SolicitudCreditoService;
use Database\Seeders\SistemaUsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * La primera cuota depende también del cierre de novedades de nómina: es el primer
 * vencimiento (15 o último día) posterior al desembolso cuyo cierre (vencimiento menos
 * quincena.cierre_novedades_dias_antes días) es posterior al desembolso; con
 * quincena.desembolso_dia_cierre_entra, el desembolso del día del cierre todavía entra.
 */
class PrimeraCuotaConCierreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-01 10:00:00');
        $this->seed(SistemaUsuarioSeeder::class);
        config(['quincena.regla_vencimiento' => 'calendario', 'quincena.cierre_novedades_dias_antes' => 2, 'quincena.desembolso_dia_cierre_entra' => false]);
    }

    private function calendario(): CalendarioQuincenalService
    {
        return app(CalendarioQuincenalService::class);
    }

    /**
     * @return array<string, array{0: int, 1: bool, 2: string, 3: string}>
     */
    public static function desembolsosYPrimeraCuota(): array
    {
        return [
            // [días antes del cierre, el día del cierre entra, desembolso, primera cuota]
            'antes del cierre' => [2, false, '2026-10-28', '2026-10-31'],
            'día exacto del cierre, no entra' => [2, false, '2026-10-29', '2026-11-15'],
            'día exacto del cierre, entra' => [2, true, '2026-10-29', '2026-10-31'],
            'después del cierre: el ejemplo (cierre 29, desembolso 30)' => [2, false, '2026-10-30', '2026-11-15'],
            'después del cierre, con entra' => [2, true, '2026-10-30', '2026-11-15'],
            'el mismo día del vencimiento' => [2, true, '2026-10-31', '2026-11-15'],
            'vencimiento 15, cierre el 13: el 12 entra' => [2, false, '2026-11-12', '2026-11-15'],
            'vencimiento 15, cierre el 13: el 13 no entra' => [2, false, '2026-11-13', '2026-11-30'],
            'vencimiento 15, cierre el 13: el 13 entra' => [2, true, '2026-11-13', '2026-11-15'],
            'vencimiento 15, cierre el 13: el 14 pasa al último día' => [2, true, '2026-11-14', '2026-11-30'],
            'vencimiento 15, cierre el 12 (3 días): el 11 entra' => [3, false, '2026-11-11', '2026-11-15'],
            'vencimiento 15, cierre el 12 (3 días): el 12 no entra' => [3, false, '2026-11-12', '2026-11-30'],
            'cierre a 1 día: el 13 entra' => [1, false, '2026-11-13', '2026-11-15'],
            'cierre a 1 día: el 14 no entra' => [1, false, '2026-11-14', '2026-11-30'],
            'cierre a 1 día: el 14 entra con la opción' => [1, true, '2026-11-14', '2026-11-15'],
            'cierre a 3 días: el 27 de octubre entra' => [3, false, '2026-10-27', '2026-10-31'],
            'cierre a 3 días: el 28 de octubre no entra' => [3, false, '2026-10-28', '2026-11-15'],
            'febrero de 28 días: el 25 entra' => [2, false, '2027-02-25', '2027-02-28'],
            'febrero de 28 días: el 26 no entra' => [2, false, '2027-02-26', '2027-03-15'],
            'febrero de 29 días: el 26 entra' => [2, false, '2028-02-26', '2028-02-29'],
            'febrero de 29 días: el 27 no entra' => [2, false, '2028-02-27', '2028-03-15'],
            'mes de 30 días: el 27 entra' => [2, false, '2026-04-27', '2026-04-30'],
            'mes de 30 días: el 28 no entra' => [2, false, '2026-04-28', '2026-05-15'],
            'mes de 31 días: el 28 entra' => [2, false, '2026-03-28', '2026-03-31'],
            'mes de 31 días: el 29 no entra' => [2, false, '2026-03-29', '2026-04-15'],
            'fin de año: el 28 de diciembre entra' => [2, false, '2026-12-28', '2026-12-31'],
            'fin de año: el 29 de diciembre no entra' => [2, false, '2026-12-29', '2027-01-15'],
            'fin de año: el 31 de diciembre' => [2, false, '2026-12-31', '2027-01-15'],
            'inicio de año: el 13 de enero no entra' => [2, false, '2027-01-13', '2027-01-31'],
        ];
    }

    #[DataProvider('desembolsosYPrimeraCuota')]
    public function test_la_regla_de_la_primera_cuota_con_cierre(int $dias, bool $entra, string $desembolso, string $esperada): void
    {
        config(['quincena.cierre_novedades_dias_antes' => $dias, 'quincena.desembolso_dia_cierre_entra' => $entra]);

        $this->assertSame($esperada, $this->calendario()->primeraCuotaConCierre($desembolso));
    }

    public function test_las_opciones_traen_la_anterior_solo_si_sigue_siendo_posterior_al_desembolso(): void
    {
        $opciones = $this->calendario()->opcionesPrimeraCuota('2026-10-30');

        $this->assertSame(['vencimiento' => '2026-11-15', 'cierre' => '2026-11-13'], $opciones['propuesta']);
        $this->assertSame(['vencimiento' => '2026-10-31', 'cierre' => '2026-10-29'], $opciones['anterior']);
        $this->assertSame(['vencimiento' => '2026-11-30', 'cierre' => '2026-11-28'], $opciones['siguiente']);

        $this->assertNull($this->calendario()->opcionesPrimeraCuota('2026-10-31')['anterior'], 'el 31 no es posterior al desembolso del 31');
        $this->assertSame('2026-10-31', $this->calendario()->opcionesPrimeraCuota('2026-10-20')['propuesta']['vencimiento']);
        $this->assertSame('2026-10-15', $this->calendario()->opcionesPrimeraCuota('2026-10-10')['propuesta']['vencimiento']);
    }

    // ───────── Desembolso real ─────────

    private function aprobarPrestamo(): Prestamo
    {
        $modalidad = TipoPrestamo::where('nombre', 'Educación')->first();

        if ($modalidad === null) {
            $modalidad = TipoPrestamo::factory()->create(['nombre' => 'Educación', 'plazo_maximo' => 480]);
            SmmlvAnual::firstOrCreate(['anio' => 2026], ['valor' => 1_300_000]);
            CuantiaSmmlv::factory()->create(['id_tipo' => $modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 100]);
            TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => 2026, 'tasa_anual' => 4.85]);
            PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => 2026, 'monto_asignado' => 500_000_000]);
        }

        $empleado = Empleado::factory()->create(['salario' => 3_000_000, 'fecha_vinculacion' => '2020-01-15']);
        $solicitud = SolicitudCredito::factory()->create([
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-03-01',
        ]);

        return app(SolicitudCreditoService::class)->aprobar($solicitud, Usuario::factory()->administrador()->create());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function desembolsar(Prestamo $prestamo, string $fecha, array $extra = []): void
    {
        app(DesembolsoService::class)->registrar($prestamo, ['numero_tramo' => 1, 'fecha' => $fecha, ...$extra], Usuario::factory()->create());
    }

    public function test_el_desembolso_usa_la_primera_cuota_propuesta_por_el_cierre(): void
    {
        $prestamo = $this->aprobarPrestamo();

        $this->desembolsar($prestamo, '2026-10-30');

        $prestamo->refresh();
        $this->assertSame('2026-11-15', $prestamo->inicio_primera_cuota->toDateString());
        $this->assertSame('2026-11-15', $prestamo->cuotas()->orderBy('numero_cuota')->first()->fecha_vencimiento->toDateString());
        $this->assertNull($prestamo->motivo_primera_cuota);
    }

    public function test_el_desembolso_del_dia_del_cierre_depende_de_la_configuracion(): void
    {
        $sinEntrar = $this->aprobarPrestamo();
        $this->desembolsar($sinEntrar, '2026-10-29');
        $this->assertSame('2026-11-15', $sinEntrar->fresh()->inicio_primera_cuota->toDateString());

        config(['quincena.desembolso_dia_cierre_entra' => true]);
        $entrando = $this->aprobarPrestamo();
        $this->desembolsar($entrando, '2026-10-29');
        $this->assertSame('2026-10-31', $entrando->fresh()->inicio_primera_cuota->toDateString());
    }

    public function test_elegir_otra_quincena_sin_motivo_se_rechaza_y_no_guarda_nada(): void
    {
        $prestamo = $this->aprobarPrestamo();

        foreach (['siguiente', 'anterior'] as $opcion) {
            try {
                $this->desembolsar($prestamo, '2026-10-30', ['primera_cuota_opcion' => $opcion, 'motivo_primera_cuota' => '  ']);
                $this->fail('Se esperaba que se exigiera el motivo.');
            } catch (RuntimeException $e) {
                $this->assertSame('Elegir una quincena distinta a la propuesta exige indicar el motivo.', $e->getMessage());
            }
        }

        $this->assertDatabaseCount('desembolsos', 0);
        $this->assertTrue($prestamo->fresh()->pendiente_desembolso);
    }

    public function test_se_puede_elegir_la_anterior_o_la_siguiente_con_motivo_y_queda_en_auditoria(): void
    {
        $anterior = $this->aprobarPrestamo();
        $this->desembolsar($anterior, '2026-10-30', ['primera_cuota_opcion' => 'anterior', 'motivo_primera_cuota' => 'Se alcanzó a reportar de última hora']);
        $anterior->refresh();

        $this->assertSame('2026-10-31', $anterior->inicio_primera_cuota->toDateString());
        $this->assertSame('Se alcanzó a reportar de última hora', $anterior->motivo_primera_cuota);

        $detalle = Auditoria::where('funcion_realizada', 'Registro de desembolso')->orderByDesc('id_auditoria')->first()->detalle;
        $this->assertStringContainsString('primera cuota: 2026-10-31', $detalle);
        $this->assertStringContainsString('propuesta: 2026-11-15', $detalle);
        $this->assertStringContainsString('cierre de novedades: 2026-10-29', $detalle);
        $this->assertStringContainsString('criterio: cierre 2 día(s) antes del vencimiento; el desembolso el día del cierre no entra', $detalle);
        $this->assertStringContainsString('quincena elegida por el funcionario (anterior): Se alcanzó a reportar de última hora', $detalle);

        $siguiente = $this->aprobarPrestamo();
        $this->desembolsar($siguiente, '2026-10-30', ['primera_cuota_opcion' => 'siguiente', 'motivo_primera_cuota' => 'Pedido del empleado']);

        $this->assertSame('2026-11-30', $siguiente->fresh()->inicio_primera_cuota->toDateString());
    }

    public function test_no_se_permite_la_anterior_si_ya_no_es_posterior_al_desembolso(): void
    {
        $prestamo = $this->aprobarPrestamo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se puede elegir la quincena anterior');

        $this->desembolsar($prestamo, '2026-10-31', ['primera_cuota_opcion' => 'anterior', 'motivo_primera_cuota' => 'Prueba']);
    }

    public function test_aceptar_la_propuesta_deja_la_auditoria_sin_quincena_elegida(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $this->desembolsar($prestamo, '2026-10-30', ['primera_cuota_opcion' => 'propuesta', 'motivo_primera_cuota' => 'se ignora']);

        $detalle = Auditoria::where('funcion_realizada', 'Registro de desembolso')->sole()->detalle;
        $this->assertStringContainsString('primera cuota: 2026-11-15 (propuesta: 2026-11-15', $detalle);
        $this->assertStringNotContainsString('quincena elegida', $detalle);
        $this->assertNull($prestamo->fresh()->motivo_primera_cuota);
    }

    public function test_los_prestamos_antiguos_no_cambian(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $prestamo->update(['pendiente_desembolso' => false]);
        $antes = $prestamo->cuotas()->orderBy('numero_cuota')->pluck('fecha_vencimiento')->map->toDateString()->all();

        $this->desembolsar($prestamo, '2026-10-30', ['primera_cuota_opcion' => 'siguiente']);

        $this->assertNull($prestamo->fresh()->inicio_primera_cuota);
        $this->assertSame($antes, $prestamo->cuotas()->orderBy('numero_cuota')->pluck('fecha_vencimiento')->map->toDateString()->all());
    }

    public function test_el_segundo_tramo_no_mueve_la_primera_cuota(): void
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => 'Vivienda — construcción', 'plazo_maximo' => 480]);
        $prestamo = $this->aprobarPrestamo();
        $prestamo->update(['id_tipo' => $modalidad->id_tipo]);

        $this->desembolsar($prestamo, '2026-10-30');
        app(DesembolsoService::class)->registrar($prestamo->fresh(), ['numero_tramo' => 2, 'fecha' => '2026-12-20', 'inspeccion_aprobada' => true], Usuario::factory()->create());

        $this->assertSame('2026-11-15', $prestamo->fresh()->inicio_primera_cuota->toDateString());
    }

    // ───────── Pantalla ─────────

    public function test_la_pantalla_muestra_la_propuesta_y_el_cierre_antes_de_confirmar(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $this->travelTo('2026-10-30 10:00:00');

        $this->actingAs(Usuario::factory()->create())->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertSee('Primera cuota propuesta: <strong data-propuesta-vencimiento>15/11/2026</strong>. Cierre de novedades de esa quincena: <span data-propuesta-cierre>13/11/2026</span>.', false)
            ->assertSee('Quincena anterior:')
            ->assertSee('Quincena siguiente:')
            ->assertSee('name="motivo_primera_cuota"', false);
    }

    public function test_el_endpoint_devuelve_la_propuesta_para_otra_fecha(): void
    {
        $prestamo = $this->aprobarPrestamo();

        $this->actingAs(Usuario::factory()->create())
            ->getJson(route('desembolsos.primera-cuota', [$prestamo, 'fecha' => '2026-10-30']))
            ->assertOk()
            ->assertJsonPath('propuesta.vencimiento_legible', '15/11/2026')
            ->assertJsonPath('propuesta.cierre_legible', '13/11/2026')
            ->assertJsonPath('anterior.vencimiento', '2026-10-31')
            ->assertJsonPath('siguiente.vencimiento', '2026-11-30');

        $this->actingAs(Usuario::factory()->create())
            ->getJson(route('desembolsos.primera-cuota', [$prestamo, 'fecha' => '2026-10-31']))
            ->assertJsonPath('anterior', null);
    }

    public function test_el_desembolso_por_pantalla_rechaza_otra_quincena_sin_motivo_y_la_acepta_con_motivo(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $funcionario = Usuario::factory()->create();

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), ['numero_tramo' => 1, 'fecha' => '2026-10-30', 'primera_cuota_opcion' => 'siguiente'])
            ->assertSessionHas('error', 'Elegir una quincena distinta a la propuesta exige indicar el motivo.');
        $this->assertTrue($prestamo->fresh()->pendiente_desembolso);

        $this->actingAs($funcionario)->post(route('desembolsos.store', $prestamo), ['numero_tramo' => 1, 'fecha' => '2026-10-30', 'primera_cuota_opcion' => 'siguiente', 'motivo_primera_cuota' => 'Pedido del empleado'])
            ->assertRedirect(route('prestamos.show', $prestamo));

        $this->actingAs($funcionario)->get(route('prestamos.show', $prestamo))
            ->assertSee('Cierre de novedades de esa quincena: 28/11/2026.')
            ->assertSee('Quincena elegida por el funcionario: Pedido del empleado');
    }

    public function test_el_prestamo_sin_quincena_elegida_no_muestra_la_nota(): void
    {
        $prestamo = $this->aprobarPrestamo();
        $this->desembolsar($prestamo, '2026-10-30');

        $this->actingAs(Usuario::factory()->create())->get(route('prestamos.show', $prestamo))
            ->assertSee('Cierre de novedades de esa quincena: 13/11/2026.')
            ->assertDontSee('Quincena elegida por el funcionario');
    }
}
