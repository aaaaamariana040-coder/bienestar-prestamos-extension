<?php

namespace App\Mail;

use App\Models\DocumentoSolicitud;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Correo de una solicitud al comité. Los destinatarios los pone quien lo envía
 * (Mail::to()->cc()), así la vista previa y el envío usan exactamente este mismo objeto.
 */
class SolicitudAlComiteMail extends Mailable
{
    public const DISCO_DOCUMENTOS = 'local';

    /**
     * @param  array<string, mixed>  $datos  cifras y textos del cuerpo (sin el salario)
     * @param  Collection<int, DocumentoSolicitud>  $documentos  los que van adjuntos
     */
    public function __construct(
        public readonly array $datos,
        public readonly string $asunto,
        public readonly string $introduccion,
        public readonly string $cierre,
        public readonly Collection $documentos,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('comite.remitente.direccion'), (string) config('comite.remitente.nombre')),
            subject: $this->asunto,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.solicitud-comite');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return $this->documentos
            ->map(fn (DocumentoSolicitud $documento): Attachment => Attachment::fromStorageDisk(self::DISCO_DOCUMENTOS, $documento->ruta)
                ->as($documento->nombre_original)
                ->withMime($documento->tipo_mime))
            ->values()
            ->all();
    }
}
