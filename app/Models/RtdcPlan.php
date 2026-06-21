<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtdcPlan extends Model
{
    protected $table = 'prod.rtdc_plans';

    protected $primaryKey = 'rtdc_plan_id';

    protected $fillable = [
        'rtdc_prediction_id', 'action_text', 'owner', 'due_at', 'status', 'created_by', 'is_deleted',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(RtdcPrediction::class, 'rtdc_prediction_id', 'rtdc_prediction_id');
    }
}
