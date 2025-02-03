<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    public $timestamps = true;
    protected $table = 'prod.rooms';
    protected $primaryKey = 'room_id';

    protected $fillable = [
        'location_id',
        'name',
        'type',
        'active_status',
        'created_by',
        'modified_by',
        'created_at',
        'updated_at',
        'is_deleted'
    ];

    protected $casts = [
        'active_status' => 'boolean',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
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
        return $query->where('type', 'OR');
    }
}
