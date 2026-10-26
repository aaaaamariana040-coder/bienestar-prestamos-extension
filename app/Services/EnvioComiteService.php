<?php

namespace App\Services;

use App\Enums\EstadoSolicitud;
use App\Mail\SolicitudAlComiteMail;
use App\Models\DocumentoSolicitud;
use App\Models\EnvioComite;
use App\Models\SolicitudCredito;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envío de una solicitud al comité por correo (síncrono, sin colas). Arma el
 * mismo correo para la vista previa y para el envío, valida lo que impide
 * enviarlo y deja el registro y la auditoría. Un envío fallido no crea registro
 * ni marca: solo queda en la auditoría.
 */
class EnvioComiteService
{
    /**
     * Transportes que no entregan nada: el envío queda «simulado».
     */
    private const TRANSPORTES_SIMULADOS = ['log', 'log-resumen', 'array'];

    public function __construct(
        private readonly AuditoriaService $auditoriaService,
        private readonly SimuladorCreditoService $simuladorCreditoService,
        private readonly CalculoFinancieroService $calculoFinanciero,
        private readonly CapacidadEndeudamientoService $capacidadService,
        private readonly SolicitudCreditoService $solicitudCreditoService,
        private readonly DocumentoSolicitudService $documentoSolicitudService,
    ) {
        //
    }

    /**
     * ¿El mailer actual entrega correos de verdad? Con log o array el envío es una simulación.
     */
    public function esCorreoReal(): bool
    {
        $mailer = (string) config('mail.default');
        $transporte = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        return ! in_array($transporte, self::TRANSPORTES_SIMULADOS, true);
    }

    /**
     * Todo lo que la pantalla de vista previa necesita. `$idsDocumentos` null
     * incluye todos los documentos de la solicitud.
     *
     * @param  list<int>|null  $idsDocumentos
     * @return array{
     *     puede_enviar: bool,
     *     bloqueos: list<string>,
     *     avisos: list<string>,
     *     destinatarios: list<string>,
     *     copia: list<string>,
     *     asunto: string,
     *     cuerpo: string,
     *     adjuntos: list<array{id: int, tipo: string, nombre: string, bytes: int, tamano_legible: string, mime: string, incluido: bool, existe: bool}>,
     *     total_bytes: int,
     *     limite_bytes: int,
     *     simulado: bool,
     *     ultimo_envio: ?EnvioComite,
     *     es_reenvio: bool,
     * }
     */
    public function vistaPrevia(SolicitudCredito $solicitud, ?array $idsDocumentos = null): array
    {
        $documentos = $solicitud->documentos()->with('tipoDocumento')->orderBy('created_at')->orderBy('id_documento')->get();
        $incluidos = $idsDocumentos === null
            ? $documentos
            : $documentos->filter(fn (DocumentoSolicitud $documento): bool => in_array($documento->id_documento, $idsDocumentos, true))->values();

        $adjuntos = $documentos->map(fn (DocumentoSolicitud $documento): array => [
            'id' => $documento->id_documento,
            'tipo' => $documento->tipoDocumento->nombre,
            'nombre' => $documento->nombre_original,
            'bytes' => (int) $documento->tamano,
            'tamano_legible' => $documento->tamano_legible,
            'mime' => $documento->tipo_mime,
            'incluido' => $incluidos->contains('id_documento', $documento->id_documento),
            'existe' => $this->documentoSolicitudService->existeElArchivo($documento),
        ])->all();

        $totalBytes = (int) $incluidos->sum('tamano');
        $limiteBytes = (int) round((float) config('comite.adjuntos.maximo_total_mb') * 1024 * 1024);
        $correo = $this->construirCorreo($solicitud, $incluidos);
        $simulado = ! $this->esCorreoReal();
        $ultimoEnvio = $solicitud->envios()->first();

        $bloqueos = $this->bloqueos($solicitud, $incluidos, $adjuntos, $totalBytes, $limiteBytes);
        $avisos = [];

        if ($simulado) {
            $avisos[] = 'Simulado (no se envió): el correo se registra en el log y no sale del aplicativo.';
        }

        if ($incluidos->isEmpty()) {
            $avisos[] = 'La solicitud se enviará sin documentos adjuntos.';
        } elseif ($incluidos->count() < $documentos->count()) {
            $avisos[] = 'Hay documentos de la solicitud que no se adjuntarán a este correo.';
        }

        return [
            'puede_enviar' => $bloqueos === [],
            'bloqueos' => $bloqueos,
            'avisos' => $avisos,
            'destinatarios' => $this->destinatarios(),
            'copia' => $this->copia(),
            'asunto' => $correo->asunto,
            'cuerpo' => $correo->render(),
            'adjuntos' => $adjuntos,
            'total_bytes' => $totalBytes,
            'limite_bytes' => $limiteBytes,
            'simulado' => $simulado,
            'ultimo_envio' => $ultimoEnvio,
            'es_reenvio' => $ultimoEnvio !== null,
        ];
    }

