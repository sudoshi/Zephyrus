<?php

namespace App\Services\Patient;

use RuntimeException;

final class PatientNotificationDeviceFailure extends RuntimeException
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
        return new self('notification_device_unavailable', 503);
    }
}
