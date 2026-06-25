<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;

class StateSnapshot extends Model
{
    protected $table = 'ops.state_snapshots';

    protected $primaryKey = 'state_snapshot_id';

    protected $guarded = [];

    protected $casts = [
        'captured_at' => 'datetime',
        'node_count' => 'integer',
        'edge_count' => 'integer',
        'state_payload' => 'array',
        'metadata' => 'array',
    ];
}
