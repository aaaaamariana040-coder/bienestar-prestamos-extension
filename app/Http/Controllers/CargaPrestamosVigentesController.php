<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmarCargaRequest;
use App\Http\Requests\VistaPreviaCargaRequest;
use App\Models\Prestamo;
use App\Services\Carga\ArchivoPendiente;
use App\Services\CargaPrestamosVigentesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class CargaPrestamosVigentesController extends Controller
{
    private const CARPETA_PENDIENTES = 'cargas-vigentes/pendientes';

    public function index(CargaPrestamosVigentesService $carga): View
    {
        $this->authorize('cargarVigentes', Prestamo::class);

        return view('cargar-prestamos-vigentes.index', [
            'lotes' => $carga->lotes(),
            'columnas' => CargaPrestamosVigentesService::COLUMNAS,
            'maximoFilas' => CargaPrestamosVigentesService::MAXIMO_FILAS,
        ]);
    }

    public function plantilla(CargaPrestamosVigentesService $carga): Response
    {
        $this->authorize('cargarVigentes', Prestamo::class);

        return response($carga->plantillaCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla-prestamos-vigentes.csv"',
        ]);
    }

    /**
     * Valida el archivo y muestra la vista previa; no guarda ningún préstamo. El archivo queda
     * en una carpeta de pendientes hasta que se confirma la carga.
     */
    public function vistaPrevia(VistaPreviaCargaRequest $request, CargaPrestamosVigentesService $carga): View
    {
        $this->authorize('cargarVigentes', Prestamo::class);

        $archivo = $request->file('archivo');
        $contenido = (string) file_get_contents($archivo->getRealPath());
        $analisis = $carga->analizar($contenido);
        $token = null;

        if ($analisis['errores_archivo'] === []) {
            $token = $this->pendientes()->guardar($contenido);
        }

        return view('cargar-prestamos-vigentes.vista-previa', [
            'analisis' => $analisis,
            'token' => $token,
            'nombreArchivo' => Str::limit(basename($archivo->getClientOriginalName()), 150, ''),
        ]);
    }

    public function confirmar(ConfirmarCargaRequest $request, CargaPrestamosVigentesService $carga): RedirectResponse
    {
        $this->authorize('cargarVigentes', Prestamo::class);

        if (! $this->pendientes()->existe($request->validated('token'))) {
            return redirect()->route('cargar-prestamos-vigentes.index')->withErrors(['archivo' => 'La vista previa venció o ya se cargó. Suba el archivo de nuevo.']);
        }

        try {
            $resultado = $carga->cargar($this->pendientes()->leer($request->validated('token')), (string) ($request->validated('nombre_archivo') ?? 'archivo.csv'), $request->user());
        } catch (RuntimeException $excepcion) {
            return redirect()->route('cargar-prestamos-vigentes.index')->withErrors(['archivo' => $excepcion->getMessage()]);
        }

        $this->pendientes()->borrar($request->validated('token'));

        return redirect()->route('cargar-prestamos-vigentes.lote', $resultado['lote'])
            ->with('status', "Carga confirmada: {$resultado['prestamos']->count()} préstamo(s) creado(s) en el lote {$resultado['lote']}.");
    }

    public function lote(string $lote, CargaPrestamosVigentesService $carga): View
    {
        $this->authorize('cargarVigentes', Prestamo::class);

        $prestamos = Prestamo::with(['empleado', 'tipoPrestamo'])->where('lote_carga', $lote)->orderBy('id_prestamo')->get();

        abort_if($prestamos->isEmpty(), 404);

        return view('cargar-prestamos-vigentes.resultado', [
            'lote' => $lote,
            'prestamos' => $prestamos,
            'motivos' => $carga->motivosParaNoRevertir($lote),
        ]);
    }

    public function revertir(Request $request, string $lote, CargaPrestamosVigentesService $carga): RedirectResponse
    {
        $this->authorize('cargarVigentes', Prestamo::class);

        try {
            $cantidad = $carga->revertir($lote, $request->user());
        } catch (RuntimeException $excepcion) {
            return redirect()->route('cargar-prestamos-vigentes.index')->withErrors(['lote' => $excepcion->getMessage()]);
        }

        return redirect()->route('cargar-prestamos-vigentes.index')
            ->with('status', "Lote {$lote} revertido: {$cantidad} préstamo(s) eliminado(s) con sus cuotas y desembolsos.");
    }

    private function pendientes(): ArchivoPendiente
    {
        return new ArchivoPendiente(self::CARPETA_PENDIENTES);
    }
}
