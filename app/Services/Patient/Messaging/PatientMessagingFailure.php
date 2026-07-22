<?php

namespace App\Services\Patient\Messaging;

use RuntimeException;

class PatientMessagingFailure extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct($errorCode);
    }

    public static function notFound(): self
    {
        return new self('not_found', 404);
    }

    public static function unavailable(): self
    {
        return new self('messaging_unavailable', 503);
    }

    public static function threadClosed(): self
    {
        return new self('thread_closed', 409);
    }

    public static function staleVersion(): self
    {
        return new self('stale_thread_version', 409);
    }

    public static function idempotencyConflict(): self
    {
        return new self('idempotency_conflict', 409);
    }

    public static function guidanceChanged(): self
    {
        return new self('urgent_guidance_changed', 409);
    }

    public static function threadMessageLimitReached(): self
    {
        return new self('thread_message_limit_reached', 409);
    }

    public static function messageNotAmendable(): self
    {
        return new self('message_not_amendable', 409);
    }
}
