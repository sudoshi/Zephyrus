<?php

namespace App\Services\Patient\Pathway\Source;

use DateTimeInterface;

/**
 * One transient, source-owned status observation for an already governed
 * pathway definition. No source value from this transfer object is persisted
 * verbatim in the patient realm.
 */
final readonly class PatientPathwaySourceStatusObservation
{
    public function __construct(
        public string $definitionStableKey,
        public string $status,
        public string $sourceEventReference,
        public DateTimeInterface $sourceObservedAt,
    ) {}
}
