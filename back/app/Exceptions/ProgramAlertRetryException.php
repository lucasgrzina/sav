<?php

namespace App\Exceptions;

/** A manual resend was refused. `reason` is a stable code the frontend maps to copy. */
class ProgramAlertRetryException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const PROGRAM_CANCELLED = 'program_cancelled';
    public const ALERT_CANCELLED = 'alert_cancelled';
    public const NOT_RETRYABLE = 'not_retryable';
    public const OPTED_OUT = 'opted_out';
    public const ALREADY_DELIVERED = 'already_delivered';
    public const RECENTLY_RETRIED = 'recently_retried';
    public const CONTACT_STILL_MISSING = 'contact_still_missing';

    private const MESSAGES = [
        self::NOT_FOUND => 'Aviso no encontrado.',
        self::PROGRAM_CANCELLED => 'El programa está cancelado: no se pueden reenviar avisos.',
        self::ALERT_CANCELLED => 'El aviso fue cancelado y ya no corresponde enviarlo.',
        self::NOT_RETRYABLE => 'Este aviso no se puede reenviar.',
        self::OPTED_OUT => 'La persona se dio de baja de los avisos por WhatsApp.',
        self::ALREADY_DELIVERED => 'Este aviso ya llegó a la persona por otro canal.',
        self::RECENTLY_RETRIED => 'Este aviso se reenvió hace instantes. Esperá unos minutos antes de volver a intentarlo.',
        self::CONTACT_STILL_MISSING => 'La persona todavía no tiene un contacto válido para recibir el aviso.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES[self::NOT_RETRYABLE]);
    }
}
