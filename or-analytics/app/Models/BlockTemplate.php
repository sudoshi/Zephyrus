<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlockTemplate extends Model
{
    protected $table = 'prod.block_templates';
    protected $primaryKey = 'block_id';

    protected $fillable = [
        'room_id',
        'service_id',
        'surgeon_id',
        'group_id',
        'block_date',
        'start_time',
        'end_time',
        'is_public',
        'title',
        'abbreviation',
        'created_by',
        'modified_by',
        'is_deleted'
    ];

    protected $casts = [
        'block_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_public' => 'boolean',
        'is_deleted' => 'boolean'
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }

    public function surgeon(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'surgeon_id', 'provider_id');
    }

    public function utilization(): HasMany
    {
        return $this->hasMany(BlockUtilization::class, 'block_id', 'block_id');
    }

    public function getFormattedTimeAttribute(): string
    {
        return $this->start_time->format('H:i') . ' - ' . $this->end_time->format('H:i');
    }

    public function getDurationMinutesAttribute(): int
    {
        return $this->start_time->diffInMinutes($this->end_time);
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('block_date', '>=', now()->toDateString());
    }

    public function scopeForService($query, $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    public function scopeForSurgeon($query, $surgeonId)
    {
        return $query->where('surgeon_id', $surgeonId);
    }

    public function scopeForRoom($query, $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopePrivate($query)
    {
        return $query->where('is_public', false);
    }
}
