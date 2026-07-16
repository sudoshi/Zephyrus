<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserExternalIdentity extends Model
{
    protected $table = 'prod.user_external_identities';

    protected $fillable = [
        'user_id', 'provider', 'provider_subject', 'provider_email_at_link', 'linked_at',
        'is_active', 'unlinked_at', 'unlinked_by_user_id', 'unlink_reason',
        'relinked_at', 'relinked_by_user_id', 'relink_reason',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'unlinked_at' => 'immutable_datetime',
            'relinked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(\App\Models\Governance\IdentityLinkEvent::class, 'external_identity_id');
    }
}
