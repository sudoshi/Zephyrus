<?php

namespace Database\Factories\Pharmacy;

use App\Models\Pharmacy\FormularyItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FormularyItem> */
class FormularyItemFactory extends Factory
{
    protected $model = FormularyItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $suffix = Str::lower(Str::random(8));

        return [
            'formulary_uuid' => (string) Str::uuid(),
            'formulary_key' => "rx.factory_{$suffix}",
            'local_code' => 'FACTORY_'.Str::upper($suffix),
            'rxnorm_cui' => (string) fake()->numberBetween(100000, 999999),
            'ndc_code' => null,
            'terminology_status' => 'mapped',
            'label' => 'Factory medication '.$suffix,
            'therapeutic_class' => 'analgesic',
            'dosage_form' => 'tablet',
            'default_route' => 'oral',
            'default_prep_branch' => 'adc',
            'is_controlled' => false,
            'controlled_schedule' => null,
            'is_hazardous' => false,
            'is_high_alert' => false,
            'is_active' => true,
            'effective_from' => now()->startOfYear(),
            'effective_to' => null,
            'metadata' => [],
        ];
    }

    public function unmappedLocal(): static
    {
        return $this->state(fn (): array => [
            'rxnorm_cui' => null,
            'ndc_code' => null,
            'terminology_status' => 'unmapped_local',
        ]);
    }

    public function controlled(string $schedule = 'II'): static
    {
        return $this->state(fn (): array => [
            'is_controlled' => true,
            'controlled_schedule' => $schedule,
        ]);
    }

    public function hazardous(): static
    {
        return $this->state(fn (): array => [
            'is_hazardous' => true,
            'default_prep_branch' => 'iv_room',
            'dosage_form' => 'infusion',
            'default_route' => 'intravenous',
        ]);
    }
}
