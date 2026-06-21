<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bed extends Model
{
    protected $table = 'prod.beds';

    protected $primaryKey = 'bed_id';

    protected $fillable = [
        'unit_id', 'label', 'status', 'bed_type',
        'isolation_capable', 'created_by', 'modified_by', 'is_deleted',
    ];

    protected $casts = [
        'isolation_capable' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available')->where('is_deleted', false);
    }
}
