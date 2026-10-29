<?php

namespace App\Services;

use App\Enums\TipoVinculacion;
use App\Http\Requests\StoreEmpleadoRequest;
use App\Models\Empleado;
use App\Models\Prestamo;
use App\Models\SmmlvAnual;
use App\Models\SolicitudCredito;
use App\Models\Usuario;
use App\Rules\MontoColombiano;
use App\Services\Carga\LectorCsv;
use App\Services\Carga\Lotes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Carga masiva de empleados desde un CSV, con el mismo esquema de la carga de préstamos vigentes:
 * vista previa que no guarda nada, confirmación todo o nada y reversión del lote.
 *
 * Las columnas salen del formulario «Nuevo empleado» y las reglas son las de StoreEmpleadoRequest
 * (no se copian: se leen de ella). Una cédula que ya existe no es error: la fila se omite y no se
 * actualiza. Un empleado nuevo siempre entra con estado Activo, como en el formulario.
 *
 * Privacidad: ningún mensaje de excepción ni de auditoría lleva cédulas, nombres ni salarios.
 */
class CargaEmpleadosService
{
    public const COLUMNAS = ['cedula', 'nombre_completo', 'tipo_vinculacion', 'de_carrera', 'cargo', 'dependencia', 'salario', 'fecha_vinculacion', 'capacidad_endeudamiento'];

    private const COLUMNAS_OBLIGATORIAS = ['cedula', 'nombre_completo', 'tipo_vinculacion', 'salario'];

    public const MAXIMO_FILAS = 1000;

    private const ATRIBUTOS = [
        'cedula' => 'cédula',
        'nombre_completo' => 'nombre completo',
        'tipo_vinculacion' => 'tipo de vinculación',
        'de_carrera' => 'de carrera',
        'cargo' => 'cargo',
        'dependencia' => 'dependencia',
        'salario' => 'salario',
        'fecha_vinculacion' => 'fecha de ingreso',
        'capacidad_endeudamiento' => 'capacidad de endeudamiento',
    ];

    public function __construct(
        private readonly EmpleadoService $empleadoService,
        private readonly AuditoriaService $auditoriaService,
    ) {
        //
    }

    /**
     * Plantilla descargable con tres filas de ejemplo claramente ficticias.
     */
    public function plantillaCsv(): string
    {
        return LectorCsv::escribir([
            self::COLUMNAS,
            ['9000000001', 'Ejemplo Uno', 'vinculado', 'si', 'Auxiliar administrativo', 'Dependencia de ejemplo', '3.200.000', '15/03/2018', ''],
            ['9000000002', 'Ejemplo Dos', 'docente', 'no', 'Docente de cátedra', 'Facultad de ejemplo', '4.850.000,50', '2020-08-03', ''],
            ['9000000003', 'Ejemplo Tres', 'jubilado', 'si', '', '', '2.400.000', '', '1.200.000'],
        ]);
    }

    /**
     * Valida el archivo completo sin guardar nada.
     *
     * @return array{errores_archivo: list<string>, filas: list<array<string, mixed>>, resumen: array<string, int>}
     */
    public function analizar(string $contenido): array
    {
        $lectura = LectorCsv::leer($contenido, self::COLUMNAS, self::COLUMNAS_OBLIGATORIAS, self::MAXIMO_FILAS, 'de empleados');

        if ($lectura['errores'] !== []) {
            return ['errores_archivo' => $lectura['errores'], 'filas' => [], 'resumen' => Lotes::resumir([], true)];
        }

        $cedulas = collect($lectura['filas'])->map(fn (array $fila): string => StoreEmpleadoRequest::normalizarCedula($fila['datos']['cedula']))->filter()->unique()->all();
        $existentes = Empleado::whereIn('cedula', $cedulas)->pluck('cedula')->flip();
        $vistas = [];
        $filas = [];

        foreach ($lectura['filas'] as $fila) {
            $filas[] = $this->analizarFila($fila['linea'], $fila['datos'], $existentes, $vistas);
        }

        return ['errores_archivo' => [], 'filas' => $filas, 'resumen' => Lotes::resumir($filas, true)];
    }

