<?php

namespace App\Models\Governance;

use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityLinkEvent extends Model
{
    protected $table = 'governance.identity_link_events';

    protected $primaryKey = 'identity_link_event_uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserExternalIdentity::class, 'external_identity_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
