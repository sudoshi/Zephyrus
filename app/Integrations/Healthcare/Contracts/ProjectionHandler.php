<?php

namespace App\Integrations\Healthcare\Contracts;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;

interface ProjectionHandler
{
    public function key(): string;

    /** @return list<string> */
    public function eventTypes(): array;

    public function supports(CanonicalOperationalEvent $event): bool;

    public function project(CanonicalOperationalEvent $event): void;
}
