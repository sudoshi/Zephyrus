<?php

namespace App\Models\Transport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportRequest extends Model
{
    protected $table = 'prod.transport_requests';

    protected $primaryKey = 'transport_request_id';

    protected $fillable = [
        'request_uuid',
        'request_type',
        'priority',
        'status',
        'patient_ref',
        'encounter_ref',
        'origin',
        'destination',
        'transport_mode',
        'clinical_service',
        'requested_by',
        'requested_at',
        'needed_at',
        'assigned_at',
        'dispatched_at',
        'completed_at',
        'assigned_team',
        'assigned_vendor',
        'external_system',
        'external_id',
        'segments',
        'risk_flags',
        'handoff',
        'metadata',
        'created_by_user_id',
        'updated_by_user_id',
        'is_deleted',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'needed_at' => 'datetime',
        'assigned_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
        'segments' => 'array',
        'risk_flags' => 'array',
        'handoff' => 'array',
        'metadata' => 'array',
        'is_deleted' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(TransportEvent::class, 'transport_request_id', 'transport_request_id');
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
