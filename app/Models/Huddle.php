<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Huddle extends Model
{
    protected $table = 'prod.huddles';

    protected $primaryKey = 'huddle_id';

    protected $fillable = ['type', 'unit_id', 'service_date', 'status', 'facilitator_id', 'closed_at', 'is_deleted'];

    protected $casts = [
        'service_date' => 'date',
        'closed_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }
}
