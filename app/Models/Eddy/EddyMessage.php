<?php

namespace App\Models\Eddy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EddyMessage extends Model
{
    protected $table = 'eddy.eddy_messages';

    protected $primaryKey = 'eddy_message_id';

    /** Messages are append-only — created_at managed, no updated_at column. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EddyConversation::class, 'eddy_conversation_id', 'eddy_conversation_id');
    }
}
