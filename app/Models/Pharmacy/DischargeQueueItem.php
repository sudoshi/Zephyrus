<?php

namespace App\Models\Pharmacy;

use App\Casts\JsonObject;
use App\Models\Encounter;
use App\Models\Integration\Source;
use Database\Factories\Pharmacy\DischargeQueueItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DischargeQueueItem extends Model
{
    /** @use HasFactory<DischargeQueueItemFactory> */
    use HasFactory;

    protected $table = 'prod.rx_discharge_queue';

    protected $primaryKey = 'rx_discharge_queue_id';

    protected $guarded = [];

    protected $casts = [
        'status_changed_at' => 'immutable_datetime',
        'prior_auth_pending_at' => 'immutable_datetime',
        'verification_started_at' => 'immutable_datetime',
        'filling_started_at' => 'immutable_datetime',
        'ready_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
        'planned_discharge_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): DischargeQueueItemFactory
    {
        return DischargeQueueItemFactory::new();
    }

    public function medicationOrder(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class, 'rx_order_id', 'rx_order_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id', 'encounter_id');
    }

    public function scopePipeline(Builder $query, string|array $statuses): Builder
    {
        return $query->whereIn($this->qualifyColumn('pipeline_status'), (array) $statuses);
    }

    public function scopeOpenPipeline(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('pipeline_status'), ['ready', 'delivered']);
    }

    public function scopeDueBy(Builder $query, mixed $at): Builder
    {
        return $query->where($this->qualifyColumn('planned_discharge_at'), '<=', $at);
    }
}
