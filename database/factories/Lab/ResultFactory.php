<?php

namespace Database\Factories\Lab;

use App\Models\Lab\LabTestCatalog;
use App\Models\Lab\Result;
use App\Models\Lab\Specimen;
use Database\Factories\Lab\Concerns\CreatesLabFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Result> */
class ResultFactory extends Factory
{
    use CreatesLabFixtures;

    protected $model = Result::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $resulted = now()->subMinutes(5);

        return [
            'result_uuid' => (string) Str::uuid(),
            'lab_specimen_id' => fn (): int => (int) Specimen::factory()->create()->lab_specimen_id,
            'ancillary_order_id' => fn (array $attributes): int => (int) Specimen::query()->findOrFail($attributes['lab_specimen_id'])->ancillary_order_id,
            'lab_test_catalog_id' => fn (): int => (int) $this->catalog('lab.bmp')->lab_test_catalog_id,
            'source_id' => fn (array $attributes): int => (int) Specimen::query()->findOrFail($attributes['lab_specimen_id'])->source_id,
            'source_result_key' => 'result-'.Str::uuid(),
            'source_result_version' => '1',
            'parent_lab_result_id' => null,
            'local_code' => fn (array $attributes): string => (string) LabTestCatalog::query()->findOrFail($attributes['lab_test_catalog_id'])->local_code,
            'loinc_code' => fn (array $attributes): ?string => LabTestCatalog::query()->findOrFail($attributes['lab_test_catalog_id'])->loinc_code,
            'result_status' => 'final',
            'result_stage' => 'final',
            'abnormal_flag' => 'normal',
            'auto_verified' => false,
            'is_critical' => false,
            'analyzer_ref' => 'analyzer-chemistry-1',
            'observed_at' => $resulted->copy()->subMinutes(5),
            'resulted_at' => $resulted,
            'verified_at' => now(),
            'corrected_at' => null,
            'cancelled_at' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function preliminary(): static
    {
        return $this->state(fn (): array => [
            'result_status' => 'preliminary',
            'result_stage' => 'preliminary',
            'verified_at' => null,
        ]);
    }

    public function autoVerified(): static
    {
        return $this->state(fn (): array => ['auto_verified' => true, 'verified_at' => now()]);
    }

    public function critical(): static
    {
        return $this->state(fn (): array => ['is_critical' => true, 'abnormal_flag' => 'critical']);
    }

    public function corrected(Result $parent): static
    {
        return $this->state(fn (): array => [
            'lab_specimen_id' => $parent->lab_specimen_id,
            'ancillary_order_id' => $parent->ancillary_order_id,
            'lab_test_catalog_id' => $parent->lab_test_catalog_id,
            'source_id' => $parent->source_id,
            'source_result_key' => $parent->source_result_key,
            'source_result_version' => (string) (((int) ($parent->source_result_version ?? 1)) + 1),
            'parent_lab_result_id' => $parent->lab_result_id,
            'local_code' => $parent->local_code,
            'loinc_code' => $parent->loinc_code,
            'result_status' => 'corrected',
            'result_stage' => 'corrected',
            'resulted_at' => $parent->resulted_at,
            'verified_at' => $parent->verified_at,
            'corrected_at' => now(),
        ]);
    }

    public function microbiologyStage(string $stage): static
    {
        $catalog = $this->catalog('lab.blood_culture');

        return $this->state(fn (): array => [
            'lab_test_catalog_id' => $catalog->lab_test_catalog_id,
            'local_code' => $catalog->local_code,
            'loinc_code' => $catalog->loinc_code,
            'result_status' => $stage === 'final' ? 'final' : 'preliminary',
            'result_stage' => $stage,
            'verified_at' => $stage === 'final' ? now() : null,
            'analyzer_ref' => 'microbiology-workcell-1',
        ]);
    }
}
