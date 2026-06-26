<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class DataQualityFinding extends Model
{
    protected $table = 'ops.data_quality_findings';

    protected $primaryKey = 'data_quality_finding_id';

    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];
}
