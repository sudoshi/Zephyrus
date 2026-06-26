<?php

namespace App\Services\PatientFlow;

interface AmbientSignalAdapter
{
    /** @return array<string,mixed> */
    public function definition(): array;

    /** @return array<int,array<string,mixed>> */
    public function fixtureEvents(): array;
}
