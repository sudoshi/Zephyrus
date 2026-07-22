<?php

namespace App\Services\Patient\Pathway\Source;

use DateTimeInterface;

/**
 * A connector-facing, in-memory pathway snapshot. It is intentionally not an
 * HTTP payload, queue payload, Eloquent model, or loggable DTO. The approved
 * connector is responsible for resolving the governed access grant before it
 * calls the reconciliation service.
 *
 * @phpstan-type ObservationList list<PatientPathwaySourceStatusObservation>
 */
final readonly class PatientPathwaySourceSnapshot
{
    /**
     * @param  ObservationList  $stageObservations
     * @param  ObservationList  $milestoneObservations
     */
    public function __construct(
        public string $sourceSystemKey,
        public string $sourceAssignmentReference,
        public string $pathwayVersionUuid,
        public DateTimeInterface $sourceObservedAt,
        public array $stageObservations = [],
        public array $milestoneObservations = [],
    ) {}
}
