<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationsNode extends Model
{
    protected $table = 'ops.nodes';

    protected $primaryKey = 'graph_node_id';

    protected $guarded = [];

    protected $casts = [
        'current_state' => 'array',
        'metadata' => 'array',
        'last_observed_at' => 'datetime',
        'is_active' => 'boolean',
        'source_priority' => 'integer',
    ];

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(OperationsEdge::class, 'from_node_id', 'graph_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(OperationsEdge::class, 'to_node_id', 'graph_node_id');
    }
}
