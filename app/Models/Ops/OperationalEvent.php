<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalEvent extends Model
{
    protected $table = 'ops.operational_events';

    protected $primaryKey = 'operational_event_id';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'scope' => 'array',
        'status' => 'array',
        'recommendation' => 'array',
        'relay' => 'array',
        'phi_policy' => 'array',
        'payload' => 'array',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(OperationalEventTarget::class, 'operational_event_id', 'operational_event_id');
    }

    public function entities(): HasMany
    {
        return $this->hasMany(OperationalEventEntity::class, 'operational_event_id', 'operational_event_id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(OperationalEventAcknowledgement::class, 'operational_event_id', 'operational_event_id');
    }
}
