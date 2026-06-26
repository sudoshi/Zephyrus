<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalAction extends Model
{
    protected $table = 'ops.actions';

    protected $primaryKey = 'action_id';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'completion_payload' => 'array',
        'approved_at' => 'datetime',
        'assigned_at' => 'datetime',
        'due_at' => 'datetime',
        'expires_at' => 'datetime',
        'executed_at' => 'datetime',
        'completed_at' => 'datetime',
        'expired_at' => 'datetime',
        'overridden_at' => 'datetime',
    ];

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class, 'recommendation_id', 'recommendation_id');
    }

    public function subjectNode(): BelongsTo
    {
        return $this->belongsTo(OperationsNode::class, 'subject_node_id', 'graph_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(OperationsNode::class, 'target_node_id', 'graph_node_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'action_id', 'action_id');
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class, 'action_id', 'action_id');
    }
}
