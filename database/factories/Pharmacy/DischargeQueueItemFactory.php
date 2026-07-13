<?php

namespace Database\Factories\Pharmacy;

use App\Models\Pharmacy\DischargeQueueItem;
use App\Models\Pharmacy\MedicationOrder;
use Database\Factories\Pharmacy\Concerns\CreatesPharmacyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DischargeQueueItem> */
class DischargeQueueItemFactory extends Factory
{
    use CreatesPharmacyFixtures;

    protected $model = DischargeQueueItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'discharge_queue_uuid' => (string) Str::uuid(),
            'rx_order_id' => fn (): int => (int) MedicationOrder::factory()->discharge()->create()->rx_order_id,
            'source_id' => fn (array $attributes): int => (int) MedicationOrder::query()->findOrFail($attributes['rx_order_id'])->source_id,
            'source_queue_key' => 'rx-discharge-'.Str::uuid(),
            'encounter_id' => fn (array $attributes): ?int => MedicationOrder::query()->findOrFail($attributes['rx_order_id'])->encounter_id,
            'pipeline_status' => 'not_started',
            'status_changed_at' => now()->subMinutes(30),
            'prior_auth_pending_at' => null,
            'verification_started_at' => null,
            'filling_started_at' => null,
            'ready_at' => null,
            'delivered_at' => null,
            'planned_discharge_at' => now()->addHours(4),
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function priorAuthPending(): static
    {
        return $this->state(fn (): array => [
            'pipeline_status' => 'prior_auth_pending',
            'status_changed_at' => now()->subMinutes(20),
            'prior_auth_pending_at' => now()->subMinutes(20),
        ]);
    }

    public function verification(): static
    {
        return $this->state(fn (): array => [
            'pipeline_status' => 'verification',
            'status_changed_at' => now()->subMinutes(15),
            'verification_started_at' => now()->subMinutes(15),
        ]);
    }

    public function filling(): static
    {
        return $this->verification()->state(fn (): array => [
            'pipeline_status' => 'filling',
            'status_changed_at' => now()->subMinutes(10),
            'filling_started_at' => now()->subMinutes(10),
        ]);
    }

    public function ready(): static
    {
        return $this->filling()->state(fn (): array => [
            'pipeline_status' => 'ready',
            'status_changed_at' => now()->subMinutes(5),
            'ready_at' => now()->subMinutes(5),
        ]);
    }

    public function delivered(): static
    {
        return $this->ready()->state(fn (): array => [
            'pipeline_status' => 'delivered',
            'status_changed_at' => now(),
            'delivered_at' => now(),
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn (): array => [
            'pipeline_status' => 'unknown',
            'status_changed_at' => now(),
        ]);
    }
}
