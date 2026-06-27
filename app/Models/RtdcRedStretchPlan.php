<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtdcRedStretchPlan extends Model
{
    protected $table = 'prod.rtdc_red_stretch_plans';

    protected $primaryKey = 'rtdc_red_stretch_plan_id';

    protected $fillable = ['unit_id', 'plan', 'updated_by'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }
}
