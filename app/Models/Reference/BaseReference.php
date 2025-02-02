<?php

namespace App\Models\Reference;

use Illuminate\Database\Eloquent\Model;

abstract class BaseReference extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'name',
        'code',
        'active_status',
        'created_by',
        'modified_by',
        'is_deleted'
    ];

    protected $casts = [
        'active_status' => 'boolean',
        'is_deleted' => 'boolean'
    ];

    public function scopeActive($query)
    {
        return $query->where('active_status', true)
                    ->where('is_deleted', false);
    }

}
