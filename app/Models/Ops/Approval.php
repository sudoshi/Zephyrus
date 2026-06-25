<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    protected $table = 'ops.approvals';

    protected $primaryKey = 'approval_id';

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(OperationalAction::class, 'action_id', 'action_id');
    }
}
