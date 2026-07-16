<?php

namespace App\Security\ClinicalPayloads;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;

/** Keeps Laravel's last-resort logger inside the same content boundary. */
final class ClinicalSafeLogManager extends LogManager
{
    protected function createEmergencyLogger(): LoggerInterface
    {
        $config = $this->configurationFor('emergency');
        $handler = new StreamHandler(
            $config['path'] ?? $this->app->storagePath().'/logs/laravel.log',
            $this->level(['level' => 'debug']),
        );
        $monolog = new MonologLogger('laravel', $this->prepareHandlers([$handler]));
        $monolog->pushProcessor($this->app->make(ClinicalContentLogProcessor::class));

        return new Logger($monolog, $this->app['events']);
    }
}
