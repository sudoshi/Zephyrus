<?php

namespace App\Models\Pharmacy;

use App\Casts\JsonObject;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Encounter;
use App\Models\Integration\Source;
use Database\Factories\Pharmacy\MedicationOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MedicationOrder extends Model
{
    /** @use HasFactory<MedicationOrderFactory> */
    use HasFactory;

    protected $table = 'prod.rx_orders';

    protected $primaryKey = 'rx_order_id';

    protected $guarded = [];

    protected $casts = [
        'is_controlled' => 'boolean',
        'is_hazardous' => 'boolean',
        'on_shortage' => 'boolean',
        'due_at' => 'immutable_datetime',
        'held_at' => 'immutable_datetime',
        'discontinued_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): MedicationOrderFactory
    {
        return MedicationOrderFactory::new();
    }

    public function ancillaryOrder(): BelongsTo
    {
        return $this->belongsTo(AncillaryOrder::class, 'ancillary_order_id', 'ancillary_order_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id', 'encounter_id');
    }

    public function formularyItem(): BelongsTo
    {
        return $this->belongsTo(FormularyItem::class, 'rx_formulary_id', 'rx_formulary_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class, 'rx_order_id', 'rx_order_id');
    }

    public function preparations(): HasMany
    {
        return $this->hasMany(Preparation::class, 'rx_order_id', 'rx_order_id');
    }

    public function dispenses(): HasMany
    {
        return $this->hasMany(Dispense::class, 'rx_order_id', 'rx_order_id');
    }

    public function administrations(): HasMany
    {
        return $this->hasMany(Administration::class, 'rx_order_id', 'rx_order_id');
    }

    public function adcTransactions(): HasMany
    {
        return $this->hasMany(AdcTransaction::class, 'rx_order_id', 'rx_order_id');
    }

    public function dischargeQueueItem(): HasOne
    {
        return $this->hasOne(DischargeQueueItem::class, 'rx_order_id', 'rx_order_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('order_status'), ['administered', 'discontinued', 'cancelled', 'completed']);
    }

    public function scopeClockClass(Builder $query, string|array $classes): Builder
    {
        return $query->whereIn($this->qualifyColumn('clock_class'), (array) $classes);
    }

    public function scopePreparationBranch(Builder $query, string|array $branches): Builder
    {
        return $query->whereIn($this->qualifyColumn('preparation_branch'), (array) $branches);
    }

    public function scopeControlled(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_controlled'), true);
    }

    public function scopeHazardous(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_hazardous'), true);
    }

    public function scopeOnShortage(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('on_shortage'), true);
    }

    public function scopeUnmapped(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('terminology_status'), 'unmapped_local');
    }

    public function scopeDischargeQueueable(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('clock_class'), 'discharge');
    }
}
