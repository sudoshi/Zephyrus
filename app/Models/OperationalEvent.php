<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalEvent extends Model
{
    protected $table = 'prod.operational_events';

    protected $primaryKey = 'operational_event_id';

    public const UPDATED_AT = null; // append-only; created_at only

    protected $fillable = ['event_id', 'type', 'encounter_ref', 'payload', 'occurred_at'];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];
}
