<?php

namespace App\Models\Pharmacy;

use App\Casts\JsonObject;
use Database\Factories\Pharmacy\FormularyItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormularyItem extends Model
{
    /** @use HasFactory<FormularyItemFactory> */
    use HasFactory;

    protected $table = 'hosp_ref.rx_formulary';

    protected $primaryKey = 'rx_formulary_id';

    protected $guarded = [];

    protected $casts = [
        'is_controlled' => 'boolean',
        'is_hazardous' => 'boolean',
        'is_high_alert' => 'boolean',
        'is_active' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): FormularyItemFactory
    {
        return FormularyItemFactory::new();
    }

    public function medicationOrders(): HasMany
    {
        return $this->hasMany(MedicationOrder::class, 'rx_formulary_id', 'rx_formulary_id');
    }

    public function scopeActiveAt(Builder $query, mixed $at): Builder
    {
        return $query
            ->where($this->qualifyColumn('is_active'), true)
            ->where($this->qualifyColumn('effective_from'), '<=', $at)
            ->where(function (Builder $range) use ($at): void {
                $range->whereNull($this->qualifyColumn('effective_to'))
                    ->orWhere($this->qualifyColumn('effective_to'), '>', $at);
            });
    }

    public function scopeControlled(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_controlled'), true);
    }

    public function scopeHazardous(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_hazardous'), true);
    }

    public function scopeUnmapped(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('terminology_status'), 'unmapped_local');
    }
}
