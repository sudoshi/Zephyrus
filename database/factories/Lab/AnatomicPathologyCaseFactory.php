<?php

namespace Database\Factories\Lab;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Lab\AnatomicPathologyCase;
use Database\Factories\Lab\Concerns\CreatesLabFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AnatomicPathologyCase> */
class AnatomicPathologyCaseFactory extends Factory
{
    use CreatesLabFixtures;

    protected $model = AnatomicPathologyCase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $specimenOut = now()->subMinutes(45);
        $received = $specimenOut->copy()->addMinutes(15);

        return [
            'ap_case_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => fn (): int => $this->createPathologyOrderId(),
            'source_id' => fn (array $attributes): int => (int) AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->source_id,
            'source_case_key' => 'ap-case-'.Str::uuid(),
            'case_id' => null,
            'encounter_id' => fn (array $attributes): ?int => AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->encounter_id,
            'source_accession_key' => 'ap-accession-'.Str::uuid(),
            'specimen_ref' => 'ap-specimen-'.Str::uuid(),
            'procedure_code' => 'SURG_PATH',
            'procedure_label' => 'Surgical pathology specimen',
            'case_type' => 'surgical',
            'stage' => 'received',
            'current_stage_at' => $received,
            'specimen_out_at' => $specimenOut,
            'received_at' => $received,
            'grossed_at' => null,
            'processing_batch_at' => null,
            'slides_ready_at' => null,
            'diagnosed_at' => null,
            'signed_out_at' => null,
            'frozen_status' => 'not_applicable',
            'frozen_started_at' => null,
            'frozen_resulted_at' => null,
            'pathologist_ref' => null,
            'cancelled_at' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function frozenSection(int $caseId): static
    {
        return $this->state(fn (): array => [
            'case_id' => $caseId,
            'case_type' => 'frozen_section',
            'frozen_status' => 'in_progress',
            'frozen_started_at' => now()->subMinutes(8),
            'frozen_resulted_at' => null,
        ]);
    }

    public function frozenResulted(int $caseId): static
    {
        return $this->frozenSection($caseId)->state(fn (): array => [
            'frozen_status' => 'resulted',
            'frozen_started_at' => now()->subMinutes(18),
            'frozen_resulted_at' => now()->subMinutes(2),
        ]);
    }

    public function signedOut(): static
    {
        $received = now()->subDays(2);

        return $this->state(fn (): array => [
            'stage' => 'signed_out',
            'current_stage_at' => now(),
            'specimen_out_at' => $received->copy()->subMinutes(20),
            'received_at' => $received,
            'grossed_at' => $received->copy()->addHours(2),
            'processing_batch_at' => $received->copy()->addHours(8),
            'slides_ready_at' => $received->copy()->addDay(),
            'diagnosed_at' => $received->copy()->addDay()->addHours(4),
            'signed_out_at' => now(),
            'pathologist_ref' => 'pathologist-service-1',
        ]);
    }
}
