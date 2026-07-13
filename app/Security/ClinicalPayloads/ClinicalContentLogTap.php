<?php

namespace App\Security\ClinicalPayloads;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

final class ClinicalContentLogTap
{
    public function __construct(private readonly ClinicalContentLogProcessor $processor) {}

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor($this->processor);
        }
    }
}
