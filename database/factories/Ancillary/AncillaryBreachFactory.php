<?php

namespace Database\Factories\Ancillary;

use App\Models\Ancillary\AncillaryBreach;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Ancillary\AncillarySlaDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AncillaryBreach> */
class AncillaryBreachFactory extends Factory
{
    protected $model = AncillaryBreach::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'breach_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => AncillaryOrder::factory()->radiology(),
            'ancillary_sla_definition_id' => AncillarySlaDefinition::factory(),
            'status' => 'open',
            'warning_at' => now()->subMinutes(30),
            'breached_at' => now()->subMinutes(20),
            'cleared_at' => null,
            'start_assertion_id' => fn (array $attributes): int => AncillaryMilestone::factory()
                ->for(AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id']), 'order')
                ->create()
                ->ancillary_milestone_id,
            'stop_assertion_id' => null,
            'elapsed_minutes_at_open' => 121,
            'elapsed_minutes_at_clear' => null,
            'barrier_id' => null,
            'opened_event_uuid' => (string) Str::uuid(),
            'cleared_event_uuid' => null,
            'last_evaluated_at' => now(),
            'metadata' => [],
        ];
    }
}
