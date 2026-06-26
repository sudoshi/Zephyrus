<?php

namespace App\Models\Evs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvsRequest extends Model
{
    protected $table = 'prod.evs_requests';

    protected $primaryKey = 'evs_request_id';

    protected $fillable = [
        'request_uuid',
        'request_type',
        'priority',
        'status',
        'room_id',
        'bed_id',
        'unit_id',
        'patient_ref',
        'encounter_ref',
        'location_label',
        'turn_type',
        'isolation_required',
        'requested_by',
        'requested_at',
        'needed_at',
        'assigned_at',
        'started_at',
        'completed_at',
        'assigned_team',
        'assigned_user_ref',
        'external_system',
        'external_id',
        'risk_flags',
        'completion_payload',
        'metadata',
        'created_by_user_id',
        'updated_by_user_id',
        'is_deleted',
    ];

    protected $casts = [
        'room_id' => 'integer',
        'bed_id' => 'integer',
        'unit_id' => 'integer',
        'isolation_required' => 'boolean',
        'requested_at' => 'datetime',
        'needed_at' => 'datetime',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'risk_flags' => 'array',
        'completion_payload' => 'array',
        'metadata' => 'array',
        'is_deleted' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(EvsEvent::class, 'evs_request_id', 'evs_request_id');
    }

    public function scopeActive($query)
    {
        return $query
            ->where('is_deleted', false)
            ->whereNotIn('status', ['completed', 'canceled', 'failed']);
    }

    public function scopeForType($query, ?string $type)
    {
        return $type ? $query->where('request_type', $type) : $query;
    }
}
