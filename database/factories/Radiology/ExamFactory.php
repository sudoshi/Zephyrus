<?php

namespace Database\Factories\Radiology;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Radiology\Exam;
use Database\Factories\Radiology\Concerns\CreatesRadiologyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Exam> */
class ExamFactory extends Factory
{
    use CreatesRadiologyFixtures;

    protected $model = Exam::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $this->ensureRadiologyCatalogs();
        $scheduled = now()->addMinutes(fake()->numberBetween(15, 240));

        return [
            'exam_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => fn (): int => $this->createRadiologyOrderId(),
            'source_id' => fn (array $attributes): int => (int) AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->source_id,
            'source_exam_key' => 'exam-'.Str::uuid(),
            'encounter_id' => fn (array $attributes): ?int => AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->encounter_id,
            'modality_code' => 'CT',
            'body_region' => 'head',
            'subspecialty_code' => 'neuro',
            'procedure_code' => 'CT_HEAD_WO',
            'procedure_label' => 'CT head without contrast',
            'protocol' => 'Routine noncontrast head',
            'contrast_status' => 'not_required',
            'is_portable' => false,
            'is_ir' => false,
            'rad_scanner_id' => null,
            'scheduled_start_at' => $scheduled,
            'scheduled_end_at' => $scheduled->copy()->addMinutes(30),
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'status' => 'scheduled',
            'preparation' => ['screening' => 'not_required'],
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function modality(string $code): static
    {
        return $this->state(fn (): array => ['modality_code' => $code]);
    }

    public function xr(): static
    {
        return $this->modality('XR')->state(fn (): array => ['subspecialty_code' => 'general', 'procedure_code' => 'XR_CHEST', 'procedure_label' => 'Chest radiograph']);
    }

    public function ct(): static
    {
        return $this->modality('CT');
    }

    public function mri(): static
    {
        return $this->modality('MRI')->state(fn (): array => ['procedure_code' => 'MRI_BRAIN', 'procedure_label' => 'MRI brain']);
    }

    public function ultrasound(): static
    {
        return $this->modality('US')->state(fn (): array => ['subspecialty_code' => 'body', 'procedure_code' => 'US_ABD', 'procedure_label' => 'Abdominal ultrasound']);
    }

    public function nuclearMedicine(): static
    {
        return $this->modality('NM')->state(fn (): array => ['subspecialty_code' => 'body', 'procedure_code' => 'NM_HIDA', 'procedure_label' => 'HIDA scan']);
    }

    public function interventional(): static
    {
        return $this->modality('IR')->state(fn (): array => ['is_ir' => true, 'subspecialty_code' => 'ir', 'procedure_code' => 'IR_DRAIN', 'procedure_label' => 'Image-guided drainage']);
    }

    public function portable(): static
    {
        return $this->xr()->state(fn (): array => ['is_portable' => true, 'rad_scanner_id' => null, 'protocol' => 'Portable chest']);
    }

    public function withContrast(): static
    {
        return $this->state(fn (): array => ['contrast_status' => 'ready', 'preparation' => ['contrast_screening' => 'complete', 'renal_function' => 'reviewed']]);
    }

    public function completed(): static
    {
        return $this->state(function (): array {
            $started = now()->subMinutes(20);

            return ['status' => 'complete', 'started_at' => $started, 'completed_at' => $started->copy()->addMinutes(15)];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => 'cancelled', 'cancelled_at' => now(), 'started_at' => null, 'completed_at' => null]);
    }
}
