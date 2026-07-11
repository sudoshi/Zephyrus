<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Barrier extends Model
{
    protected $table = 'prod.barriers';

    protected $primaryKey = 'barrier_id';

    public const CATEGORIES = ['medical', 'logistical', 'placement', 'social'];

    protected $fillable = [
        'encounter_id', 'unit_id', 'category', 'reason_code',
        'description', 'owner', 'status', 'opened_at', 'resolved_at', 'is_deleted',
        // seam 3: the corrective-action (PDSA) cycle that answers this barrier,
        // stamped by the P3 executor on approval.
        'pdsa_cycle_id',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    /**
     * The corrective-action PDSA cycle materialized when this barrier's governed
     * draft was approved (seam 3). Null until a corrective action is approved.
     */
    public function resolutionCycle(): BelongsTo
    {
        return $this->belongsTo(PdsaCycle::class, 'pdsa_cycle_id', 'pdsa_cycle_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open')->where('is_deleted', false);
    }
}