    /**
     * Envía el correo. Rechaza con EnvioComiteException (mensaje listo para el
     * operador) lo que impide el envío; si el transporte falla, no crea registro
     * y deja el intento en la auditoría.
     *
     * @param  list<int>|null  $idsDocumentos
     */
    public function enviar(SolicitudCredito $solicitud, Usuario $actor, ?array $idsDocumentos, bool $confirmaReenvio): EnvioComite
    {
        $candado = Cache::lock("envio-comite-{$solicitud->id_solicitud}", 60);

        if (! $candado->get()) {
            throw new EnvioComiteException('Ya hay un envío de esta solicitud en curso. Espera unos segundos y revisa el registro de envíos.');
        }

        try {
            return $this->enviarConCandado($solicitud, $actor, $idsDocumentos, $confirmaReenvio);
        } finally {
            $candado->release();
        }
    }

    /**
     * @param  list<int>|null  $idsDocumentos
     */
    private function enviarConCandado(SolicitudCredito $solicitud, Usuario $actor, ?array $idsDocumentos, bool $confirmaReenvio): EnvioComite
    {
        $plan = $this->vistaPrevia($solicitud, $idsDocumentos);

        if ($plan['bloqueos'] !== []) {
            throw new EnvioComiteException($plan['bloqueos'][0]);
        }

        $ultimoEnvio = $plan['ultimo_envio'];
        $segundos = (int) config('comite.segundos_entre_envios');

        if ($ultimoEnvio !== null && $ultimoEnvio->enviado_en->greaterThan(now()->subSeconds($segundos))) {
            throw new EnvioComiteException("Esta solicitud ya se envió hace menos de {$segundos} segundos. Si fue un doble clic, no hace falta reenviarla.");
        }

        if ($ultimoEnvio !== null && ! $confirmaReenvio) {
            throw new EnvioComiteException('Esta solicitud ya se envió el '.$ultimoEnvio->enviado_en->format('Y-m-d H:i').'. Para reenviarla marca «Confirmo el reenvío».');
        }

        $incluidos = $solicitud->documentos()->with('tipoDocumento')->orderBy('created_at')->orderBy('id_documento')->get()
            ->filter(fn (DocumentoSolicitud $documento): bool => $idsDocumentos === null || in_array($documento->id_documento, $idsDocumentos, true))
            ->values();
        $correo = $this->construirCorreo($solicitud, $incluidos);
        $esReenvio = $ultimoEnvio !== null;

        try {
            Mail::to($plan['destinatarios'])->cc($plan['copia'])->send($correo);
        } catch (Throwable $e) {
            $this->auditoriaService->registrar($actor, 'Envío de solicitud al comité fallido', "Solicitud #{$solicitud->id_solicitud} — no se pudo enviar el correo (".class_basename($e).')');
            Log::warning('Falló el envío de una solicitud al comité', ['solicitud' => $solicitud->id_solicitud, 'excepcion' => $e::class]);

            throw new EnvioComiteException('No se pudo enviar el correo al comité: el servidor de correo no respondió o lo rechazó. La solicitud NO quedó marcada como enviada; inténtalo de nuevo o avisa al administrador.');
        }

        $adjuntos = $incluidos->map(fn (DocumentoSolicitud $documento): array => [
            'id' => $documento->id_documento,
            'nombre' => $documento->nombre_original,
            'bytes' => (int) $documento->tamano,
            'mime' => $documento->tipo_mime,
        ])->all();

        return DB::transaction(function () use ($solicitud, $actor, $plan, $correo, $adjuntos, $esReenvio): EnvioComite {
            $envio = $solicitud->envios()->create([
                'cedula_usu' => $actor->cedula,
                'enviado_en' => now(),
                'destinatarios' => $plan['destinatarios'],
                'copia' => $plan['copia'],
                'asunto' => $correo->asunto,
                'adjuntos' => $adjuntos,
                'datos' => $correo->datos,
                'mailer' => (string) config('mail.default'),
                'simulado' => $plan['simulado'],
                'es_reenvio' => $esReenvio,
            ]);

            $nombres = implode(', ', array_column($adjuntos, 'nombre'));
            $this->auditoriaService->registrar(
                $actor,
                $esReenvio ? 'Reenvío de solicitud al comité' : 'Envío de solicitud al comité',
                "Solicitud #{$solicitud->id_solicitud} — para: ".implode(', ', $plan['destinatarios']).'; cc: '.implode(', ', $plan['copia'])
                    .'; '.count($adjuntos).' adjunto(s)'.($nombres !== '' ? " ({$nombres})" : '').($plan['simulado'] ? '; simulado (no se envió)' : ''),
            );

            return $envio;
        });
    }

