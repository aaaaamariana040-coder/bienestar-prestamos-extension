<?php

namespace Tests\Feature;

use App\Enums\EstadoCuota;
use App\Enums\EstadoPrestamo;
use App\Models\Cuota;
use App\Models\Pago;
use App\Models\Prestamo;
use App\Models\ProcesoQuincena;
use App\Models\Usuario;
use App\Services\QuincenaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvisoCuotaExcluidaTest extends TestCase
{
    use RefreshDatabase;

    private const ANIO = 2026;

    private const QUINCENA = 19;

    private const AVISO_POR_DEFECTO = 'Esta cuota no se descontó por nómina. Debe pagarse directamente en: la dependencia que indique Bienestar Social.';

    private Usuario $funcionario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00:00');
        config(['quincena.regla_vencimiento' => 'calendario', 'quincena.modo_marcado' => 'confirmar']);

        $this->funcionario = Usuario::factory()->create();
    }

    public function test_el_valor_por_defecto_del_aviso_y_del_lugar_de_pago(): void
    {
        $this->assertSame(self::AVISO_POR_DEFECTO, app(QuincenaService::class)->avisoCuotaExcluida());
    }

    public function test_el_texto_y_el_lugar_vienen_de_la_configuracion(): void
    {
        config([
            'quincena.aviso_cuota_excluida' => 'Sin descuento de nómina; pague en {lugar}.',
            'quincena.aviso_cuota_excluida_lugar' => 'la oficina de Tesorería',
        ]);

        $this->assertSame('Sin descuento de nómina; pague en la oficina de Tesorería.', app(QuincenaService::class)->avisoCuotaExcluida());
    }

    public function test_el_prestamo_muestra_el_aviso_solo_en_la_cuota_excluida(): void
    {
        [$prestamo] = $this->prestamoConDosCuotasYUnaExcluida();

        $respuesta = $this->actingAs($this->funcionario)->get(route('prestamos.show', $prestamo))->assertOk();

        $this->assertSame(1, substr_count($respuesta->getContent(), 'aviso-cuota-excluida'));
        $respuesta->assertSee(self::AVISO_POR_DEFECTO)->assertSee('Licencia no remunerada');
    }

    public function test_el_prestamo_no_muestra_aviso_sin_cuotas_excluidas(): void
    {
        $prestamo = $this->prestamoVigente();
        $this->cuota($prestamo, 1, '2026-10-10');

        $this->actingAs($this->funcionario)->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertDontSee('aviso-cuota-excluida')
            ->assertDontSee('Esta cuota no se descontó por nómina');
    }

    public function test_el_aviso_deja_de_mostrarse_cuando_la_cuota_se_paga(): void
    {
        [$prestamo, $excluida] = $this->prestamoConDosCuotasYUnaExcluida();

        $excluida->update(['estado' => EstadoCuota::Pagada]);

        $this->actingAs($this->funcionario)->get(route('prestamos.show', $prestamo))
            ->assertOk()
            ->assertDontSee('aviso-cuota-excluida');
    }

    public function test_el_aviso_cambia_al_cambiar_la_configuracion(): void
    {
        [$prestamo] = $this->prestamoConDosCuotasYUnaExcluida();
        config(['quincena.aviso_cuota_excluida_lugar' => 'la Caja de Bienestar']);

        $this->actingAs($this->funcionario)->get(route('prestamos.show', $prestamo))
            ->assertSee('Debe pagarse directamente en: la Caja de Bienestar.')
            ->assertDontSee('la dependencia que indique Bienestar Social');
    }

    public function test_el_informe_de_la_quincena_confirmada_muestra_el_aviso_de_las_excluidas(): void
    {
        [, , $proceso] = $this->prestamoConDosCuotasYUnaExcluida();

        $this->actingAs($this->funcionario)->get(route('quincenas.proceso', $proceso))
            ->assertOk()
            ->assertSee(self::AVISO_POR_DEFECTO);
    }

    public function test_el_informe_de_una_quincena_sin_excluidas_no_muestra_el_aviso(): void
    {
        $cuota = $this->cuota($this->prestamoVigente(), 1, '2026-10-10');
        $proceso = $this->confirmar([$cuota]);

        $this->actingAs($this->funcionario)->get(route('quincenas.proceso', $proceso))
            ->assertOk()
            ->assertDontSee('aviso-cuota-excluida');
    }

    public function test_la_vista_previa_solo_lleva_el_aviso_de_las_cuotas_marcadas_como_excluidas(): void
    {
        $cuota = $this->cuota($this->prestamoVigente(), 1, '2026-10-10');
        config(['quincena.aviso_cuota_excluida_lugar' => 'la ventanilla 3']);

        $this->actingAs($this->funcionario)->get(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertOk()
            ->assertSee('data-aviso="Esta cuota no se descontó por nómina. Debe pagarse directamente en: la ventanilla 3."', false)
            ->assertDontSee('<div class="small text-warning-emphasis mt-1 aviso-cuota-excluida">', false);

        $this->actingAs($this->funcionario)
            ->withSession(['_old_input' => ['excluir' => [$cuota->id_cuota]]])
            ->get(route('quincenas.vista-previa', [self::ANIO, self::QUINCENA]))
            ->assertSee('<div class="small text-warning-emphasis mt-1 aviso-cuota-excluida">', false);
    }

    public function test_el_aviso_no_cambia_nada_financiero(): void
    {
        [$prestamo, $excluida, $proceso] = $this->prestamoConDosCuotasYUnaExcluida();

        $antes = $this->fotoFinanciera($prestamo, $proceso);

        $this->actingAs($this->funcionario)->get(route('prestamos.show', $prestamo))->assertOk();
        $this->actingAs($this->funcionario)->get(route('quincenas.proceso', $proceso))->assertOk();
        $this->actingAs($this->funcionario)->get(route('quincenas.index'))->assertOk();

        $this->assertEquals($antes, $this->fotoFinanciera($prestamo, $proceso));
        $this->assertSame(EstadoCuota::Pendiente, $excluida->fresh()->estado);
    }

    /**
     * @return array<string, mixed>
     */
    private function fotoFinanciera(Prestamo $prestamo, ProcesoQuincena $proceso): array
    {
        return [
            'cuotas' => Cuota::orderBy('id_cuota')->get()->map->only(['id_cuota', 'estado', 'valor_total', 'valor_capital', 'valor_interes', 'fecha_vencimiento'])->toArray(),
            'prestamo' => $prestamo->fresh()->only(['estado']),
            'pagos' => Pago::count(),
            'resumen' => $proceso->fresh()->resumen,
        ];
    }

    /**
     * @return array{0: Prestamo, 1: Cuota, 2: ProcesoQuincena}
     */
    private function prestamoConDosCuotasYUnaExcluida(): array
    {
        $prestamo = $this->prestamoVigente();
        $excluida = $this->cuota($prestamo, 1, '2026-10-10');
        $normal = $this->cuota($prestamo, 2, '2026-10-12');

        $proceso = $this->confirmar([$excluida, $normal], [$excluida->id_cuota => 'Licencia no remunerada']);

        return [$prestamo->refresh(), $excluida, $proceso];
    }

    /**
     * @param  list<Cuota>  $cuotas
     * @param  array<int, string>  $excluir
     */
    private function confirmar(array $cuotas, array $excluir = []): ProcesoQuincena
    {
        $this->actingAs($this->funcionario)->post(route('quincenas.confirmar', [self::ANIO, self::QUINCENA]), [
            'vistas' => collect($cuotas)->mapWithKeys(fn (Cuota $cuota) => [$cuota->id_cuota => 85_000])->all(),
            'excluir' => array_keys($excluir),
            'motivo' => $excluir,
        ])->assertRedirect();

        return ProcesoQuincena::query()->confirmados()->firstOrFail();
    }

    private function prestamoVigente(): Prestamo
    {
        return Prestamo::factory()->create(['estado' => EstadoPrestamo::Vigente, 'fecha_autorizacion' => '2026-09-01']);
    }

    private function cuota(Prestamo $prestamo, int $numero, string $fecha): Cuota
    {
        return Cuota::factory()->create([
            'id_prestamo' => $prestamo->id_prestamo,
            'numero_cuota' => $numero,
            'fecha_vencimiento' => $fecha,
            'valor_capital' => 80_000,
            'valor_interes' => 5_000,
            'valor_total' => 85_000,
            'estado' => EstadoCuota::Pendiente,
        ]);
    }
}
