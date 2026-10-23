<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\TipoPrestamo;
use App\Models\Usuario;
use App\Rules\MontoColombiano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MontosFormatoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formatosValidos(): array
    {
        return [
            'puntos de miles' => ['7.500.000', '7500000.00'],
            'comas de miles' => ['7,500,000', '7500000.00'],
            'sin separadores' => ['7500000', '7500000.00'],
            'con simbolo y espacios' => ['$ 7.500.000', '7500000.00'],
            'miles con coma decimal' => ['7.500.000,50', '7500000.50'],
            'miles con punto decimal' => ['7,500,000.50', '7500000.50'],
            'miles con coma decimal y centavos' => ['7.520.507,28', '7520507.28'],
            'punto decimal sin miles' => ['7520507.28', '7520507.28'],
            'solo digitos' => ['7520507', '7520507.00'],
            'puntos de miles sin decimales' => ['7.520.507', '7520507.00'],
            'comas de miles con punto decimal' => ['7,520,507.28', '7520507.28'],
            'simbolo con miles y coma decimal' => ['$ 7.520.507,28', '7520507.28'],
            'un punto y tres digitos son miles (150.000)' => ['150.000', '150000.00'],
            'un punto y tres digitos son miles (7.500)' => ['7.500', '7500.00'],
            'mil con un punto (1.000)' => ['1.000', '1000.00'],
            'simbolo con un punto de miles' => ['$ 150.000', '150000.00'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function montosRechazados(): array
    {
        return [
            'decimal con punto (7.5)' => ['7.5'],
            'menor al minimo (500)' => ['500'],
            'decimal con coma (7,40)' => ['7,40'],
            'decimal con punto (7.40)' => ['7.40'],
            'texto' => ['abc'],
            'formato de miles invalido' => ['7.50.000'],
        ];
    }

    #[DataProvider('formatosValidos')]
    public function test_solicitud_normaliza_el_monto_escrito_con_separadores(string $escrito, string $guardado): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs(Usuario::factory()->create())->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => $escrito,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('solicitudes_credito', [
            'cedula_empleado' => $empleado->cedula,
            'monto_solicitado' => $guardado,
        ]);
    }

    #[DataProvider('montosRechazados')]
    public function test_solicitud_rechaza_montos_invalidos_o_menores_al_minimo(string $escrito): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs(Usuario::factory()->create())->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => $escrito,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertSessionHasErrors('monto_solicitado');

        $this->assertDatabaseCount('solicitudes_credito', 0);
    }

    #[DataProvider('formatosValidos')]
    public function test_abono_extraordinario_normaliza_el_monto(string $escrito, string $guardado): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 50_000_000, 'cuota_periodica' => 5_000_000]);
        // El abono se compara siempre contra el saldo de capital pendiente, así que el préstamo necesita cuotas.
        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-03-01',
            'valor_capital' => 50_000_000,
            'valor_interes' => 0,
            'valor_total' => 50_000_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), [
            'monto' => $escrito,
            'fecha_pago' => '2026-02-15',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('pagos', [
            'id_prestamo' => $prestamo->id_prestamo,
            'monto' => $guardado,
        ]);
    }

    #[DataProvider('montosRechazados')]
    public function test_abono_extraordinario_rechaza_montos_invalidos_o_menores_al_minimo(string $escrito): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 50_000_000, 'cuota_periodica' => 5_000_000]);
        // El abono se compara siempre contra el saldo de capital pendiente, así que el préstamo necesita cuotas.
        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-03-01',
            'valor_capital' => 50_000_000,
            'valor_interes' => 0,
            'valor_total' => 50_000_000,
            'estado' => EstadoCuota::Pendiente,
        ]);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), [
            'monto' => $escrito,
            'fecha_pago' => '2026-02-15',
        ])->assertSessionHasErrors('monto');

        $this->assertDatabaseCount('pagos', 0);
    }

    #[DataProvider('formatosValidos')]
    public function test_abono_de_cesantias_acepta_los_formatos_de_monto(string $escrito, string $guardado): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 50_000_000, 'cuota_periodica' => 5_000_000]);

        $this->actingAs(Usuario::factory()->create())->post(route('cesantias.store', $prestamo), [
            'anio' => 2025,
            'monto' => $escrito,
            'fecha_abono' => '2026-02-15',
        ])->assertSessionDoesntHaveErrors('monto');
    }

    #[DataProvider('montosRechazados')]
    public function test_abono_de_cesantias_rechaza_montos_invalidos_o_menores_al_minimo(string $escrito): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 50_000_000, 'cuota_periodica' => 5_000_000]);

        $this->actingAs(Usuario::factory()->create())->post(route('cesantias.store', $prestamo), [
            'anio' => 2025,
            'monto' => $escrito,
            'fecha_abono' => '2026-02-15',
        ])->assertSessionHasErrors('monto');

        $this->assertDatabaseCount('abonos_cesantias', 0);
    }

    #[DataProvider('formatosValidos')]
    public function test_smmlv_normaliza_el_valor(string $escrito, string $guardado): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create())->post(route('smmlv.store'), [
            'anio' => 2026,
            'valor' => $escrito,
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('smmlv_anual', ['anio' => 2026, 'valor' => $guardado]);
    }

    #[DataProvider('montosRechazados')]
    public function test_smmlv_rechaza_valores_invalidos_o_menores_al_minimo(string $escrito): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create())->post(route('smmlv.store'), [
            'anio' => 2026,
            'valor' => $escrito,
        ])->assertSessionHasErrors('valor');

        $this->assertDatabaseCount('smmlv_anual', 0);
    }

    private const MENSAJE_DECIMALES = 'debe escribirse con máximo dos decimales (por ejemplo 7.520.507,28).';

    private const MENSAJE_FORMATO = 'solo con números, puntos de miles y coma decimal (por ejemplo 7.520.507,28).';

    /**
     * @return array<string, array{0: string}>
     */
    public static function montosConDemasiadosDecimales(): array
    {
        return [
            'coma decimal con cinco decimales' => ['7.520,50728'],
            'punto decimal con cinco decimales y comas de miles' => ['7,520.50728'],
            'punto decimal con cinco decimales' => ['7520507.28123'],
            'miles con tres decimales' => ['7.520.507,281'],
            'cuatro digitos antes del punto y tres despues' => ['7520.507'],
            'cero a la izquierda y tres decimales' => ['0.500'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function montosIrreconocibles(): array
    {
        return [
            'texto' => ['abc'],
            'grupo de miles incompleto' => ['7.50.000'],
            'varias comas decimales' => ['12,5,5'],
            'coma final' => ['7.520,'],
        ];
    }

    private function prestamoConSaldo(float $saldo): Prestamo
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => $saldo, 'cuota_periodica' => round($saldo / 100, 2)]);
        $prestamo->cuotas()->create([
            'numero_cuota' => 1,
            'fecha_vencimiento' => '2026-03-01',
            'valor_capital' => $saldo,
            'valor_interes' => 0,
            'valor_total' => $saldo,
            'estado' => EstadoCuota::Pendiente,
        ]);

        return $prestamo;
    }

    /**
     * @param  array<string, mixed>  $errores
     */
    private function assertUnSoloError(array $errores, string $campo, string $mensaje): void
    {
        $this->assertCount(1, $errores[$campo] ?? [], "Se esperaba un único error en {$campo}: ".json_encode($errores));
        $this->assertSame($mensaje, $errores[$campo][0]);
    }

    #[DataProvider('montosConDemasiadosDecimales')]
    public function test_abono_rechaza_mas_de_dos_decimales_con_un_solo_mensaje_en_espanol(string $escrito): void
    {
        $prestamo = $this->prestamoConSaldo(100_000_000);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), ['monto' => $escrito, 'fecha_pago' => '2026-02-15'])
            ->assertSessionHasErrors('monto');

        $this->assertUnSoloError(session('errors')->getMessages(), 'monto', 'El monto '.self::MENSAJE_DECIMALES);
        $this->assertDatabaseCount('pagos', 0);
    }

    #[DataProvider('montosIrreconocibles')]
    public function test_abono_rechaza_un_formato_irreconocible_con_un_solo_mensaje_en_espanol(string $escrito): void
    {
        $prestamo = $this->prestamoConSaldo(100_000_000);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), ['monto' => $escrito, 'fecha_pago' => '2026-02-15'])
            ->assertSessionHasErrors('monto');

        $this->assertUnSoloError(session('errors')->getMessages(), 'monto', 'Escribe el monto '.self::MENSAJE_FORMATO);
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_un_monto_valido_pero_menor_al_minimo_da_el_mensaje_de_minimo_en_espanol(): void
    {
        $prestamo = $this->prestamoConSaldo(100_000_000);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), ['monto' => '500', 'fecha_pago' => '2026-02-15'])
            ->assertSessionHasErrors(['monto' => 'El campo monto debe ser al menos 1000.']);
    }

    public function test_los_errores_de_validacion_salen_en_espanol_con_los_nombres_de_los_campos(): void
    {
        $this->assertSame('es', app()->getLocale());
        $this->assertSame('es', config('app.fallback_locale'));

        $prestamo = $this->prestamoConSaldo(100_000_000);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), [])
            ->assertSessionHasErrors([
                'monto' => 'El campo monto es obligatorio.',
                'fecha_pago' => 'El campo fecha de pago es obligatorio.',
            ]);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), ['monto' => '5.000.000', 'fecha_pago' => 'no-es-fecha'])
            ->assertSessionHasErrors(['fecha_pago' => 'El campo fecha de pago debe ser una fecha válida.']);
    }

    public function test_el_idioma_espanol_trae_todas_las_reglas_de_validacion_de_laravel(): void
    {
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $es = require lang_path('es/validation.php');

        foreach (array_keys($en) as $clave) {
            $this->assertArrayHasKey($clave, $es, "Falta la traducción de validation.{$clave}");
        }
    }

    public function test_un_monto_de_solo_digitos_que_supera_el_saldo_se_rechaza_con_aviso_de_cancelacion_total(): void
    {
        $prestamo = $this->prestamoConSaldo(100_000_000);

        $this->actingAs(Usuario::factory()->create())->post(route('pagos.store-abono', $prestamo), ['monto' => '752050728', 'fecha_pago' => '2026-02-15'])
            ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'Cancelación total') && str_contains($mensaje, '100.000.000,00'));

        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('reliquidaciones', 0);
    }

    public function test_la_vista_previa_devuelve_el_error_de_formato_en_espanol(): void
    {
        $prestamo = $this->prestamoConSaldo(100_000_000);
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => '7.520,50728']))
            ->assertStatus(422)
            ->assertJsonPath('errors.monto', ['El monto '.self::MENSAJE_DECIMALES]);

        $this->actingAs($usuario)->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => 'abc']))
            ->assertStatus(422)
            ->assertJsonPath('errors.monto', ['Escribe el monto '.self::MENSAJE_FORMATO]);

        $this->actingAs($usuario)->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => '7.520.507,28']))
            ->assertOk()
            ->assertJsonMissingPath('errors');
    }

    public function test_la_vista_previa_de_un_monto_igual_o_mayor_al_saldo_avisa_cancelacion_total_sin_opciones(): void
    {
        $prestamo = $this->prestamoConSaldo(100_000_000);
        $usuario = Usuario::factory()->create();

        foreach (['752050728', '100.000.000', '100.000.000,01'] as $monto) {
            $this->actingAs($usuario)->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => $monto, 'modo_reliquidacion' => 'cuota']))
                ->assertOk()
                ->assertJson(['cancelacion_total' => true, 'supera_umbral' => false])
                ->assertJsonPath('error', fn (string $error) => str_contains($error, 'Cancelación total'))
                ->assertJsonMissingPath('plan');
        }

        $this->actingAs($usuario)->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => '99.999.999,99']))
            ->assertOk()
            ->assertJsonMissingPath('cancelacion_total');
    }

    #[DataProvider('montosConDemasiadosDecimales')]
    public function test_solicitud_rechaza_mas_de_dos_decimales_en_espanol(string $escrito): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();

        $this->actingAs(Usuario::factory()->create())->post(route('solicitudes.store'), [
            'cedula_empleado' => $empleado->cedula,
            'id_tipo' => $tipoPrestamo->id_tipo,
            'monto_solicitado' => $escrito,
            'plazo_solicitado' => 24,
            'fecha_solicitud' => '2026-01-15',
        ])->assertSessionHasErrors('monto_solicitado');

        $this->assertUnSoloError(session('errors')->getMessages(), 'monto_solicitado', 'El monto solicitado '.self::MENSAJE_DECIMALES);
        $this->assertDatabaseCount('solicitudes_credito', 0);
    }

    #[DataProvider('montosConDemasiadosDecimales')]
    public function test_smmlv_rechaza_mas_de_dos_decimales_en_espanol(string $escrito): void
    {
        $this->actingAs(Usuario::factory()->administrador()->create())->post(route('smmlv.store'), ['anio' => 2026, 'valor' => $escrito])
            ->assertSessionHasErrors('valor');

        $this->assertUnSoloError(session('errors')->getMessages(), 'valor', 'El valor '.self::MENSAJE_DECIMALES);
        $this->assertDatabaseCount('smmlv_anual', 0);
    }

    #[DataProvider('montosConDemasiadosDecimales')]
    public function test_cesantias_rechaza_mas_de_dos_decimales_en_espanol(string $escrito): void
    {
        $prestamo = Prestamo::factory()->create(['monto_aprobado' => 50_000_000, 'cuota_periodica' => 5_000_000]);

        $this->actingAs(Usuario::factory()->create())->post(route('cesantias.store', $prestamo), ['anio' => 2025, 'monto' => $escrito, 'fecha_abono' => '2026-02-15'])
            ->assertSessionHasErrors('monto');

        $this->assertUnSoloError(session('errors')->getMessages(), 'monto', 'El monto '.self::MENSAJE_DECIMALES);
        $this->assertDatabaseCount('abonos_cesantias', 0);
    }

    #[DataProvider('montosConDemasiadosDecimales')]
    public function test_simulador_rechaza_el_monto_y_la_retencion_con_mas_de_dos_decimales(string $escrito): void
    {
        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();
        $usuario = Usuario::factory()->create();
        $base = ['cedula' => $empleado->cedula, 'id_tipo' => $tipoPrestamo->id_tipo, 'monto' => '5.000.000', 'plazo' => 24];

        $this->actingAs($usuario)->getJson(route('simulador.calcular', [...$base, 'monto' => $escrito]))
            ->assertStatus(422)
            ->assertJsonPath('errors.monto', ['El monto '.self::MENSAJE_DECIMALES]);

        $this->actingAs($usuario)->getJson(route('simulador.calcular', [...$base, 'retencion' => $escrito]))
            ->assertStatus(422)
            ->assertJsonPath('errors.retencion', ['El valor de la retención en la fuente '.self::MENSAJE_DECIMALES]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizaciones(): array
    {
        return [
            'miles con coma decimal' => ['7.520.507,28', '7520507.28'],
            'punto decimal sin miles' => ['7520507.28', '7520507.28'],
            'comas de miles con punto decimal' => ['7,520,507.28', '7520507.28'],
            'solo digitos' => ['7520507', '7520507'],
            'puntos de miles' => ['7.520.507', '7520507'],
            'simbolo y espacios' => ['$ 7.520.507,28', '7520507.28'],
            'un punto y tres digitos (150.000)' => ['150.000', '150000'],
            'un punto y tres digitos (7.500)' => ['7.500', '7500'],
            'el limite de tres cifras antes del punto (999.999)' => ['999.999', '999999'],
            'coma y tres digitos son miles' => ['7,500', '7500'],
            'un punto y un decimal (7.5)' => ['7.5', '7.5'],
            'un punto y dos decimales (7.52)' => ['7.52', '7.52'],
            'coma y un decimal (7,5)' => ['7,5', '7.5'],
            'cero a la izquierda no es miles (0.500)' => ['0.500', '0.500'],
            'cuatro digitos antes del punto no es miles (7520.507)' => ['7520.507', '7520.507'],
            'cinco decimales se deja intacto' => ['7.520,50728', '7.520,50728'],
            'texto se deja intacto' => ['abc', 'abc'],
        ];
    }

    #[DataProvider('normalizaciones')]
    public function test_la_normalizacion_interpreta_cada_formato(string $escrito, string $esperado): void
    {
        $this->assertSame($esperado, MontoColombiano::normalizar($escrito));
    }

    public function test_la_normalizacion_es_estable_si_se_aplica_dos_veces(): void
    {
        foreach (['7.520.507,28', '150.000', '7.500', '7.52', '7520507', '7,520,507.28'] as $escrito) {
            $una = MontoColombiano::normalizar($escrito);

            $this->assertSame($una, MontoColombiano::normalizar($una), $escrito);
        }
    }

    public function test_la_regla_acepta_los_formatos_claros_y_rechaza_el_resto(): void
    {
        $regla = new MontoColombiano;

        foreach (['7520507', '7520507.2', '7520507.28', '0.5', '150.000', '7.500', '7.520.507,28', '7,520,507.28', '$ 7.520.507,28', '7.5', '7.52'] as $valor) {
            $this->assertTrue(Validator::make(['monto' => $valor], ['monto' => ['required', $regla]])->passes(), $valor);
        }

        foreach (['7520507.281', '7.520,50728', '7520.507', '0.500', 'abc', '7.50.000', '12,5,5'] as $valor) {
            $this->assertTrue(Validator::make(['monto' => $valor], ['monto' => ['required', $regla]])->fails(), $valor);
        }
    }

    public function test_un_punto_con_tres_digitos_se_lee_como_miles_en_el_abono_la_vista_previa_y_el_simulador(): void
    {
        $prestamo = $this->prestamoConSaldo(100_000_000);
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->post(route('pagos.store-abono', $prestamo), ['monto' => '150.000', 'fecha_pago' => '2026-02-15'])
            ->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('pagos', ['id_prestamo' => $prestamo->id_prestamo, 'monto' => '150000.00']);

        $this->actingAs($usuario)->getJson(route('pagos.vista-previa-abono', [$prestamo, 'monto' => '7.500']))
            ->assertOk()
            ->assertJsonMissingPath('errors');

        $empleado = Empleado::factory()->create();
        $tipoPrestamo = TipoPrestamo::factory()->create();
        $base = ['cedula' => $empleado->cedula, 'id_tipo' => $tipoPrestamo->id_tipo, 'monto' => '5.000.000', 'plazo' => 24];

        $this->actingAs($usuario)->getJson(route('simulador.calcular', [...$base, 'retencion' => '150.000']))
            ->assertJsonMissingValidationErrors('retencion');
    }

    public function test_un_punto_con_uno_o_dos_digitos_sigue_siendo_decimal(): void
    {
        $prestamo = $this->prestamoConSaldo(100_000_000);
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario)->post(route('pagos.store-abono', $prestamo), ['monto' => '7.52', 'fecha_pago' => '2026-02-15'])
            ->assertSessionHasErrors(['monto' => 'El campo monto debe ser al menos 1000.']);

        $this->actingAs($usuario)->post(route('pagos.store-abono', $prestamo), ['monto' => '7.5', 'fecha_pago' => '2026-02-15'])
            ->assertSessionHasErrors(['monto' => 'El campo monto debe ser al menos 1000.']);
    }
}
