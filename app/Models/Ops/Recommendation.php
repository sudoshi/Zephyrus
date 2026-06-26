<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
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

    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class, 'recommendation_id', 'recommendation_id');
    }
}
