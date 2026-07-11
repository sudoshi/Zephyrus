<?php

namespace App\Models\Ops;

use App\Models\Barrier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recommendation extends Model
{
    protected $table = 'ops.recommendations';

    protected $primaryKey = 'recommendation_id';

    protected $guarded = [];

    protected $casts = [
        'confidence' => 'decimal:4',
        'expected_impact' => 'array',
        'evidence' => 'array',
    ];

    public function actions(): HasMany
    {
        return $this->hasMany(OperationalAction::class, 'recommendation_id', 'recommendation_id');
    }

    /**
     * The open prod.barriers row this corrective-action draft targets (seam 3 of
     * the flow-reconciliation loop). Null for recommendations not born of a barrier.
     */
    public function barrier(): BelongsTo
    {
        return $this->belongsTo(Barrier::class, 'barrier_id', 'barrier_id');
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class, 'recommendation_id', 'recommendation_id');
    }
}
