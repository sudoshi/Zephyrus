<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdsaCycle extends Model
{
    protected $table = 'prod.pdsa_cycles';

    protected $primaryKey = 'pdsa_cycle_id';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(\App\Models\Ops\Intervention::class, 'pdsa_cycle_id', 'pdsa_cycle_id');
    }
}
