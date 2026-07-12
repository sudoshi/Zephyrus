<?php

namespace App\Models\Radiology;

use App\Casts\JsonObject;
use App\Models\Integration\Source;
use Database\Factories\Radiology\CriticalResultFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CriticalResult extends Model
{
    /** @use HasFactory<CriticalResultFactory> */
    use HasFactory;

    protected $table = 'prod.rad_critical_results';

    protected $primaryKey = 'rad_critical_result_id';

    protected $guarded = [];

    protected $casts = [
        'identified_at' => 'immutable_datetime', 'notified_at' => 'immutable_datetime',
        'acknowledged_at' => 'immutable_datetime', 'escalated_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime', 'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): CriticalResultFactory
    {
        return CriticalResultFactory::new();
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'rad_exam_id', 'rad_exam_id');
    }

    public function read(): BelongsTo
    {
        return $this->belongsTo(Read::class, 'rad_read_id', 'rad_read_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('policy_state'), ['acknowledged', 'closed']);
    }
}
