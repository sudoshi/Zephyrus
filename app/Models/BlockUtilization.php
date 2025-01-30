<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockUtilization extends Model
{
    protected $table = 'prod.block_utilization';

    protected $fillable = [
        'block_id',
        'date',
        'service_id',
        'location_id',
        'scheduled_minutes',
        'actual_minutes',
        'utilization_percentage',
        'cases_scheduled',
        'cases_performed',
        'prime_time_percentage',
        'non_prime_time_percentage',
        'created_by',
        'modified_by',
        'is_deleted'
    ];

    protected $casts = [
        'date' => 'date',
        'utilization_percentage' => 'decimal:2',
        'prime_time_percentage' => 'decimal:2',
        'non_prime_time_percentage' => 'decimal:2',
        'is_deleted' => 'boolean'
    ];

    public function blockTemplate(): BelongsTo
    {
        return $this->belongsTo(BlockTemplate::class, 'block_id', 'block_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    public function getFormattedScheduledTimeAttribute(): string
    {
        return floor($this->scheduled_minutes / 60) . ':' . str_pad($this->scheduled_minutes % 60, 2, '0', STR_PAD_LEFT);
    }

    public function getFormattedActualTimeAttribute(): string
    {
        return floor($this->actual_minutes / 60) . ':' . str_pad($this->actual_minutes % 60, 2, '0', STR_PAD_LEFT);
    }

    public function getFormattedUtilizationAttribute(): string
    {
        return number_format($this->utilization_percentage, 1) . '%';
    }

    public function getFormattedPrimeTimeAttribute(): string
    {
        return number_format($this->prime_time_percentage, 1) . '%';
    }

    public function getUnderutilizedAttribute(): bool
    {
        return $this->utilization_percentage < 75;
    }

    public function getOverutilizedAttribute(): bool
    {
        return $this->utilization_percentage > 100;
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForService($query, $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    public function scopeForLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeUnderutilized($query)
    {
        return $query->where('utilization_percentage', '<', 75);
    }

    public function scopeOverutilized($query)
    {
        return $query->where('utilization_percentage', '>', 100);
    }
}
