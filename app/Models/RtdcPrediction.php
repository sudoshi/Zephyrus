<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RtdcPrediction extends Model
{
    protected $table = 'prod.rtdc_predictions';

    protected $primaryKey = 'rtdc_prediction_id';

    protected $fillable = [
        'unit_id', 'service_date', 'horizon',
        'discharges_definite', 'discharges_probable', 'discharges_possible', 'discharges_weighted',
        'demand_ed', 'demand_or', 'demand_transfer', 'demand_direct', 'demand_expected',
        'capacity_now', 'bed_need', 'status', 'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'service_date' => 'date',
        'discharges_weighted' => 'float',
        'bed_need' => 'integer',
        'capacity_now' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(RtdcPlan::class, 'rtdc_prediction_id', 'rtdc_prediction_id')->where('is_deleted', false);
    }
}
