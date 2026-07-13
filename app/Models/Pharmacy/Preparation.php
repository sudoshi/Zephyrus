<?php

namespace App\Models\Pharmacy;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use Database\Factories\Pharmacy\PreparationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Preparation extends Model
{
    /** @use HasFactory<PreparationFactory> */
    use HasFactory;

    protected $table = 'prod.rx_preps';

    protected $primaryKey = 'rx_prep_id';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'checked_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'bud_expires_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): PreparationFactory
    {
        return PreparationFactory::new();
    }

    public function medicationOrder(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class, 'rx_order_id', 'rx_order_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('prep_state'), ['pending', 'in_progress']);
    }

    public function scopeOfType(Builder $query, string|array $types): Builder
    {
        return $query->whereIn($this->qualifyColumn('prep_type'), (array) $types);
    }

    public function scopeForBatch(Builder $query, string $batchRef): Builder
    {
        return $query->where($this->qualifyColumn('batch_ref'), $batchRef);
    }
}
