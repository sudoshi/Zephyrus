<?php

namespace App\Models\Pharmacy;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use Database\Factories\Pharmacy\DispenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispense extends Model
{
    /** @use HasFactory<DispenseFactory> */
    use HasFactory;

    protected $table = 'prod.rx_dispenses';

    protected $primaryKey = 'rx_dispense_id';

    protected $guarded = [];

    protected $casts = [
        'dispensed_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
        'returned_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): DispenseFactory
    {
        return DispenseFactory::new();
    }

    public function medicationOrder(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class, 'rx_order_id', 'rx_order_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function adcStation(): BelongsTo
    {
        return $this->belongsTo(AdcStation::class, 'adc_station_id', 'adc_station_id');
    }

    public function scopePendingDelivery(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'dispensed')->whereNull($this->qualifyColumn('delivered_at'));
    }

    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'delivered');
    }

    public function scopeChannel(Builder $query, string|array $channels): Builder
    {
        return $query->whereIn($this->qualifyColumn('dispense_channel'), (array) $channels);
    }
}