    /**
     * Crea los empleados nuevos del archivo en una sola transacción: todo o nada. Las filas
     * omitidas (cédula ya existente) no se tocan.
     *
     * @return array{lote: string, creados: int, omitidos: int}
     */
    public function cargar(string $contenido, string $nombreArchivo, Usuario $actor): array
    {
        $analisis = $this->analizar($contenido);

        if ($analisis['errores_archivo'] !== []) {
            throw new RuntimeException('El archivo no se puede cargar: '.$analisis['errores_archivo'][0]);
        }

        if ($analisis['resumen']['errores'] > 0) {
            throw new RuntimeException('El archivo tiene filas con error; corríjalas y vuelva a subirlo. No se cargó nada.');
        }

        $nuevas = array_values(array_filter($analisis['filas'], fn (array $fila): bool => $fila['estado'] !== 'omitida'));

        if ($nuevas === []) {
            throw new RuntimeException('No hay empleados nuevos para crear: todas las cédulas del archivo ya existen.');
        }

        return DB::transaction(function () use ($nuevas, $analisis, $nombreArchivo, $actor): array {
            $lote = Lotes::nuevo(fn (string $lote): bool => Empleado::where('lote_carga', $lote)->exists());

            foreach ($nuevas as $fila) {
                Empleado::create([...$fila['datos_validos'], 'origen' => Empleado::ORIGEN_CARGA_INICIAL, 'lote_carga' => $lote]);
            }

            $omitidos = $analisis['resumen']['omitidas'];

            $this->auditoriaService->registrar(
                $actor,
                'Carga de empleados',
                'Archivo «'.Str::limit(basename($nombreArchivo), 100, '')."» — lote {$lote} — ".count($nuevas).' empleado(s) creado(s), '.$omitidos.' omitido(s) por ya existir',
            );

            return ['lote' => $lote, 'creados' => count($nuevas), 'omitidos' => $omitidos];
        });
    }

    /**
     * Qué empleados del lote tienen solicitudes, préstamos o una cuenta de usuario, y por eso
     * impiden revertirlo; vacío si se puede. Los textos son para la pantalla (llevan la cédula);
     * ninguna excepción los usa.
     *
     * @return list<string>
     */
    public function motivosParaNoRevertir(string $lote): array
    {
        $empleados = Empleado::where('lote_carga', $lote)->where('origen', Empleado::ORIGEN_CARGA_INICIAL)->pluck('cedula');

        if ($empleados->isEmpty()) {
            return ['El lote no existe o ya fue revertido.'];
        }

        $solicitudes = SolicitudCredito::whereIn('cedula_empleado', $empleados)->selectRaw('cedula_empleado, count(*) as c')->groupBy('cedula_empleado')->pluck('c', 'cedula_empleado');
        $prestamos = Prestamo::whereIn('cedula_empleado', $empleados)->selectRaw('cedula_empleado, count(*) as c')->groupBy('cedula_empleado')->pluck('c', 'cedula_empleado');
        $usuarios = Usuario::whereIn('cedula', $empleados)->pluck('cedula')->flip();

        $motivos = [];

        foreach ($empleados as $cedula) {
            $tiene = [];

            if (($solicitudes[$cedula] ?? 0) > 0) {
                $tiene[] = "{$solicitudes[$cedula]} solicitud(es)";
            }

            if (($prestamos[$cedula] ?? 0) > 0) {
                $tiene[] = "{$prestamos[$cedula]} préstamo(s)";
            }

            if (isset($usuarios[$cedula])) {
                $tiene[] = 'una cuenta de usuario';
            }

            if ($tiene !== []) {
                $motivos[] = "El empleado {$cedula} tiene ".implode(', ', $tiene).' registrado(s) después de la carga.';
            }
        }

        return $motivos;
    }

    /**
     * Elimina los empleados del lote. Si alguno tiene registros asociados no revierte nada.
     */
    public function revertir(string $lote, Usuario $actor): int
    {
        if ($this->motivosParaNoRevertir($lote) !== []) {
            throw new RuntimeException('El lote no se puede revertir: hay empleados con registros asociados o el lote no existe.');
        }

        return DB::transaction(function () use ($lote, $actor): int {
            $cantidad = Empleado::where('lote_carga', $lote)->where('origen', Empleado::ORIGEN_CARGA_INICIAL)->delete();

            $this->auditoriaService->registrar($actor, 'Reversión de carga de empleados', "Lote {$lote} — {$cantidad} empleado(s) eliminado(s)");

            return $cantidad;
        });
    }

