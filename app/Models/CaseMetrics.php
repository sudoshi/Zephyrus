<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseMetrics extends Model
{
    protected $table = 'prod.case_metrics';
    protected $primaryKey = 'case_id';
    public $incrementing = false;

    protected $fillable = [
        'case_id',
        'turnover_time',
        'utilization_percentage',
        'in_block_time',
        'out_of_block_time',
        'prime_time_minutes',
        'non_prime_time_minutes',
        'late_start_minutes',
        'early_finish_minutes',
        'created_by',
        'modified_by',
        'is_deleted'
    ];

    protected $casts = [
        'utilization_percentage' => 'decimal:2',
        'is_deleted' => 'boolean'
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ORCase::class, 'case_id', 'case_id');
    }

    public function getFormattedTurnoverTimeAttribute(): string
    {
        return $this->turnover_time ? floor($this->turnover_time / 60) . ':' . str_pad($this->turnover_time % 60, 2, '0', STR_PAD_LEFT) : '0:00';
    }

    public function getFormattedUtilizationAttribute(): string
    {
        return number_format($this->utilization_percentage, 1) . '%';
    }

    public function getIsLateStartAttribute(): bool
    {
        return $this->late_start_minutes > 0;
    }

    public function getIsEarlyFinishAttribute(): bool
    {
        return $this->early_finish_minutes > 0;
    }

    public function getIsPrimeTimeAttribute(): bool
    {
        return $this->prime_time_minutes > 0;
    }

    public function getTotalTimeAttribute(): int
    {
        return $this->prime_time_minutes + $this->non_prime_time_minutes;
    }

    public function getPrimeTimePercentageAttribute(): float
    {
        $total = $this->getTotalTimeAttribute();
        return $total > 0 ? ($this->prime_time_minutes / $total) * 100 : 0;
    }
}
