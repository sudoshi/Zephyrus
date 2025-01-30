<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomUtilization extends Model
{
    protected $table = 'prod.room_utilization';

    protected $fillable = [
        'room_id',
        'date',
        'available_minutes',
        'utilized_minutes',
        'turnover_minutes',
        'utilization_percentage',
        'cases_performed',
        'avg_case_duration',
        'created_by',
        'modified_by',
        'is_deleted'
    ];

    protected $casts = [
        'date' => 'date',
        'utilization_percentage' => 'decimal:2',
        'is_deleted' => 'boolean'
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }

    public function getFormattedAvailableTimeAttribute(): string
    {
        return floor($this->available_minutes / 60) . ':' . str_pad($this->available_minutes % 60, 2, '0', STR_PAD_LEFT);
    }

    public function getFormattedUtilizedTimeAttribute(): string
    {
        return floor($this->utilized_minutes / 60) . ':' . str_pad($this->utilized_minutes % 60, 2, '0', STR_PAD_LEFT);
    }

    public function getFormattedTurnoverTimeAttribute(): string
    {
        return floor($this->turnover_minutes / 60) . ':' . str_pad($this->turnover_minutes % 60, 2, '0', STR_PAD_LEFT);
    }

    public function getFormattedAvgCaseDurationAttribute(): string
    {
        return floor($this->avg_case_duration / 60) . ':' . str_pad($this->avg_case_duration % 60, 2, '0', STR_PAD_LEFT);
    }

    public function getFormattedUtilizationAttribute(): string
    {
        return number_format($this->utilization_percentage, 1) . '%';
    }

    public function getUnderutilizedAttribute(): bool
    {
        return $this->utilization_percentage < 75;
    }

    public function getOverutilizedAttribute(): bool
    {
        return $this->utilization_percentage > 100;
    }

    public function getHighTurnoverAttribute(): bool
    {
        return ($this->turnover_minutes / $this->cases_performed) > 30;
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForRoom($query, $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    public function scopeUnderutilized($query)
    {
        return $query->where('utilization_percentage', '<', 75);
    }

    public function scopeOverutilized($query)
    {
        return $query->where('utilization_percentage', '>', 100);
    }

    public function scopeHighTurnover($query)
    {
        return $query->whereRaw('turnover_minutes / cases_performed > 30');
    }
}
