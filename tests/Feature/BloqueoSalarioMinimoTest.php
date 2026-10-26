<?php

namespace Tests\Feature;

use App\Models\CuantiaSmmlv;
use App\Models\Empleado;
use App\Models\PresupuestoAnual;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\TasaInt;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Services\SimuladorCreditoService;
use App\Services\SolicitudCreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Quien devenga el SMMLV o menos no puede pedir un crédito: lo bloquea el
 * sistema al registrar la solicitud, al aprobarla y en el simulador.
 */
class BloqueoSalarioMinimoTest extends TestCase
{
    use RefreshDatabase;

    private const SMMLV_2025 = 1_423_500;

    private const SMMLV_2026 = 1_750_905;

    private const MENSAJE = 'El salario del empleado es igual o menor al salario mínimo (SMMLV). A quien devenga el mínimo no se le puede descontar por nómina.';

    private const MENSAJE_REGISTRO = 'No se puede registrar: el salario del empleado es igual o menor al salario mínimo (SMMLV). A quien devenga el mínimo no se le puede descontar por nómina.';

    protected function setUp(): void
    {
        parent::setUp();

        SmmlvAnual::factory()->create(['anio' => 2025, 'valor' => self::SMMLV_2025]);
        SmmlvAnual::factory()->create(['anio' => 2026, 'valor' => self::SMMLV_2026]);

        if (now()->year > 2026) {
            SmmlvAnual::factory()->create(['anio' => now()->year, 'valor' => self::SMMLV_2026]);
        }
    }

