<?php

namespace App\Services\PatientFlow;

class FixtureAmbientSignalAdapter implements AmbientSignalAdapter
{
    /**
     * @param  array<string,mixed>  $definition
     * @param  array<int,array<string,mixed>>  $events
     */
    public function __construct(
        private readonly array $definition,
        private readonly array $events,
    ) {}

    public function definition(): array
    {
        return $this->definition;
    }

    public function fixtureEvents(): array
    {
        return $this->events;
    }
}
