<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Barrier extends Model
{
    protected $table = 'prod.barriers';

    protected $primaryKey = 'barrier_id';

    public const CATEGORIES = ['medical', 'logistical', 'placement', 'social'];

    protected $fillable = [
        'encounter_id', 'unit_id', 'category', 'reason_code',
        'description', 'owner', 'status', 'opened_at', 'resolved_at', 'is_deleted',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open')->where('is_deleted', false);
    }
}
