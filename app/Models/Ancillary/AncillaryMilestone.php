<?php

namespace App\Models\Ancillary;

use App\Casts\JsonObject;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Integration\ProvenanceRecord;
use App\Models\Integration\Source;
use Database\Factories\Ancillary\AncillaryMilestoneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AncillaryMilestone extends Model
{
    /** @use HasFactory<AncillaryMilestoneFactory> */
    use HasFactory;

    protected $table = 'prod.ancillary_milestones';

    protected $primaryKey = 'ancillary_milestone_id';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'source_rank' => 'integer',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): AncillaryMilestoneFactory
    {
        return AncillaryMilestoneFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Ancillary milestone assertions are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Ancillary milestone assertions are append-only.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AncillaryOrder::class, 'ancillary_order_id', 'ancillary_order_id');
    }

    public function milestoneType(): BelongsTo
    {
        return $this->belongsTo(AncillaryMilestoneType::class, 'milestone_code', 'code');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function canonicalEvent(): BelongsTo
    {
        return $this->belongsTo(CanonicalEventRecord::class, 'canonical_event_id', 'canonical_event_id');
    }

    public function provenance(): BelongsTo
    {
        return $this->belongsTo(ProvenanceRecord::class, 'provenance_record_id', 'provenance_record_id');
    }

    public function scopeForOrderAndCode(Builder $query, int $orderId, string $code): Builder
    {
        return $query
            ->where($this->qualifyColumn('ancillary_order_id'), $orderId)
            ->where($this->qualifyColumn('milestone_code'), $code);
    }
}
