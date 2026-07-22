<?php

namespace App\Services\Mobile\Demo;

use App\Models\Encounter;
use App\Models\Unit;
use App\Services\Mobile\MobilePatientContextService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class HummingbirdReferencePatientProvisioner
{
    public const CREATED_BY = 'hummingbird-reference-patient-provisioner';

    public const DEFAULT_PATIENT_REF = 'demo-hummingbird-reference-inpatient';

    public function __construct(private readonly MobilePatientContextService $patientContext) {}

    /** @return array<string, mixed> */
    public function preview(int $unitId, string $patientRef = self::DEFAULT_PATIENT_REF): array
    {
        $this->assertSyntheticReference($patientRef);
        $unit = $this->unit($unitId);
        $existing = Encounter::query()
            ->where('patient_ref', $patientRef)
            ->orderByDesc('encounter_id')
            ->first();

        $this->assertOwnedOrMissing($existing);

        return $this->result(
            committed: false,
            action: $existing ? 'refresh_owned_reference_encounter' : 'create_reference_encounter',
            patientRef: $patientRef,
            unit: $unit,
            encounter: $existing,
        );
    }

    /** @return array<string, mixed> */
    public function provision(int $unitId, string $patientRef = self::DEFAULT_PATIENT_REF): array
    {
        $this->assertSyntheticReference($patientRef);

        return DB::transaction(function () use ($unitId, $patientRef): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('hummingbird-reference-patient'))");

            $unit = Unit::query()
                ->whereKey($unitId)
                ->where('is_deleted', false)
                ->lockForUpdate()
                ->firstOrFail();
            $encounter = Encounter::query()
                ->where('patient_ref', $patientRef)
                ->orderByDesc('encounter_id')
                ->lockForUpdate()
                ->first();

            $this->assertOwnedOrMissing($encounter);

            if ($encounter) {
                $encounter->update([
                    'unit_id' => $unit->unit_id,
                    'bed_id' => null,
                    'status' => 'active',
                    'discharged_at' => null,
                    'is_deleted' => false,
                    'expected_discharge_date' => now()->addDays(2)->toDateString(),
                    'modified_by' => self::CREATED_BY,
                ]);
                $action = 'refreshed_owned_reference_encounter';
            } else {
                $encounter = Encounter::create([
                    'patient_ref' => $patientRef,
                    'unit_id' => $unit->unit_id,
                    'bed_id' => null,
                    'admitted_at' => now(),
                    'expected_discharge_date' => now()->addDays(2)->toDateString(),
                    'acuity_tier' => 2,
                    'status' => 'active',
                    'created_by' => self::CREATED_BY,
                    'modified_by' => self::CREATED_BY,
                    'is_deleted' => false,
                ]);
                $action = 'created_reference_encounter';
            }

            return $this->result(
                committed: true,
                action: $action,
                patientRef: $patientRef,
                unit: $unit,
                encounter: $encounter->fresh(),
            );
        }, 3);
    }

    private function assertSyntheticReference(string $patientRef): void
    {
        if (preg_match('/^(demo|sim)-[a-z0-9][a-z0-9-]{7,95}$/', $patientRef) !== 1) {
            throw new InvalidArgumentException('Reference patient id must be a lowercase demo- or sim- pseudonym.');
        }
    }

    private function unit(int $unitId): Unit
    {
        if ($unitId < 1) {
            throw new InvalidArgumentException('A positive --unit-id is required.');
        }

        return Unit::query()
            ->whereKey($unitId)
            ->where('is_deleted', false)
            ->firstOrFail();
    }

    private function assertOwnedOrMissing(?Encounter $encounter): void
    {
        if ($encounter && $encounter->created_by !== self::CREATED_BY) {
            throw new RuntimeException('The synthetic patient reference is already owned by another data source.');
        }
    }

    /** @return array<string, mixed> */
    private function result(bool $committed, string $action, string $patientRef, Unit $unit, ?Encounter $encounter): array
    {
        return [
            'committed' => $committed,
            'action' => $action,
            'patient_context_ref' => $this->patientContext->contextRefFor($patientRef),
            'encounter_id' => $encounter?->encounter_id,
            'unit_id' => $unit->unit_id,
            'unit' => $unit->name,
            'status' => $encounter?->status ?? 'planned',
            'created_by' => self::CREATED_BY,
        ];
    }
}
