<?php

namespace App\Console\Commands;

use App\Enums\TipoVinculacion;
use App\Services\ConversionQService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * TEMPORAL — herramienta de migración puntual (docs/conversion-q.md); no es parte del producto.
 * Convierte los exportes Q09 (nómina) y Q15 (cartera) a las plantillas de «Cargar empleados» y
 * «Cargar préstamos vigentes». Imprime solo agregados: nunca nombres, cédulas, salarios ni montos.
 */
#[Signature('datos:convertir-q {--interpretacion=auto : Cómo leer QUINCENA_NUEVA: auto, restantes o pagadas} {--muestra=500 : Filas de la cartera para verificar saldos} {--tasa=derivada : tasa_anual de los préstamos: derivada (se deduce de la cuota) o vacia}')]
#[Description('TEMPORAL: convierte los CSV Q09 (nómina) y Q15 (cartera) a las plantillas de carga de empleados y de préstamos vigentes')]
class ConvertirQCommand extends Command
{
    public function handle(ConversionQService $conversion): int
    {
        $carpeta = rtrim((string) config('conversion_q.carpeta'), '/\\');
        $archivos = (array) config('conversion_q.archivos');

        if (config('conversion_q.verificar_git') && ! $this->carpetaIgnoradaPorGit($carpeta.DIRECTORY_SEPARATOR.$archivos['nomina'])) {
            $this->error('La carpeta de los archivos no está ignorada por git (o no se pudo comprobar): '.$carpeta.'. Use storage/app/private/real/ y no la versione.');

            return self::FAILURE;
        }

        foreach (['nomina', 'cartera'] as $clave) {
            if (! is_file($carpeta.DIRECTORY_SEPARATOR.$archivos[$clave])) {
                $this->error("Falta el archivo {$archivos[$clave]} en {$carpeta}.");

                return self::FAILURE;
            }
        }

        if (! in_array($this->option('interpretacion'), ConversionQService::INTERPRETACIONES, true)) {
            $this->error('--interpretacion debe ser auto, restantes o pagadas.');

            return self::FAILURE;
        }

        if (! in_array($this->option('tasa'), ConversionQService::MODOS_TASA, true)) {
            $this->error('--tasa debe ser derivada o vacia.');

            return self::FAILURE;
        }

        try {
            $resultado = $conversion->convertir(
                (string) file_get_contents($carpeta.DIRECTORY_SEPARATOR.$archivos['nomina']),
                (string) file_get_contents($carpeta.DIRECTORY_SEPARATOR.$archivos['cartera']),
                (string) $this->option('interpretacion'),
                (int) $this->option('muestra'),
                (string) $this->option('tasa'),
            );
        } catch (InvalidArgumentException $excepcion) {
            $this->error($excepcion->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            // Sin detalles: el mensaje de una excepción podría traer datos del archivo.
            $this->error('La conversión falló por un error inesperado. Revise el formato de los archivos.');

            return self::FAILURE;
        }

        file_put_contents($carpeta.DIRECTORY_SEPARATOR.$archivos['empleados'], $resultado['empleados_csv']);
        file_put_contents($carpeta.DIRECTORY_SEPARATOR.$archivos['prestamos'], $resultado['prestamos_csv']);

        $this->informe($resultado['informe'], $archivos);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $informe
     * @param  array<string, string>  $archivos
     */
    private function informe(array $informe, array $archivos): void
    {
        $e = $informe['empleados'];
        $p = $informe['prestamos'];
        $v = $informe['verificacion'];

        $this->newLine();
        $this->info('TEMPORAL — informe de la conversión (solo agregados)');
        $this->line("Empleados: {$e['leidos']} leídos, {$e['convertidos']} convertidos, ".array_sum($e['omitidos']).' omitidos.');

        foreach ($e['omitidos'] as $motivo => $cantidad) {
            $this->line("  omitidos — {$motivo}: {$cantidad}");
        }

        foreach (TipoVinculacion::cases() as $tipo) {
            $this->line("  tipo de vinculación {$tipo->etiqueta()}: ".($e['por_tipo_vinculacion'][$tipo->value] ?? 0));
        }

        $this->line("  empleados cuyo código de Tipo Contrato contradice el cargo (manda el código): {$e['codigo_contradice_el_cargo']}");

        $this->line("  fechas de ingreso inválidas (columna vacía): {$e['fechas_de_ingreso_invalidas']}");

        $this->newLine();
        $this->line('Valores de Tipo Contrato (de los empleados convertidos):');

        foreach ($informe['tipo_contrato'] as $valor => $datos) {
            $this->line("  «{$valor}»: {$datos['conteo']} — {$datos['significado']}");
        }

        $this->newLine();
        $this->line("Préstamos: {$p['leidos']} leídos, {$p['convertidos']} convertidos.");

        foreach ($p['excluidos_por_concepto'] as $concepto => $datos) {
            $this->line("  excluidos — concepto {$concepto} ({$datos['motivo']}): {$datos['conteo']}");
        }

        foreach ($p['sin_mapeo_por_concepto'] as $concepto => $cantidad) {
            $this->line("  sin mapeo — concepto {$concepto}: {$cantidad}");
        }

        foreach ($p['modalidad_del_mapeo_inexistente'] as $modalidad => $cantidad) {
            $this->line("  modalidad del mapeo que no existe en la base — {$modalidad}: {$cantidad}");
        }

        $this->line("  cédulas de la cartera sin empleado convertido: {$p['cedulas_sin_empleado']}");

        foreach ($p['datos_invalidos'] as $campo => $cantidad) {
            $this->line("  datos inválidos — {$campo}: {$cantidad}");
        }

        $this->newLine();
        $this->line("Tasa anual (modo {$informe['tasa']['modo']}): {$informe['tasa']['deducidas']} tasa deducida, {$informe['tasa']['no_deducibles']} tasa no deducible.");

        foreach ($informe['tasa']['por_motivo'] as $motivo => $cantidad) {
            $this->line("  tasa no deducible — {$motivo}: {$cantidad}");
        }

        foreach ($informe['tasa']['por_anio'] as $anio => $datos) {
            $this->line("  tasa deducida por año del préstamo {$anio}: ".(isset($datos['minimo'])
                ? "{$datos['conteo']} préstamos, mínimo {$datos['minimo']} %, mediana {$datos['mediana']} %, máximo {$datos['maximo']} %"
                : "{$datos['conteo']} préstamo(s) (menos de ".ConversionQService::MINIMO_PARA_RESUMIR.' en el año: no se detalla)'));
        }

        $this->newLine();
        $this->line("Verificación de saldos (muestra de {$v['muestra']}; {$v['sin_tasa_o_saldo']} sin tasa o sin saldo para verificar):");
        $this->line("  restantes: {$v['restantes_exactas']} exactas (≤ 1 peso), {$v['restantes_aproximadas']} aproximadas (≤ 1 %)");
        $this->line("  pagadas:   {$v['pagadas_exactas']} exactas (≤ 1 peso), {$v['pagadas_aproximadas']} aproximadas (≤ 1 %)");
        $this->line("  tasa deducida plausible (0–40 %) sin tasa del año en la base: restantes {$v['derivadas_restantes_en_rango']}, pagadas {$v['derivadas_pagadas_en_rango']}");
        $this->line("  interpretación usada: {$v['elegida']} ({$v['criterio']})");

        $this->newLine();
        $this->line('Modalidades de vivienda en la base:');

        foreach ($informe['modalidades_de_vivienda'] as $modalidad) {
            $this->line("  - {$modalidad}");
        }

        $this->newLine();
        $this->info("Escribí {$archivos['empleados']} y {$archivos['prestamos']} en la carpeta de entrada. Revíselos con la vista previa de las pantallas de carga; no suba nada a git.");
    }

    private function carpetaIgnoradaPorGit(string $archivo): bool
    {
        $codigo = 1;
        $salida = [];

        @exec('git -C '.escapeshellarg(base_path()).' check-ignore -q -- '.escapeshellarg($archivo).' 2>&1', $salida, $codigo);

        return $codigo === 0;
    }
}
