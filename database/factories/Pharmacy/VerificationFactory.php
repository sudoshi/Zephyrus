<?php

namespace Database\Factories\Pharmacy;

use App\Models\Pharmacy\MedicationOrder;
use App\Models\Pharmacy\Verification;
use Database\Factories\Pharmacy\Concerns\CreatesPharmacyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Verification> */
class VerificationFactory extends Factory
{
    use CreatesPharmacyFixtures;

    protected $model = Verification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'verification_uuid' => (string) Str::uuid(),
            'rx_order_id' => fn (): int => (int) MedicationOrder::factory()->create()->rx_order_id,
            'source_id' => fn (array $attributes): int => (int) MedicationOrder::query()->findOrFail($attributes['rx_order_id'])->source_id,
            'source_verification_key' => 'rx-verification-'.Str::uuid(),
            'queue_ref' => 'inpatient-verification',
            'verification_state' => 'queued',
            'verifier_ref' => null,
            'queued_at' => now()->subMinutes(12),
            'verified_at' => null,
            'removed_at' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verification_state' => 'verified',
            'verified_at' => now()->subMinutes(3),
            'verifier_ref' => 'pharmacist-service-1',
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn (): array => [
            'verification_state' => 'removed',
            'removed_at' => now()->subMinutes(2),
        ]);
    }
}
