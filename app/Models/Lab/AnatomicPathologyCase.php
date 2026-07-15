<?php

namespace App\Models\Lab;

use App\Casts\JsonObject;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Encounter;
use App\Models\Integration\Source;
use App\Models\ORCase;
use Database\Factories\Lab\AnatomicPathologyCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnatomicPathologyCase extends Model
{
    /** @use HasFactory<AnatomicPathologyCaseFactory> */
    use HasFactory;

    protected $table = 'prod.ap_cases';

    protected $primaryKey = 'ap_case_id';

    protected $guarded = [];

    protected $casts = [
        'current_stage_at' => 'immutable_datetime',
        'specimen_out_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'grossed_at' => 'immutable_datetime',
        'processing_batch_at' => 'immutable_datetime',
        'slides_ready_at' => 'immutable_datetime',
        'diagnosed_at' => 'immutable_datetime',
        'signed_out_at' => 'immutable_datetime',
        'frozen_started_at' => 'immutable_datetime',
        'frozen_resulted_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): AnatomicPathologyCaseFactory
    {
        return AnatomicPathologyCaseFactory::new();
    }

    public function ancillaryOrder(): BelongsTo
    {
        return $this->belongsTo(AncillaryOrder::class, 'ancillary_order_id', 'ancillary_order_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function operatingCase(): BelongsTo
    {
        return $this->belongsTo(ORCase::class, 'case_id', 'case_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id', 'encounter_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('stage'), ['signed_out', 'cancelled']);
    }

    public function scopeFrozen(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('frozen_status'), ['not_applicable', 'cancelled']);
    }

    public function scopeForOperatingCase(Builder $query, int|array $caseIds): Builder
    {
        return $query->whereIn($this->qualifyColumn('case_id'), (array) $caseIds);
    }
}
