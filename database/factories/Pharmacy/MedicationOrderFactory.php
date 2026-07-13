<?php

namespace Database\Factories\Pharmacy;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Pharmacy\FormularyItem;
use App\Models\Pharmacy\MedicationOrder;
use Database\Factories\Pharmacy\Concerns\CreatesPharmacyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MedicationOrder> */
class MedicationOrderFactory extends Factory
{
    use CreatesPharmacyFixtures;

    protected $model = MedicationOrder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'rx_order_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => fn (): int => $this->createPharmacyOrderId(),
            'source_id' => fn (array $attributes): int => (int) AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->source_id,
            'source_order_key' => 'rx-order-'.Str::uuid(),
            'encounter_id' => fn (array $attributes): ?int => AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->encounter_id,
            'rx_formulary_id' => fn (): int => (int) $this->formulary('rx.acetaminophen_tab')->rx_formulary_id,
            'local_code' => fn (array $attributes): string => (string) FormularyItem::query()->findOrFail($attributes['rx_formulary_id'])->local_code,
            'rxnorm_cui' => fn (array $attributes): ?string => FormularyItem::query()->findOrFail($attributes['rx_formulary_id'])->rxnorm_cui,
            'ndc_code' => fn (array $attributes): ?string => FormularyItem::query()->findOrFail($attributes['rx_formulary_id'])->ndc_code,
            'terminology_status' => fn (array $attributes): string => (string) FormularyItem::query()->findOrFail($attributes['rx_formulary_id'])->terminology_status,
            'medication_label' => fn (array $attributes): string => (string) FormularyItem::query()->findOrFail($attributes['rx_formulary_id'])->label,
            'dosage_form' => fn (array $attributes): ?string => FormularyItem::query()->findOrFail($attributes['rx_formulary_id'])->dosage_form,
            'route' => fn (array $attributes): ?string => FormularyItem::query()->findOrFail($attributes['rx_formulary_id'])->default_route,
            'clock_class' => 'routine',
            'preparation_branch' => fn (array $attributes): string => (string) FormularyItem::query()->findOrFail($attributes['rx_formulary_id'])->default_prep_branch,
            'order_status' => 'ordered',
            'is_controlled' => false,
            'controlled_schedule' => null,
            'is_hazardous' => false,
            'on_shortage' => false,
            'due_at' => null,
            'held_at' => null,
            'discontinued_at' => null,
            'cancelled_at' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function forFormulary(string $formularyKey): static
    {
        return $this->state(fn (): array => [
            'rx_formulary_id' => (int) $this->formulary($formularyKey)->rx_formulary_id,
        ]);
    }

    public function stat(): static
    {
        return $this->forFormulary('rx.ceftriaxone_iv')->state(fn (): array => [
            'clock_class' => 'stat',
        ]);
    }

    public function firstDose(): static
    {
        return $this->forFormulary('rx.ondansetron_inj')->state(fn (): array => [
            'clock_class' => 'first_dose',
            'due_at' => now()->addMinutes(45),
        ]);
    }

    public function sepsis(): static
    {
        return $this->forFormulary('rx.ceftriaxone_iv')->state(fn (): array => [
            'clock_class' => 'sepsis',
        ]);
    }

    public function ivBatch(): static
    {
        return $this->forFormulary('rx.vancomycin_iv')->state(fn (): array => [
            'clock_class' => 'timed',
            'due_at' => now()->addHours(2),
        ]);
    }

    public function chemo(): static
    {
        return $this->forFormulary('rx.cyclophosphamide_iv')->state(fn (): array => [
            'clock_class' => 'timed',
            'due_at' => now()->addHours(4),
            'is_hazardous' => true,
        ]);
    }

    public function tpn(): static
    {
        return $this->forFormulary('rx.tpn_adult')->state(fn (): array => [
            'clock_class' => 'timed',
            'due_at' => now()->addHours(6),
        ]);
    }

    public function discharge(): static
    {
        return $this->forFormulary('rx.warfarin_tab')->state(fn (): array => [
            'clock_class' => 'discharge',
        ]);
    }

    public function controlled(): static
    {
        return $this->forFormulary('rx.morphine_inj')->state(fn (): array => [
            'is_controlled' => true,
            'controlled_schedule' => 'II',
        ]);
    }

    public function onShortage(): static
    {
        return $this->forFormulary('rx.heparin_infusion')->state(fn (): array => [
            'on_shortage' => true,
        ]);
    }

    public function discontinued(): static
    {
        return $this->state(fn (): array => [
            'order_status' => 'discontinued',
            'discontinued_at' => now(),
        ]);
    }

    public function unmappedLocal(): static
    {
        return $this->state(fn (): array => [
            'rx_formulary_id' => null,
            'local_code' => 'LOCAL_COMPOUND_'.Str::upper(Str::random(6)),
            'rxnorm_cui' => null,
            'ndc_code' => null,
            'terminology_status' => 'unmapped_local',
            'medication_label' => 'Locally compounded preparation',
            'dosage_form' => 'suspension',
            'route' => 'oral',
            'preparation_branch' => 'central',
        ]);
    }
}
