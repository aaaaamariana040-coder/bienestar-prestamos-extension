<?php

namespace App\Mail\Transport;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Transporte de desarrollo: no envía nada y deja en el log solo un resumen
 * (destinatarios, copia, asunto y nombre y tamaño de cada adjunto). El cuerpo y
 * el contenido de los adjuntos no se escriben nunca.
 */
class LogResumenTransport extends AbstractTransport
{
    public const MENSAJE = 'Correo simulado (no se envió)';

    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $correo = $message->getOriginalMessage();

        if (! $correo instanceof Email) {
            $this->logger->info(self::MENSAJE);

            return;
        }

        $this->logger->info(self::MENSAJE, [
            'para' => array_map(fn (Address $direccion): string => $direccion->getAddress(), $correo->getTo()),
            'copia' => array_map(fn (Address $direccion): string => $direccion->getAddress(), $correo->getCc()),
            'asunto' => $correo->getSubject(),
            'adjuntos' => array_map(fn (DataPart $parte): array => [
                'nombre' => $parte->getFilename(),
                'bytes' => strlen($parte->getBody()),
            ], $correo->getAttachments()),
        ]);
    }

    public function __toString(): string
    {
        return 'log-resumen';
    }
}
