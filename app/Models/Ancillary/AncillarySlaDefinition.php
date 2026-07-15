<?php

namespace App\Models\Ancillary;

use App\Casts\JsonObject;
use App\Models\User;
use Database\Factories\Ancillary\AncillarySlaDefinitionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AncillarySlaDefinition extends Model
{
    /** @use HasFactory<AncillarySlaDefinitionFactory> */
    use HasFactory;

    protected $table = 'prod.ancillary_sla_definitions';

    protected $primaryKey = 'ancillary_sla_definition_id';

    protected $guarded = [];

    protected $casts = [
        'scope' => JsonObject::class,
        'warning_minutes' => 'integer',
        'breach_minutes' => 'integer',
        'target_value' => 'decimal:4',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'version' => 'integer',
        'active' => 'boolean',
        'approved_at' => 'immutable_datetime',
    ];

    protected static function newFactory(): AncillarySlaDefinitionFactory
    {
        return AncillarySlaDefinitionFactory::new();
    }

    public function startMilestoneType(): BelongsTo
    {
        return $this->belongsTo(AncillaryMilestoneType::class, 'start_milestone_code', 'code');
    }

    public function stopMilestoneType(): BelongsTo
    {
        return $this->belongsTo(AncillaryMilestoneType::class, 'stop_milestone_code', 'code');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id', 'id');
    }

    public function breaches(): HasMany
    {
        return $this->hasMany(AncillaryBreach::class, 'ancillary_sla_definition_id', 'ancillary_sla_definition_id');
    }

    public function scopeActiveAt(Builder $query, mixed $at): Builder
    {
        return $query
            ->where($this->qualifyColumn('active'), true)
            ->where($this->qualifyColumn('effective_from'), '<=', $at)
            ->where(function (Builder $effective) use ($at): void {
                $effective
                    ->whereNull($this->qualifyColumn('effective_to'))
                    ->orWhere($this->qualifyColumn('effective_to'), '>', $at);
            });
    }

    public function scopeForPopulation(
        Builder $query,
        string $department,
        ?string $priority = null,
        ?string $patientClass = null,
    ): Builder {
        return $query
            ->where($this->qualifyColumn('department'), $department)
            ->where(function (Builder $scope) use ($priority): void {
                $scope->whereNull($this->qualifyColumn('priority'));
                if ($priority !== null) {
                    $scope->orWhere($this->qualifyColumn('priority'), $priority);
                }
            })
            ->where(function (Builder $scope) use ($patientClass): void {
                $scope->whereNull($this->qualifyColumn('patient_class'));
                if ($patientClass !== null) {
                    $scope->orWhere($this->qualifyColumn('patient_class'), $patientClass);
                }
            });
    }
}
