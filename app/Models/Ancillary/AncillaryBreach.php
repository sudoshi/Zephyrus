<?php

namespace App\Models\Ancillary;

use App\Casts\JsonObject;
use App\Models\Barrier;
use Database\Factories\Ancillary\AncillaryBreachFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AncillaryBreach extends Model
{
    /** @use HasFactory<AncillaryBreachFactory> */
    use HasFactory;

    protected $table = 'prod.ancillary_breaches';

    protected $primaryKey = 'ancillary_breach_id';

    protected $guarded = [];

    protected $casts = [
        'warning_at' => 'immutable_datetime',
        'breached_at' => 'immutable_datetime',
        'cleared_at' => 'immutable_datetime',
        'elapsed_minutes_at_open' => 'decimal:3',
        'elapsed_minutes_at_clear' => 'decimal:3',
        'last_evaluated_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): AncillaryBreachFactory
    {
        return AncillaryBreachFactory::new();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AncillaryOrder::class, 'ancillary_order_id', 'ancillary_order_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AncillarySlaDefinition::class, 'ancillary_sla_definition_id', 'ancillary_sla_definition_id');
    }

    public function startAssertion(): BelongsTo
    {
        return $this->belongsTo(AncillaryMilestone::class, 'start_assertion_id', 'ancillary_milestone_id');
    }

    public function stopAssertion(): BelongsTo
    {
        return $this->belongsTo(AncillaryMilestone::class, 'stop_assertion_id', 'ancillary_milestone_id');
    }

    public function barrier(): BelongsTo
    {
        return $this->belongsTo(Barrier::class, 'barrier_id', 'barrier_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'open');
    }
}
