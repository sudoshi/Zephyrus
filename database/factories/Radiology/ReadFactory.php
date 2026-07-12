<?php

namespace Database\Factories\Radiology;

use App\Models\Radiology\Exam;
use App\Models\Radiology\Read;
use Database\Factories\Radiology\Concerns\CreatesRadiologyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Read> */
class ReadFactory extends Factory
{
    use CreatesRadiologyFixtures;

    protected $model = Read::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $this->ensureRadiologyCatalogs();

        return [
            'read_uuid' => (string) Str::uuid(),
            'rad_exam_id' => fn (): int => (int) Exam::factory()->completed()->create()->rad_exam_id,
            'source_id' => fn (array $attributes): int => (int) Exam::query()->findOrFail($attributes['rad_exam_id'])->source_id,
            'source_read_key' => 'read-'.Str::uuid(),
            'source_report_version' => '1',
            'status' => 'final',
            'radiologist_ref' => 'radiologist-'.Str::uuid(),
            'subspecialty_code' => 'neuro',
            'is_teleradiology' => false,
            'preliminary_at' => null,
            'final_at' => now(),
            'corrected_at' => null,
            'parent_rad_read_id' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function preliminary(): static
    {
        return $this->state(fn (): array => ['status' => 'preliminary', 'preliminary_at' => now(), 'final_at' => null]);
    }

    public function teleradiology(): static
    {
        return $this->state(fn (): array => ['is_teleradiology' => true]);
    }

    public function addendum(Read $parent): static
    {
        return $this->state(fn (): array => ['rad_exam_id' => $parent->rad_exam_id, 'source_id' => $parent->source_id, 'status' => 'addendum', 'parent_rad_read_id' => $parent->rad_read_id, 'final_at' => now()]);
    }
}
