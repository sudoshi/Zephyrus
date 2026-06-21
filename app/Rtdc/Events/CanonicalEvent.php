<?php

namespace App\Rtdc\Events;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Immutable canonical operational event. The ONLY shape the domain consumes.
 * HL7v2/FHIR vocabulary is mapped into this at the source adapter (anti-corruption boundary).
 */
final readonly class CanonicalEvent
{
    public const ENCOUNTER_STARTED = 'EncounterStarted';

    public const ENCOUNTER_TRANSFERRED = 'EncounterTransferred';

    public const ENCOUNTER_DISCHARGED = 'EncounterDischarged';

    public const BED_STATUS_CHANGED = 'BedStatusChanged';

    public const ACUITY_CHANGED = 'AcuityChanged';

    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $encounterRef,
        public array $payload,
        public CarbonInterface $occurredAt,
    ) {}

    public static function encounterStarted(string $patientRef, int $unitId, int $acuityTier, CarbonInterface $occurredAt, ?int $bedId = null): self
    {
        return new self((string) Str::uuid(), self::ENCOUNTER_STARTED, $patientRef, [
            'unit_id' => $unitId, 'bed_id' => $bedId, 'acuity_tier' => $acuityTier,
        ], $occurredAt);
    }

    public static function encounterTransferred(string $patientRef, int $toUnitId, CarbonInterface $occurredAt, ?int $toBedId = null): self
    {
        return new self((string) Str::uuid(), self::ENCOUNTER_TRANSFERRED, $patientRef, [
            'to_unit_id' => $toUnitId, 'to_bed_id' => $toBedId,
        ], $occurredAt);
    }

    public static function encounterDischarged(string $patientRef, CarbonInterface $occurredAt): self
    {
        return new self((string) Str::uuid(), self::ENCOUNTER_DISCHARGED, $patientRef, [], $occurredAt);
    }

    public static function bedStatusChanged(int $bedId, string $status, CarbonInterface $occurredAt): self
    {
        return new self((string) Str::uuid(), self::BED_STATUS_CHANGED, null, [
            'bed_id' => $bedId, 'status' => $status,
        ], $occurredAt);
    }

    public static function acuityChanged(string $patientRef, int $acuityTier, CarbonInterface $occurredAt): self
    {
        return new self((string) Str::uuid(), self::ACUITY_CHANGED, $patientRef, [
            'acuity_tier' => $acuityTier,
        ], $occurredAt);
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type,
            'encounter_ref' => $this->encounterRef,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt->toIso8601String(),
        ];
    }
}
