<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseTransport extends Model
{
    protected $table = 'case_transport';

    protected $fillable = [
        'case_id',
        'transport_type',
        'status',
        'location_from',
        'location_to',
        'assigned_to',
        'planned_time',
        'actual_start',
        'actual_end'
    ];

    protected $casts = [
        'planned_time' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime'
    ];

    // Relationships
    public function case()
    {
        return $this->belongsTo(ORCase::class, 'case_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'Pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'In_Progress');
    }

    public function scopeComplete($query)
    {
        return $query->where('status', 'Complete');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('transport_type', $type);
    }

    public function scopeAssignedToUser($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeScheduledBetween($query, $start, $end)
    {
        return $query->whereBetween('planned_time', [$start, $end]);
    }

    // Helper Methods
    public function start($userId = null)
    {
        $this->update([
            'status' => 'In_Progress',
            'actual_start' => now(),
            'assigned_to' => $userId ?? $this->assigned_to
        ]);
    }

    public function complete()
    {
        $this->update([
            'status' => 'Complete',
            'actual_end' => now()
        ]);
    }

    public function reassign($userId)
    {
        $this->update([
            'assigned_to' => $userId,
            'status' => 'Pending'
        ]);
    }

    public function reset()
    {
        $this->update([
            'status' => 'Pending',
            'actual_start' => null,
            'actual_end' => null
        ]);
    }

    public function getDurationAttribute()
    {
        if ($this->actual_start && $this->actual_end) {
            return $this->actual_end->diffInMinutes($this->actual_start);
        }
        return null;
    }

    public function getDelayAttribute()
    {
        if ($this->actual_start) {
            return $this->actual_start->diffInMinutes($this->planned_time);
        }
        return null;
    }

    public function isDelayed()
    {
        return $this->actual_start && $this->actual_start->gt($this->planned_time);
    }

    public function isPending()
    {
        return $this->status === 'Pending';
    }

    public function isInProgress()
    {
        return $this->status === 'In_Progress';
    }

    public function isComplete()
    {
        return $this->status === 'Complete';
    }

    public function isPreProcedure()
    {
        return $this->transport_type === 'Pre_Procedure';
    }

    public function isPostProcedure()
    {
        return $this->transport_type === 'Post_Procedure';
    }
}