    /**
     * @param  Collection<int, DocumentoSolicitud>  $documentos
     */
    private function construirCorreo(SolicitudCredito $solicitud, Collection $documentos): SolicitudAlComiteMail
    {
        $datos = $this->datosDeLaSolicitud($solicitud);

        return new SolicitudAlComiteMail(
            $datos,
            $this->sinSaltosDeLinea($this->reemplazarMarcadores((string) config('comite.asunto'), $datos)),
            $this->reemplazarMarcadores((string) config('comite.texto_introduccion'), $datos),
            $this->reemplazarMarcadores((string) config('comite.texto_cierre'), $datos),
            $documentos,
        );
    }

    /**
     * Cifras que van en el cuerpo. No incluye el salario. La tasa y la cuota se
     * calculan como en la aprobación (tasa oficial del año de la solicitud) y la
     * capacidad es la misma que usa la aprobación (CapacidadEndeudamientoService).
     *
     * @return array<string, mixed>
     */
    private function datosDeLaSolicitud(SolicitudCredito $solicitud): array
    {
        $solicitud->loadMissing(['empleado', 'tipoPrestamo']);
        $monto = (float) $solicitud->monto_solicitado;
        $plazo = (int) $solicitud->plazo_solicitado;

        $oficial = $this->simuladorCreditoService->tasaOficial($solicitud->empleado, $solicitud->tipoPrestamo, $solicitud->fecha_solicitud->year, $plazo, $solicitud->fecha_solicitud);
        $tasaTotal = $oficial['base'] === null ? null : round($oficial['base'] + $oficial['puntos'], 4);
        $cuota = $tasaTotal === null
            ? null
            : $this->calculoFinanciero->cuotaFija($monto, $this->calculoFinanciero->tasaPeriodicaQuincenal($tasaTotal), $plazo);
        $capacidad = $this->capacidadService->paraEmpleado($solicitud->empleado, $solicitud->fecha_solicitud->year);

        return [
            'id_solicitud' => $solicitud->id_solicitud,
            'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d'),
            'empleado' => $solicitud->empleado->nombre_completo,
            'cedula' => $solicitud->cedula_empleado,
            'modalidad' => $solicitud->tipoPrestamo->nombre,
            'monto' => $monto,
            'plazo_quincenas' => $plazo,
            'plazo_legible' => $solicitud->plazo_solicitado_legible,
            'tasa_base' => $oficial['base'],
            'tasa_puntos' => $oficial['puntos'],
            'tasa_total' => $tasaTotal,
            'cuota_quincenal' => $cuota,
            'cuota_mensual' => $cuota === null ? null : round($cuota * 2, 2),
            'capacidad_total' => $capacidad['total'],
            'capacidad_comprometida' => $capacidad['comprometida'],
            'capacidad_disponible' => $capacidad['disponible'],
            'observaciones' => config('comite.incluir_observaciones') ? $this->solicitudCreditoService->validar($solicitud) : [],
        ];
    }

    /**
     * @param  Collection<int, DocumentoSolicitud>  $incluidos
     * @param  list<array<string, mixed>>  $adjuntos
     * @return list<string>
     */
    private function bloqueos(SolicitudCredito $solicitud, Collection $incluidos, array $adjuntos, int $totalBytes, int $limiteBytes): array
    {
        $bloqueos = [];

        if ($solicitud->estado !== EstadoSolicitud::Radicada) {
            $bloqueos[] = "Solo se puede enviar al comité una solicitud Radicada; esta está {$solicitud->estado->value}.";
        }

        if ($this->destinatarios() === []) {
            $bloqueos[] = 'No hay destinatarios del comité configurados (COMITE_DESTINATARIOS): configúralos antes de enviar.';
        }

        if (app()->isProduction()) {
            if (! $this->esCorreoReal()) {
                $bloqueos[] = 'Correo no configurado: en producción se necesita un servidor de correo real (por ejemplo MAIL_MAILER=smtp), no log ni array.';
            }

            $provisionales = array_filter([...$this->destinatarios(), ...$this->copia(), (string) config('comite.remitente.direccion')], fn (string $direccion): bool => str_ends_with(strtolower($direccion), '.test'));

            if ($provisionales !== []) {
                $bloqueos[] = 'Correo no configurado: el remitente, el comité o la copia siguen con direcciones provisionales (.test): '.implode(', ', $provisionales).'.';
            }
        }

        if ($incluidos->isEmpty() && config('comite.adjuntos.exigir_documentos')) {
            $bloqueos[] = 'Se exigen documentos adjuntos y no hay ninguno seleccionado.';
        }

        $faltantes = array_column(array_filter($adjuntos, fn (array $adjunto): bool => $adjunto['incluido'] && ! $adjunto['existe']), 'nombre');

        if ($faltantes !== []) {
            $bloqueos[] = 'Falta el archivo de: '.implode(', ', $faltantes).' (ya no está en el servidor). Desmárcalos o vuelve a subirlos.';
        }

        if ($totalBytes > $limiteBytes) {
            $bloqueos[] = 'Los adjuntos pesan '.$this->megas($totalBytes).' MB y el límite del correo es '.$this->megas($limiteBytes).' MB. Desmarca documentos.';
        }

        return $bloqueos;
    }

    /**
     * @return list<string>
     */
    private function destinatarios(): array
    {
        return array_values((array) config('comite.destinatarios'));
    }

    /**
     * @return list<string>
     */
    private function copia(): array
    {
        return array_values((array) config('comite.copia'));
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function reemplazarMarcadores(string $texto, array $datos): string
    {
        return strtr($texto, [
            '{solicitud}' => (string) $datos['id_solicitud'],
            '{empleado}' => (string) $datos['empleado'],
            '{cedula}' => (string) $datos['cedula'],
            '{modalidad}' => (string) $datos['modalidad'],
            '{monto}' => '$'.number_format((float) $datos['monto'], 0, ',', '.'),
        ]);
    }

    private function sinSaltosDeLinea(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    private function megas(int $bytes): string
    {
        return number_format($bytes / (1024 * 1024), 1, ',', '.');
    }
}
