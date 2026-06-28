<?php

namespace App\Models\Eddy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EddyConversation extends Model
{
    protected $table = 'eddy.eddy_conversations';

    protected $primaryKey = 'eddy_conversation_id';

    protected $guarded = [];

    protected $casts = [
        'pinned_context' => 'array',
        'archived_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(EddyMessage::class, 'eddy_conversation_id', 'eddy_conversation_id');
    }

    /** The only isolation enforcement — every conversation read is user-scoped. */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
