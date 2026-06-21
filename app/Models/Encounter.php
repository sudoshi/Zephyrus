<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Encounter extends Model
{
    protected $table = 'prod.encounters';

    protected $primaryKey = 'encounter_id';

    protected $fillable = [
        'patient_ref', 'unit_id', 'bed_id', 'admitted_at', 'expected_discharge_date',
        'acuity_tier', 'status', 'discharged_at', 'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'admitted_at' => 'datetime',
        'discharged_at' => 'datetime',
        'expected_discharge_date' => 'date',
        'acuity_tier' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'bed_id', 'bed_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('is_deleted', false);
    }
}
