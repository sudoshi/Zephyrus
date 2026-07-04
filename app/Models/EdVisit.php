<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdVisit extends Model
{
    protected $table = 'prod.ed_visits';

    protected $primaryKey = 'ed_visit_id';

    protected $guarded = [];

    protected $casts = [
        'arrived_at' => 'datetime',
        'triaged_at' => 'datetime',
        'esi_level' => 'integer',
        'provider_seen_at' => 'datetime',
        'admit_decision_at' => 'datetime',
        'bed_assigned_at' => 'datetime',
        'departed_at' => 'datetime',
        'is_ventilated' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }
}
