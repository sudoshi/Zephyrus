<?php

namespace App\Models\Flow;

use Illuminate\Database\Eloquent\Model;

class DischargeLoungeStay extends Model
{
    protected $table = 'prod.discharge_lounge_stays';

    protected $primaryKey = 'lounge_stay_id';

    protected $fillable = [
        'stay_uuid',
        'patient_ref',
        'encounter_ref',
        'unit_id',
        'origin_unit_label',
        'arrived_at',
        'expected_pickup_at',
        'departed_at',
        'departure_mode',
        'metadata',
        'created_by_user_id',
        'updated_by_user_id',
        'is_deleted',
    ];

    protected $casts = [
        'unit_id' => 'integer',
        'arrived_at' => 'datetime',
        'expected_pickup_at' => 'datetime',
        'departed_at' => 'datetime',
        'metadata' => 'array',
        'is_deleted' => 'boolean',
    ];

    /** Currently in the lounge: arrived, not yet picked up. */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false)->whereNull('departed_at');
    }
}
