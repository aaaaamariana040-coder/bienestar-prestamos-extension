<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmarCargaRequest;
use App\Http\Requests\VistaPreviaCargaRequest;
use App\Models\Empleado;
use App\Services\Carga\ArchivoPendiente;
use App\Services\CargaEmpleadosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class CargaEmpleadosController extends Controller
{
    private const CARPETA_PENDIENTES = 'cargas-empleados/pendientes';

    public function index(CargaEmpleadosService $carga): View
    {
        return view('cargar-empleados.index', [
            'lotes' => $carga->lotes(),
            'columnas' => CargaEmpleadosService::COLUMNAS,
            'maximoFilas' => CargaEmpleadosService::MAXIMO_FILAS,
        ]);
    }

    public function plantilla(CargaEmpleadosService $carga): Response
    {
        return response($carga->plantillaCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla-empleados.csv"',
        ]);
    }

    /**
     * Valida el archivo y muestra la vista previa; no guarda ningún empleado. El archivo solo
     * se deja en el disco si se puede confirmar, hasta que se confirma o se cancela.
     */
    public function vistaPrevia(VistaPreviaCargaRequest $request, CargaEmpleadosService $carga): View
    {
        $archivo = $request->file('archivo');
        $analisis = $carga->analizar((string) file_get_contents($archivo->getRealPath()));
        $resumen = $analisis['resumen'];
        $puedeConfirmar = $analisis['errores_archivo'] === [] && $resumen['errores'] === 0 && $resumen['total'] - $resumen['omitidas'] > 0;

        return view('cargar-empleados.vista-previa', [
            'analisis' => $analisis,
            'puedeConfirmar' => $puedeConfirmar,
            'token' => $puedeConfirmar ? $this->pendientes()->guardar((string) file_get_contents($archivo->getRealPath())) : null,
            'nombreArchivo' => Str::limit(basename($archivo->getClientOriginalName()), 150, ''),
        ]);
    }

    public function confirmar(ConfirmarCargaRequest $request, CargaEmpleadosService $carga): RedirectResponse
    {
        $token = $request->validated('token');

        if (! $this->pendientes()->existe($token)) {
            return redirect()->route('cargar-empleados.index')->withErrors(['archivo' => 'La vista previa venció o ya se cargó. Suba el archivo de nuevo.']);
        }

        try {
            $resultado = $carga->cargar($this->pendientes()->leer($token), (string) ($request->validated('nombre_archivo') ?? 'archivo.csv'), $request->user());
        } catch (RuntimeException $excepcion) {
            $this->pendientes()->borrar($token);

            return redirect()->route('cargar-empleados.index')->withErrors(['archivo' => $excepcion->getMessage()]);
        }

        $this->pendientes()->borrar($token);

        return redirect()->route('cargar-empleados.lote', $resultado['lote'])
            ->with('status', "Carga confirmada: {$resultado['creados']} empleado(s) creado(s) y {$resultado['omitidos']} omitido(s) por ya existir, en el lote {$resultado['lote']}.");
    }

    public function cancelar(ConfirmarCargaRequest $request): RedirectResponse
    {
        $this->pendientes()->borrar($request->validated('token'));

        return redirect()->route('cargar-empleados.index')->with('status', 'Carga cancelada: no se guardó nada y el archivo se eliminó.');
    }

    public function lote(string $lote, CargaEmpleadosService $carga): View
    {
        $empleados = Empleado::where('lote_carga', $lote)->orderBy('nombre_completo')->get();

        abort_if($empleados->isEmpty(), 404);

        return view('cargar-empleados.resultado', [
            'lote' => $lote,
            'empleados' => $empleados,
            'motivos' => $carga->motivosParaNoRevertir($lote),
        ]);
    }

    public function revertir(Request $request, string $lote, CargaEmpleadosService $carga): RedirectResponse
    {
        $motivos = $carga->motivosParaNoRevertir($lote);

        if ($motivos !== []) {
            return redirect()->route('cargar-empleados.index')->withErrors(['lote' => "No se puede revertir el lote {$lote}. ".implode(' ', $motivos)]);
        }

        $cantidad = $carga->revertir($lote, $request->user());

        return redirect()->route('cargar-empleados.index')
            ->with('status', "Lote {$lote} revertido: {$cantidad} empleado(s) eliminado(s).");
    }

    private function pendientes(): ArchivoPendiente
    {
        return new ArchivoPendiente(self::CARPETA_PENDIENTES);
    }
}
