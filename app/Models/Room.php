<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    public $timestamps = false;
    protected $table = 'prod.room';
    protected $primaryKey = 'room_id';

    protected $fillable = [
        'location_id',
        'name',
        'room_type',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ORCase::class, 'room_id', 'room_id');
    }

    public function blockTemplates(): HasMany
    {
        return $this->hasMany(BlockTemplate::class, 'room_id', 'room_id');
    }

    public function utilization(): HasMany
    {
        return $this->hasMany(RoomUtilization::class, 'room_id', 'room_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active_status', true)
                    ->where('is_deleted', false);
    }

    public function scopeOperatingRooms($query)
    {
        return $query->where('room_type', 'OR');
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
