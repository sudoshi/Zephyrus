<?php

namespace App\Services\Patient\Messaging;

use RuntimeException;

class StaffPatientCommunicationFailure extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('not_found', 404, 'The requested communication is not available.');
    }

    public static function unavailable(): self
    {
        return new self('communications_unavailable', 503, 'Patient communications are temporarily unavailable.');
    }

    public static function staleVersion(): self
    {
        return new self('stale_version', 409, 'This communication changed. Refresh before trying again.');
    }

    public static function idempotencyConflict(): self
    {
        return new self('idempotency_conflict', 409, 'This replay key is already bound to another operation.');
    }

    public static function alreadyAssigned(): self
    {
        return new self('already_assigned', 409, 'This communication is already assigned.');
    }

    public static function threadClosed(): self
    {
        return new self('thread_closed', 409, 'This communication is closed.');
    }

    public static function responseRequired(): self
    {
        return new self('response_required', 409, 'Send a patient-visible response before closing this communication.');
    }
}
