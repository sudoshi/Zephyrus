<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtdcReconciliation extends Model
{
    protected $table = 'prod.rtdc_reconciliations';

    protected $primaryKey = 'rtdc_reconciliation_id';

    protected $fillable = [
        'unit_id', 'service_date', 'predicted_discharges', 'actual_discharges',
        'predicted_admissions', 'actual_admissions', 'reliability_score',
    ];

    protected $casts = [
        'service_date' => 'date',
        'predicted_discharges' => 'float',
        'reliability_score' => 'float',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }
}
