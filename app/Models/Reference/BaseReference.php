<?php

namespace App\Models\Reference;

use Illuminate\Database\Eloquent\Model;

abstract class BaseReference extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'code',
        'active_status',
        'created_by',
        'modified_by',
        'created_date',
        'modified_date',
        'is_deleted'
    ];

    protected $casts = [
        'active_status' => 'boolean',
        'is_deleted' => 'boolean',
        'created_date' => 'datetime',
        'modified_date' => 'datetime'
    ];

    public function scopeActive($query)
    {
        return $query->where('active_status', true)
                    ->where('is_deleted', false);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_date = $model->freshTimestamp();
            $model->modified_date = $model->freshTimestamp();
        });

        static::updating(function ($model) {
            $model->modified_date = $model->freshTimestamp();
        });
    }
}
