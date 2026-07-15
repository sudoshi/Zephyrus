<?php

namespace Database\Factories\Ancillary;

use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use Database\Factories\Ancillary\Concerns\CreatesAncillaryIntegrationFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AncillaryMilestone> */
class AncillaryMilestoneFactory extends Factory
{
    use CreatesAncillaryIntegrationFixtures;

    protected $model = AncillaryMilestone::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $occurredAt = now()->subMinutes(fake()->numberBetween(1, 120));

        return [
            'milestone_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => AncillaryOrder::factory()->radiology(),
            'milestone_code' => 'RAD_ORDERED',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt->copy()->addSeconds(fake()->numberBetween(1, 300)),
            'source_id' => fn (array $attributes): int => (int) AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->source_id,
            'canonical_event_id' => fn (array $attributes): int => $this->createCanonicalEventId(
                (int) $attributes['source_id'],
                'ancillary.radiology.order_placed',
                $occurredAt,
            ),
            'provenance_record_id' => null,
            'assertion_key' => 'factory-assertion-'.Str::uuid(),
            'source_rank' => 100,
            'metadata' => [],
        ];
    }

    public function forCode(string $code, string $department, int $ordinal, string $eventType): static
    {
        return $this->state(fn (array $attributes): array => [
            'milestone_code' => $code,
            'canonical_event_id' => $this->createCanonicalEventId(
                (int) $attributes['source_id'],
                $eventType,
                $attributes['occurred_at'],
            ),
        ]);
    }

    public function competingSource(int $sourceId, int $sourceRank): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_id' => $sourceId,
            'source_rank' => $sourceRank,
            'canonical_event_id' => $this->createCanonicalEventId(
                $sourceId,
                'ancillary.factory.competing_assertion',
                $attributes['occurred_at'],
            ),
        ]);
    }
}