    private function modalidad(int ...$anios): TipoPrestamo
    {
        $modalidad = TipoPrestamo::factory()->create(['nombre' => 'Educación', 'plazo_maximo' => 72]);
        CuantiaSmmlv::factory()->create(['id_tipo' => $modalidad->id_tipo, 'tramo_desde_smmlv' => 0, 'tramo_hasta_smmlv' => null, 'cuantia_maxima_smmlv' => 100]);

        foreach (array_unique($anios ?: [2026, now()->year]) as $anio) {
            TasaInt::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'tasa_anual' => 4.85]);
            PresupuestoAnual::factory()->create(['id_tipo' => $modalidad->id_tipo, 'anio' => $anio, 'monto_asignado' => 500_000_000]);
        }

        return $modalidad;
    }

    private function empleado(float $salario): Empleado
    {
        return Empleado::factory()->create(['salario' => $salario, 'capacidad_endeudamiento' => 4_000_000, 'fecha_vinculacion' => now()->subYears(5)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosDeSolicitud(Empleado $empleado, TipoPrestamo $modalidad, string $fecha = '2026-01-15'): array
    {
        return [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $modalidad->id_tipo,
            'monto_solicitado' => 2_000_000,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => $fecha,
        ];
    }

    private function registrar(Empleado $empleado, TipoPrestamo $modalidad, string $fecha = '2026-01-15'): TestResponse
    {
        return $this->actingAs(Usuario::factory()->create())->post(route('solicitudes.store'), $this->datosDeSolicitud($empleado, $modalidad, $fecha));
    }

    /**
     * Lo que ya existía en la base antes de la regla: se crea sin pasar por el registro.
     */
    private function solicitudPreexistente(Empleado $empleado, TipoPrestamo $modalidad, string $fecha = '2026-01-15'): SolicitudCredito
    {
        return SolicitudCredito::factory()->create($this->datosDeSolicitud($empleado, $modalidad, $fecha));
    }

    /**
     * @return list<string>
     */
    private function codigos(SolicitudCredito $solicitud): array
    {
        return array_column(app(SolicitudCreditoService::class)->incumplimientos($solicitud), 'codigo');
    }

    // ───────── Registro de la solicitud ─────────

    public function test_un_salario_menor_al_minimo_bloquea_el_registro_con_el_mensaje(): void
    {
        $this->registrar($this->empleado(self::SMMLV_2026 - 1), $this->modalidad())
            ->assertSessionHasErrors(['cedula_empleado' => self::MENSAJE_REGISTRO]);

        $this->assertSame(0, SolicitudCredito::count());
    }

    public function test_un_salario_igual_al_minimo_bloquea_el_registro_borde_exacto(): void
    {
        $this->registrar($this->empleado(self::SMMLV_2026), $this->modalidad())
            ->assertSessionHasErrors(['cedula_empleado' => self::MENSAJE_REGISTRO]);

        $this->assertSame(0, SolicitudCredito::count());
    }

    public function test_un_salario_apenas_por_encima_del_minimo_se_registra(): void
    {
        $modalidad = $this->modalidad();

        $this->registrar($this->empleado(self::SMMLV_2026 + 1), $modalidad)->assertSessionHasNoErrors()->assertRedirect();
        $this->registrar($this->empleado(self::SMMLV_2026 + 0.01), $modalidad)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2, SolicitudCredito::count());
    }

    public function test_un_salario_mayor_al_minimo_se_registra(): void
    {
        $this->registrar($this->empleado(3_000_000), $this->modalidad())->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, SolicitudCredito::count());
    }

    public function test_se_compara_con_el_smmlv_del_ano_de_la_solicitud_y_no_con_el_de_otro_ano(): void
    {
        // 1.600.000 supera el SMMLV de 2025 (1.423.500) pero no alcanza el de 2026 (1.750.905).
        $empleado = $this->empleado(1_600_000);
        $modalidad = $this->modalidad(2025, 2026);

        $this->registrar($empleado, $modalidad, '2025-06-10')->assertSessionHasNoErrors()->assertRedirect();
        $this->registrar($empleado, $modalidad, '2026-01-15')->assertSessionHasErrors(['cedula_empleado' => self::MENSAJE_REGISTRO]);

        $this->assertSame(1, SolicitudCredito::count());
        $this->assertSame('2025-06-10', SolicitudCredito::firstOrFail()->fecha_solicitud->toDateString());
    }

    public function test_sin_salario_registrado_no_se_bloquea_por_esta_regla(): void
    {
        $this->registrar($this->empleado(0), $this->modalidad())->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, SolicitudCredito::count());
    }

    public function test_sin_smmlv_del_ano_no_se_bloquea_el_registro_por_esta_regla(): void
    {
        $this->registrar($this->empleado(1_000_000), $this->modalidad(2030), '2030-03-01')->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, SolicitudCredito::count());
    }

    // ───────── Aprobación de solicitudes que ya existían ─────────

    public function test_la_aprobacion_de_una_solicitud_preexistente_queda_bloqueada(): void
    {
        $solicitud = $this->solicitudPreexistente($this->empleado(self::SMMLV_2026), $this->modalidad());

        $this->assertContains('salario_minimo', $this->codigos($solicitud));
        $this->assertContains(self::MENSAJE, app(SolicitudCreditoService::class)->validar($solicitud));

        $this->actingAs(Usuario::factory()->administrador()->create())->patch(route('solicitudes.aprobar', $solicitud));

        $this->assertSame(0, $solicitud->prestamo()->count());
    }

    public function test_un_salario_apenas_por_encima_no_tiene_el_incumplimiento_y_se_aprueba(): void
    {
        $solicitud = $this->solicitudPreexistente($this->empleado(self::SMMLV_2026 + 1), $this->modalidad());

        $this->assertNotContains('salario_minimo', $this->codigos($solicitud));

        $this->actingAs(Usuario::factory()->administrador()->create())->patch(route('solicitudes.aprobar', $solicitud));

        $this->assertSame(1, $solicitud->prestamo()->count());
    }

    public function test_sin_salario_o_sin_smmlv_no_aparece_el_incumplimiento_y_se_conserva_sin_smmlv(): void
    {
        $modalidad = $this->modalidad();
        $sinSalario = $this->solicitudPreexistente($this->empleado(0), $modalidad);
        $sinSmmlv = $this->solicitudPreexistente($this->empleado(1_000_000), $modalidad, '2031-01-15');

        $this->assertNotContains('salario_minimo', $this->codigos($sinSalario));

        $this->assertContains('sin_smmlv', $this->codigos($sinSmmlv));
        $this->assertNotContains('salario_minimo', $this->codigos($sinSmmlv));
    }

    // ───────── Simulador ─────────

    public function test_el_simulador_dice_no_apto_para_quien_devenga_el_minimo(): void
    {
        $modalidad = $this->modalidad();
        $servicio = app(SimuladorCreditoService::class);

        foreach ([self::SMMLV_2026, self::SMMLV_2026 - 1] as $salario) {
            $empleado = $this->empleado($salario);

            $evaluacion = $servicio->evaluarModalidad($empleado, $modalidad);
            $this->assertFalse($evaluacion['puede']);
            $this->assertSame('salario_minimo', $evaluacion['motivo']);
            $this->assertSame(self::MENSAJE, $evaluacion['mensaje']);

            $this->assertSame([self::MENSAJE], $servicio->simular($empleado, $modalidad, 20_000_000, 48)['errores']);
        }
    }

    public function test_el_simulador_no_ofrece_crear_la_solicitud_si_devenga_el_minimo(): void
    {
        $modalidad = $this->modalidad();
        $empleado = $this->empleado(self::SMMLV_2026);
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)
            ->getJson(route('simulador.calcular', ['cedula' => $empleado->cedula, 'id_tipo' => $modalidad->id_tipo, 'monto' => '20.000.000', 'plazo' => 48]))
            ->assertStatus(422)
            ->assertJsonPath('errores.0', self::MENSAJE)
            ->assertJsonMissingPath('html')
            ->assertJsonMissingPath('html_impresion');

        $this->actingAs($usuario)
            ->getJson(route('simulador.modalidad', [$empleado->cedula, $modalidad->id_tipo]))
            ->assertOk()
            ->assertJsonPath('puede', false)
            ->assertJsonPath('motivo', 'salario_minimo');
    }

    public function test_el_simulador_funciona_con_un_salario_apenas_por_encima_del_minimo(): void
    {
        $modalidad = $this->modalidad();
        $empleado = $this->empleado(self::SMMLV_2026 + 1);

        $this->actingAs(Usuario::factory()->create())
            ->getJson(route('simulador.calcular', ['cedula' => $empleado->cedula, 'id_tipo' => $modalidad->id_tipo, 'monto' => '2.000.000', 'plazo' => 24]))
            ->assertOk()
            ->assertJsonStructure(['html', 'encabezado']);

        $this->assertNotSame('salario_minimo', app(SimuladorCreditoService::class)->evaluarModalidad($empleado, $modalidad)['motivo']);
    }

    public function test_el_simulador_sin_salario_o_sin_smmlv_conserva_su_comportamiento(): void
    {
        $modalidad = $this->modalidad();
        $servicio = app(SimuladorCreditoService::class);

        $this->assertSame(['El salario del empleado debe ser mayor que cero.'], $servicio->simular($this->empleado(0), $modalidad, 2_000_000, 24)['errores']);

        SmmlvAnual::where('anio', now()->year)->delete();
        $sinSmmlv = $servicio->simular($this->empleado(1_000_000), $modalidad, 2_000_000, 24);

        $this->assertNotContains(self::MENSAJE, $sinSmmlv['errores']);
        $this->assertContains('No se ha registrado el valor del SMMLV para el año '.now()->year.'.', $sinSmmlv['errores']);
    }
}
