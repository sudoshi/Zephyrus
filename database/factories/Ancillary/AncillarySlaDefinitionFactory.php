<?php

namespace Database\Factories\Ancillary;

use App\Models\Ancillary\AncillarySlaDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AncillarySlaDefinition> */
class AncillarySlaDefinitionFactory extends Factory
{
    protected $model = AncillarySlaDefinition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'definition_uuid' => (string) Str::uuid(),
            'department' => 'rad',
            'metric_key' => 'factory.rad.order_final.'.Str::uuid(),
            'label' => 'Factory imaging order to final',
            'start_milestone_code' => 'RAD_ORDERED',
            'stop_milestone_code' => 'RAD_FINAL',
            'priority' => 'stat',
            'patient_class' => null,
            'scope' => [],
            'statistic' => 'item_clock',
            'warning_minutes' => 90,
            'breach_minutes' => 120,
            'target_value' => null,
            'direction' => 'lower_is_better',
            'unit' => 'minutes',
            'effective_from' => now()->startOfDay(),
            'effective_to' => null,
            'version' => 1,
            'active' => true,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'definition_text' => 'RAD_ORDERED to RAD_FINAL for the factory fixture population.',
            'source_reference_id' => 'factory-policy',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