    /**
     * Lotes cargados, del más reciente al más antiguo, con su cantidad y si se pueden revertir.
     *
     * @return Collection<int, array{lote: string, cantidad: int, fecha: string, reversible: bool, motivos: list<string>}>
     */
    public function lotes(): Collection
    {
        return Empleado::where('origen', Empleado::ORIGEN_CARGA_INICIAL)
            ->whereNotNull('lote_carga')
            ->selectRaw('lote_carga, count(*) as cantidad, min(created_at) as fecha')
            ->groupBy('lote_carga')
            ->orderByDesc('fecha')
            ->orderByDesc('lote_carga')
            ->get()
            ->map(function (Empleado $fila): array {
                $motivos = $this->motivosParaNoRevertir($fila->lote_carga);

                return ['lote' => $fila->lote_carga, 'cantidad' => (int) $fila->cantidad, 'fecha' => (string) $fila->fecha, 'reversible' => $motivos === [], 'motivos' => $motivos];
            });
    }

    /**
     * @param  array<string, string>  $datos
     * @param  Collection<string, int>  $existentes  cédulas que ya están en la base (como llaves)
     * @param  array<string, int>  $vistas  cédula => fila donde apareció primero
     * @return array<string, mixed>
     */
    private function analizarFila(int $linea, array $datos, Collection $existentes, array &$vistas): array
    {
        $cedula = StoreEmpleadoRequest::normalizarCedula($datos['cedula']);
        $errores = [];
        $advertencias = [];

        if ($cedula === '') {
            $errores[] = 'La cédula está vacía.';
        } elseif (preg_match('/^\d{1,10}$/', $cedula) !== 1) {
            $errores[] = 'La cédula debe contener solo números, máximo 10 dígitos, sin puntos ni caracteres especiales.';
        } elseif (isset($vistas[$cedula])) {
            $errores[] = "La cédula está repetida: ya aparece en la fila {$vistas[$cedula]} del archivo.";
        } else {
            $vistas[$cedula] = $linea;

            if ($existentes->has($cedula)) {
                return $this->fila($linea, $datos, 'omitida', [], ['Ya existe un empleado con esta cédula: se omite y no se actualiza.'], null);
            }
        }

        $candidato = [
            'cedula' => $cedula,
            'nombre_completo' => $datos['nombre_completo'],
            'tipo_vinculacion' => $this->tipoVinculacion($datos['tipo_vinculacion']),
            'de_carrera' => $this->esDeCarrera($datos['de_carrera'], $errores),
            'cargo' => $datos['cargo'] === '' ? null : $datos['cargo'],
            'dependencia' => $datos['dependencia'] === '' ? null : $datos['dependencia'],
            'salario' => $datos['salario'] === '' ? '' : MontoColombiano::normalizar($datos['salario']),
            'fecha_vinculacion' => $this->fecha($datos['fecha_vinculacion'], $errores),
            'capacidad_endeudamiento' => $datos['capacidad_endeudamiento'] === '' ? null : MontoColombiano::normalizar($datos['capacidad_endeudamiento']),
            'estado' => 'Activo',
        ];

        $errores = [...$errores, ...$this->errorDeReglas($candidato)];

        if ($errores !== []) {
            return $this->fila($linea, $datos, 'error', $errores, [], null);
        }

        $candidato['capacidad_endeudamiento'] ??= $this->empleadoService->capacidadEndeudamientoSugerida((float) $candidato['salario']);
        $advertencias = $this->advertencias($candidato);

        return $this->fila($linea, $datos, $advertencias === [] ? 'correcta' : 'advertencia', [], $advertencias, $candidato);
    }

    /**
     * Las reglas del formulario manual, tomadas de StoreEmpleadoRequest (sin «unique»: una cédula
     * existente se omite, no es error). Un campo que ya tiene error propio de lectura no se repite.
     *
     * @param  array<string, mixed>  $candidato
     * @return list<string>
     */
    private function errorDeReglas(array $candidato): array
    {
        $formulario = new StoreEmpleadoRequest;
        $reglas = array_map(fn (mixed $reglas): mixed => is_array($reglas)
            ? array_values(array_filter($reglas, fn (mixed $regla): bool => ! (is_string($regla) && str_starts_with($regla, 'unique:'))))
            : $reglas, $formulario->rules());

        $validador = Validator::make($candidato, $reglas, $formulario->messages());
        $validador->setAttributeNames(self::ATRIBUTOS);

        $mensajes = [];

        foreach ($validador->errors()->messages() as $campo => $lista) {
            // La cédula ya se revisó arriba, con su propio mensaje.
            if ($campo !== 'cedula') {
                $mensajes[] = $lista[0];
            }
        }

        return $mensajes;
    }

