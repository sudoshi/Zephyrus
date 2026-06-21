<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CensusSnapshot extends Model
{
    protected $table = 'prod.census_snapshots';

    protected $primaryKey = 'census_snapshot_id';

    protected $fillable = [
        'unit_id', 'captured_at', 'staffed_beds', 'occupied',
        'available', 'blocked', 'acuity_adjusted_capacity',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'staffed_beds' => 'integer',
        'occupied' => 'integer',
        'available' => 'integer',
        'blocked' => 'integer',
        'acuity_adjusted_capacity' => 'integer',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }
}
