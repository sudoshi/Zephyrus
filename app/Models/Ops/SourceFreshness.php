<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class SourceFreshness extends Model
{
    protected $table = 'ops.source_freshness';

    protected $primaryKey = 'source_freshness_id';

    protected $guarded = [];

    protected $casts = [
        'latest_observed_at' => 'datetime',
        'checked_at' => 'datetime',
        'record_count' => 'integer',
        'expected_lag_minutes' => 'integer',
        'warning_lag_minutes' => 'integer',
        'metadata' => 'array',
    ];
}
