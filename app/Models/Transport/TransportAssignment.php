<?php

namespace App\Models\Transport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportAssignment extends Model
{
    protected $table = 'prod.transport_assignments';

    protected $primaryKey = 'transport_assignment_id';

    protected $fillable = [
        'assignment_uuid', 'transport_request_id', 'transport_resource_id', 'capacity_units',
        'status', 'reserved_from', 'released_at', 'assigned_by_user_id', 'released_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'capacity_units' => 'integer',
        'reserved_from' => 'datetime',
        'released_at' => 'datetime',
        'assigned_by_user_id' => 'integer',
        'released_by_user_id' => 'integer',
        'metadata' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TransportRequest::class, 'transport_request_id', 'transport_request_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(TransportResource::class, 'transport_resource_id', 'transport_resource_id');
    }
}
