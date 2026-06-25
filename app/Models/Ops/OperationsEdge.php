<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationsEdge extends Model
{
    protected $table = 'ops.edges';

    protected $primaryKey = 'graph_edge_id';

    protected $guarded = [];

    protected $casts = [
        'weight' => 'decimal:4',
        'metadata' => 'array',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(OperationsNode::class, 'from_node_id', 'graph_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(OperationsNode::class, 'to_node_id', 'graph_node_id');
    }
}
