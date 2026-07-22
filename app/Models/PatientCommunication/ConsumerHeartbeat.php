<?php

namespace App\Models\PatientCommunication;

use Illuminate\Database\Eloquent\Model;

class ConsumerHeartbeat extends Model
{
    protected $table = 'patient_communications.consumer_heartbeats';

    protected $primaryKey = 'consumer_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'consumer_key',
        'routing_policy_version',
        'worker_ref_digest',
        'status',
        'last_seen_at',
        'metadata',
    ];

    protected $hidden = [
        'worker_ref_digest',
        'metadata',
    ];

    protected $casts = [
        'last_seen_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];
}
