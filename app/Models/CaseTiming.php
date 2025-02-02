<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseTiming extends Model
{
    protected $fillable = [
        'case_id',
        'phase',
        'planned_start',
        'planned_duration',
        'actual_start',
        'actual_duration',
        'variance'
    ];

    protected $casts = [
        'planned_start' => 'datetime',
        'actual_start' => 'datetime',
        'planned_duration' => 'integer',
        'actual_duration' => 'integer',
        'variance' => 'integer'
    ];

    // Relationships
    public function case()
    {
        return $this->belongsTo(ORCase::class, 'case_id');
    }

    // Scopes
    public function scopeByPhase($query, $phase)
    {
        return $query->where('phase', $phase);
    }

    public function scopeInProgress($query)
    {
        return $query->whereNotNull('actual_start')
                    ->whereNull('actual_duration');
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('actual_duration');
    }

    public function scopeDelayed($query, $threshold = 0)
    {
        return $query->whereNotNull('variance')
                    ->where('variance', '>', $threshold);
    }

    public function scopeScheduledBetween($query, $start, $end)
    {
        return $query->whereBetween('planned_start', [$start, $end]);
    }

    // Helper Methods
    public function start()
    {
        $this->update([
            'actual_start' => now()
        ]);
    }

    public function complete()
    {
        if ($this->actual_start) {
            $duration = now()->diffInMinutes($this->actual_start);
            $variance = $duration - $this->planned_duration;
            
            $this->update([
                'actual_duration' => $duration,
                'variance' => $variance
            ]);
        }
    }

    public function reset()
    {
        $this->update([
            'actual_start' => null,
            'actual_duration' => null,
            'variance' => null
        ]);
    }

    public function reschedule($newStart, $newDuration = null)
    {
        $this->update([
            'planned_start' => $newStart,
            'planned_duration' => $newDuration ?? $this->planned_duration,
            'actual_start' => null,
            'actual_duration' => null,
            'variance' => null
        ]);
    }

    // Computed Properties
    public function getPlannedEndAttribute()
    {
        return $this->planned_start->addMinutes($this->planned_duration);
    }

    public function getActualEndAttribute()
    {
        if ($this->actual_start && $this->actual_duration) {
            return $this->actual_start->addMinutes($this->actual_duration);
        }
        return null;
    }

    public function getIsDelayedAttribute()
    {
        return $this->variance > 0;
    }

    public function getIsOnTimeAttribute()
    {
        return $this->variance <= 0;
    }

    public function getProgressPercentageAttribute()
    {
        if (!$this->actual_start) {
            return 0;
        }

        if ($this->actual_duration) {
            return 100;
        }

        $elapsed = now()->diffInMinutes($this->actual_start);
        return min(round(($elapsed / $this->planned_duration) * 100), 100);
    }

    public function getRemainingTimeAttribute()
    {
        if (!$this->actual_start || $this->actual_duration) {
            return 0;
        }

        $elapsed = now()->diffInMinutes($this->actual_start);
        return max($this->planned_duration - $elapsed, 0);
    }

    // Status Checks
    public function isPreOp()
    {
        return $this->phase === 'Pre_Procedure';
    }

    public function isProcedure()
    {
        return $this->phase === 'Procedure';
    }

    public function isRecovery()
    {
        return $this->phase === 'Recovery';
    }

    public function isRoomTurnover()
    {
        return $this->phase === 'Room_Turnover';
    }

    public function isInProgress()
    {
        return !is_null($this->actual_start) && is_null($this->actual_duration);
    }

    public function isCompleted()
    {
        return !is_null($this->actual_duration);
    }

    public function isPending()
    {
        return is_null($this->actual_start);
    }
}
