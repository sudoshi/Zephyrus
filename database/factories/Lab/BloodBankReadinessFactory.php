<?php

namespace Database\Factories\Lab;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Lab\BloodBankReadiness;
use Database\Factories\Lab\Concerns\CreatesLabFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BloodBankReadiness> */
class BloodBankReadinessFactory extends Factory
{
    use CreatesLabFixtures;

    protected $model = BloodBankReadiness::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'readiness_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => fn (): int => $this->createBloodBankOrderId(),
            'source_id' => fn (array $attributes): int => (int) AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->source_id,
            'source_request_key' => 'bb-request-'.Str::uuid(),
            'case_id' => null,
            'encounter_id' => fn (array $attributes): ?int => AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->encounter_id,
            'product_class' => 'red_cells',
            'readiness_state' => 'ordered',
            'type_screen_state' => 'pending',
            'crossmatch_state' => 'pending',
            'units_requested' => 2,
            'units_allocated' => 0,
            'units_issued' => 0,
            'ordered_at' => now()->subMinutes(30),
            'needed_by' => now()->addHours(2),
            'type_screen_ready_at' => null,
            'crossmatch_ready_at' => null,
            'allocated_at' => null,
            'issued_at' => null,
            'expires_at' => null,
            'mtp_activated_at' => null,
            'mtp_closed_at' => null,
            'cancelled_at' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function typeAndScreenReady(?int $caseId = null): static
    {
        return $this->state(fn (): array => [
            'case_id' => $caseId,
            'readiness_state' => 'type_screen_ready',
            'type_screen_state' => 'ready',
            'type_screen_ready_at' => now(),
            'expires_at' => now()->addDays(3),
        ]);
    }

    public function crossmatchReady(?int $caseId = null): static
    {
        return $this->state(fn (): array => [
            'case_id' => $caseId,
            'readiness_state' => 'crossmatch_ready',
            'type_screen_state' => 'ready',
            'crossmatch_state' => 'ready',
            'type_screen_ready_at' => now()->subMinutes(20),
            'crossmatch_ready_at' => now(),
            'units_allocated' => 2,
            'allocated_at' => now(),
            'expires_at' => now()->addDays(3),
        ]);
    }

    public function issued(?int $caseId = null): static
    {
        return $this->crossmatchReady($caseId)->state(fn (): array => [
            'readiness_state' => 'issued',
            'units_issued' => 1,
            'issued_at' => now()->addMinute(),
        ]);
    }

    public function mtpActive(?int $caseId = null): static
    {
        return $this->state(fn (): array => [
            'case_id' => $caseId,
            'product_class' => 'mixed',
            'readiness_state' => 'testing',
            'mtp_activated_at' => now()->subMinutes(10),
            'needed_by' => now(),
            'units_requested' => 6,
        ]);
    }

    public function mtpClosed(?int $caseId = null): static
    {
        return $this->mtpActive($caseId)->state(fn (): array => [
            'readiness_state' => 'complete',
            'mtp_closed_at' => now(),
        ]);
    }
}
