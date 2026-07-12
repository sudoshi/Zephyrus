<?php

namespace App\Models\Ancillary;

use App\Casts\JsonObject;
use App\Models\Encounter;
use App\Models\Integration\Source;
use App\Models\Unit;
use Database\Factories\Ancillary\AncillaryOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AncillaryOrder extends Model
{
    /** @use HasFactory<AncillaryOrderFactory> */
    use HasFactory;

    protected $table = 'prod.ancillary_orders';

    protected $primaryKey = 'ancillary_order_id';

    protected $guarded = [];

    protected $casts = [
        'ordered_at' => 'immutable_datetime',
        'terminal_at' => 'immutable_datetime',
        'current_milestone_at' => 'immutable_datetime',
        'source_cutoff_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
    ];

    protected static function newFactory(): AncillaryOrderFactory
    {
        return AncillaryOrderFactory::new();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id', 'encounter_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function currentMilestoneType(): BelongsTo
    {
        return $this->belongsTo(AncillaryMilestoneType::class, 'current_milestone_code', 'code');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(AncillaryMilestone::class, 'ancillary_order_id', 'ancillary_order_id');
    }

    public function breaches(): HasMany
    {
        return $this->hasMany(AncillaryBreach::class, 'ancillary_order_id', 'ancillary_order_id');
    }

    public function radiologyExam(): HasOne
    {
        return $this->hasOne(\App\Models\Radiology\Exam::class, 'ancillary_order_id', 'ancillary_order_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('terminal_at'));
    }

    public function scopeDepartment(Builder $query, string|array $departments): Builder
    {
        return $query->whereIn($this->qualifyColumn('department'), (array) $departments);
    }

    public function scopeForUnit(Builder $query, int|array $unitIds): Builder
    {
        return $query->whereIn($this->qualifyColumn('unit_id'), (array) $unitIds);
    }

    public function scopeForEncounter(Builder $query, int|array $encounterIds): Builder
    {
        return $query->whereIn($this->qualifyColumn('encounter_id'), (array) $encounterIds);
    }

    public function scopePriority(Builder $query, string|array $priorities): Builder
    {
        return $query->whereIn($this->qualifyColumn('priority'), (array) $priorities);
    }

    public function scopeBreached(Builder $query): Builder
    {
        return $query->whereHas('breaches', fn (Builder $breaches): Builder => $breaches->where('status', 'open'));
    }

    public function scopeDischargeBlocking(Builder $query): Builder
    {
        return $query->where(function (Builder $blocking): void {
            $blocking
                ->where($this->qualifyColumn('priority'), 'discharge')
                ->orWhereRaw("COALESCE((prod.ancillary_orders.metadata->>'discharge_blocking')::boolean, false) = true");
        });
    }

    public function scopeFreshSince(Builder $query, mixed $cutoff): Builder
    {
        return $query->where($this->qualifyColumn('source_cutoff_at'), '>=', $cutoff);
    }

    public function scopeOwnedByDemo(Builder $query, string $owner): Builder
    {
        return $query->where($this->qualifyColumn('demo_owner'), $owner);
    }
}