    /**
     * El valor guardado del tipo de vinculación, aceptando también la etiqueta («Docente ocasional»).
     * Un texto desconocido pasa tal cual para que la regla enum lo rechace.
     */
    private function tipoVinculacion(string $texto): string
    {
        $llave = $this->llaveDeTipo($texto);

        foreach (TipoVinculacion::cases() as $tipo) {
            if ($llave === $this->llaveDeTipo($tipo->value) || $llave === $this->llaveDeTipo($tipo->etiqueta())) {
                return $tipo->value;
            }
        }

        return $texto;
    }

    private function llaveDeTipo(string $texto): string
    {
        return Str::lower(preg_replace('/[\s_]+/', ' ', Str::ascii(trim($texto))) ?? '');
    }

    /**
     * @param  list<string>  $errores
     */
    private function esDeCarrera(string $texto, array &$errores): bool
    {
        $llave = Str::lower(Str::ascii(trim($texto)));

        if (in_array($llave, ['', 'no', '0', 'false', 'n'], true)) {
            return false;
        }

        if (in_array($llave, ['si', '1', 'true', 's', 'x'], true)) {
            return true;
        }

        $errores[] = 'De carrera no es válido: use sí o no.';

        return false;
    }

    /**
     * dd/mm/aaaa o aaaa-mm-dd, a aaaa-mm-dd; vacío es nulo.
     *
     * @param  list<string>  $errores
     */
    private function fecha(string $texto, array &$errores): ?string
    {
        if ($texto === '') {
            return null;
        }

        foreach (['!d/m/Y', '!Y-m-d'] as $formato) {
            $fecha = \DateTimeImmutable::createFromFormat($formato, $texto);

            if ($fecha !== false && $fecha->format(substr($formato, 1)) === $texto && (int) $fecha->format('Y') >= 1900) {
                return $fecha->format('Y-m-d');
            }
        }

        $errores[] = 'La fecha de ingreso no es válida: use dd/mm/aaaa o aaaa-mm-dd.';

        return null;
    }

    /**
     * Avisos que no bloquean, sobre reglas que el aplicativo ya tiene para los créditos.
     *
     * @param  array<string, mixed>  $candidato
     * @return list<string>
     */
    private function advertencias(array $candidato): array
    {
        $avisos = [];

        if ($candidato['tipo_vinculacion'] === TipoVinculacion::DocenteOcasional->value) {
            $avisos[] = 'Docente ocasional: no es beneficiario de crédito del Fondo.';
        }

        $smmlv = SmmlvAnual::valorParaAnio(now()->year);

        if ($smmlv !== null && round((float) $candidato['salario'], 2) <= round($smmlv, 2) && (float) $candidato['salario'] > 0) {
            $avisos[] = 'El salario es igual o menor al salario mínimo (SMMLV): no se le puede descontar por nómina.';
        }

        if ($candidato['fecha_vinculacion'] === null) {
            $avisos[] = 'Sin fecha de ingreso: no se podrá verificar la antigüedad que exigen vivienda y vehículo.';
        } elseif ($candidato['fecha_vinculacion'] > now()->toDateString()) {
            $avisos[] = 'La fecha de ingreso es futura.';
        }

        return $avisos;
    }

    /**
     * @param  array<string, string>  $datos
     * @param  list<string>  $errores
     * @param  list<string>  $advertencias
     * @param  ?array<string, mixed>  $validos
     * @return array<string, mixed>
     */
    private function fila(int $linea, array $datos, string $estado, array $errores, array $advertencias, ?array $validos): array
    {
        return [
            'linea' => $linea,
            'datos' => $datos,
            'estado' => $estado,
            'errores' => $errores,
            'advertencias' => $advertencias,
            'datos_validos' => $validos,
        ];
    }
}
