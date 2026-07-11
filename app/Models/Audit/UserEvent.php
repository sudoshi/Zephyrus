<?php

namespace App\Models\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEvent extends Model
{
    protected $table = 'audit.user_events';

    protected $primaryKey = 'event_cursor';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
        'changes' => 'array',
        'metadata' => 'array',
        'http_status' => 'integer',
        'schema_version' => 'integer',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
