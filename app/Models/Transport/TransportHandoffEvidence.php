<?php

namespace App\Models\Transport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportHandoffEvidence extends Model
{
    protected $table = 'prod.transport_handoff_evidence';

    protected $primaryKey = 'transport_handoff_evidence_id';

    public const UPDATED_AT = null;

    protected $fillable = [
        'evidence_uuid', 'transport_request_id', 'handoff_to', 'receiver_role',
        'acceptance_status', 'accepted_at', 'handoff_summary', 'documents',
        'outstanding_risks', 'actor_user_id',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'documents' => 'array',
        'outstanding_risks' => 'array',
        'actor_user_id' => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TransportRequest::class, 'transport_request_id', 'transport_request_id');
    }
}
