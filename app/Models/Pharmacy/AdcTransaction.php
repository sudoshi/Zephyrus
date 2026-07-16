<?php

namespace App\Models\Pharmacy;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use App\Models\Unit;
use Database\Factories\Pharmacy\AdcTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdcTransaction extends Model
{
    /** @use HasFactory<AdcTransactionFactory> */
    use HasFactory;

    protected $table = 'prod.adc_transactions';

    protected $primaryKey = 'adc_transaction_id';

    protected $guarded = [];

    protected $casts = [
        'is_controlled' => 'boolean',
        'quantity' => 'decimal:2',
        'occurred_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): AdcTransactionFactory
    {
        return AdcTransactionFactory::new();
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(AdcStation::class, 'adc_station_id', 'adc_station_id');
    }

    public function medicationOrder(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class, 'rx_order_id', 'rx_order_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function scopeOfType(Builder $query, string|array $types): Builder
    {
        return $query->whereIn($this->qualifyColumn('transaction_type'), (array) $types);
    }

    public function scopeControlled(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_controlled'), true);
    }

    public function scopeForStation(Builder $query, int|array $stationIds): Builder
    {
        return $query->whereIn($this->qualifyColumn('adc_station_id'), (array) $stationIds);
    }

    public function scopeForUnit(Builder $query, int|array $unitIds): Builder
    {
        return $query->whereIn($this->qualifyColumn('unit_id'), (array) $unitIds);
    }

    public function scopeOpenDiscrepancies(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('transaction_type'), 'discrepancy_open')
            ->whereNotExists(function ($resolved): void {
                $resolved->selectRaw('1')
                    ->from('prod.adc_transactions as resolved')
                    ->whereColumn('resolved.adc_station_id', 'prod.adc_transactions.adc_station_id')
                    ->whereColumn('resolved.discrepancy_key', 'prod.adc_transactions.discrepancy_key')
                    ->where('resolved.transaction_type', 'discrepancy_resolved');
            });
    }

    public function scopeStockouts(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('transaction_type'), 'stockout');
    }
}
