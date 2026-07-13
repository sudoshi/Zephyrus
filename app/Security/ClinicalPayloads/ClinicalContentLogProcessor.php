<?php

namespace App\Security\ClinicalPayloads;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class ClinicalContentLogProcessor implements ProcessorInterface
{
    public function __construct(private readonly ClinicalContentGuard $guard) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->guard->contains($record->message)
            ? ClinicalContentGuard::REDACTED
            : $record->message;

        return $record->with(
            message: $message,
            context: $this->guard->redact($record->context),
            extra: $this->guard->redact($record->extra),
        );
    }
}
