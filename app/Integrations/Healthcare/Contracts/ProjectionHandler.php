<?php

namespace App\Integrations\Healthcare\Contracts;

use App\Integrations\Healthcare\DTO\CanonicalOperationalEvent;

interface ProjectionHandler
{
    public function supports(CanonicalOperationalEvent $event): bool;

    public function project(CanonicalOperationalEvent $event): void;
}
