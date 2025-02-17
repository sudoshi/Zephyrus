<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessLayout extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'process_type',
        'layout_data',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'layout_data' => 'array',
    ];

    /**
     * Get the user that owns the layout.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
