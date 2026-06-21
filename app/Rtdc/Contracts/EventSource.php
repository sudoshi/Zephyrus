<?php

namespace App\Rtdc\Contracts;

use App\Rtdc\Events\CanonicalEvent;

/**
 * Every event producer (synthetic simulator now; HL7v2/FHIR adapters later)
 * implements this. The dispatcher consumes CanonicalEvents regardless of source.
 *
 * @return iterable<CanonicalEvent>
 */
interface EventSource
{
    public function pull(): iterable;
}
