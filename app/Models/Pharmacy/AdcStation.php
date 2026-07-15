<?php

namespace App\Models\Pharmacy;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use App\Models\Unit;
use Database\Factories\Pharmacy\AdcStationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdcStation extends Model
{
    /** @use HasFactory<AdcStationFactory> */
    use HasFactory;

    protected $table = 'prod.adc_stations';

    protected $primaryKey = 'adc_station_id';

    protected $guarded = [];

    protected $casts = [
        'is_profiled' => 'boolean',
        'controlled_capable' => 'boolean',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): AdcStationFactory
    {
        return AdcStationFactory::new();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AdcTransaction::class, 'adc_station_id', 'adc_station_id');
    }

    public function dispenses(): HasMany
    {
        return $this->hasMany(Dispense::class, 'adc_station_id', 'adc_station_id');
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'operational');
    }

    public function scopeForUnit(Builder $query, int|array $unitIds): Builder
    {
        return $query->whereIn($this->qualifyColumn('unit_id'), (array) $unitIds);
    }
}
