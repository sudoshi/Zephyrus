<?php

namespace App\Models\Lab;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use Database\Factories\Lab\CriticalValueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CriticalValue extends Model
{
    /** @use HasFactory<CriticalValueFactory> */
    use HasFactory;

    protected $table = 'prod.lab_critical_values';

    protected $primaryKey = 'lab_critical_value_id';

    protected $guarded = [];

    protected $casts = [
        'identified_at' => 'immutable_datetime',
        'notified_at' => 'immutable_datetime',
        'acknowledged_at' => 'immutable_datetime',
        'escalated_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): CriticalValueFactory
    {
        return CriticalValueFactory::new();
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'lab_result_id', 'lab_result_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('callback_state'), ['acknowledged', 'closed']);
    }
}
