<?php

namespace App\Models\Lab;

use App\Casts\JsonObject;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Encounter;
use App\Models\Integration\Source;
use App\Models\ORCase;
use Database\Factories\Lab\BloodBankReadinessFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodBankReadiness extends Model
{
    /** @use HasFactory<BloodBankReadinessFactory> */
    use HasFactory;

    protected $table = 'prod.bb_readiness';

    protected $primaryKey = 'bb_readiness_id';

    protected $guarded = [];

    protected $casts = [
        'units_requested' => 'integer',
        'units_allocated' => 'integer',
        'units_issued' => 'integer',
        'ordered_at' => 'immutable_datetime',
        'needed_by' => 'immutable_datetime',
        'type_screen_ready_at' => 'immutable_datetime',
        'crossmatch_ready_at' => 'immutable_datetime',
        'allocated_at' => 'immutable_datetime',
        'issued_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'mtp_activated_at' => 'immutable_datetime',
        'mtp_closed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): BloodBankReadinessFactory
    {
        return BloodBankReadinessFactory::new();
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

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('readiness_state'), ['issued', 'cancelled', 'complete']);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('readiness_state'), ['crossmatch_ready', 'allocated', 'issued', 'complete']);
    }

    public function scopeMtpActive(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('mtp_activated_at'))->whereNull($this->qualifyColumn('mtp_closed_at'));
    }

    public function scopeForOperatingCase(Builder $query, int|array $caseIds): Builder
    {
        return $query->whereIn($this->qualifyColumn('case_id'), (array) $caseIds);
    }
}
