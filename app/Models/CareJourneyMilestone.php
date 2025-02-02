<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareJourneyMilestone extends Model
{
    protected $fillable = [
        'case_id',
        'milestone_type',
        'status',
        'required',
        'completed_at',
        'completed_by',
        'notes'
    ];

    protected $casts = [
        'required' => 'boolean',
        'completed_at' => 'datetime'
    ];

    // Relationships
    public function case()
    {
        return $this->belongsTo(ORCase::class, 'case_id');
    }

    public function completedByUser()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    // Scopes
    public function scopeRequired($query)
    {
        return $query->where('required', true);
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopePending($query)
    {
        return $query->whereNull('completed_at');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('milestone_type', $type);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    // Helper Methods
    public function complete($userId = null, $notes = null)
    {
        $this->update([
            'status' => 'Completed',
            'completed_at' => now(),
            'completed_by' => $userId,
            'notes' => $notes ?? $this->notes
        ]);
    }

    public function verify($userId)
    {
        $this->update([
            'status' => 'Verified',
            'completed_by' => $userId,
            'completed_at' => now()
        ]);
    }

    public function requireAction($notes = null)
    {
        $this->update([
            'status' => 'Action_Required',
            'notes' => $notes ?? $this->notes
        ]);
    }

    public function reset()
    {
        $this->update([
            'status' => 'Pending',
            'completed_at' => null,
            'completed_by' => null
        ]);
    }

    public function isComplete()
    {
        return !is_null($this->completed_at);
    }

    public function isVerified()
    {
        return $this->status === 'Verified';
    }

    public function requiresAction()
    {
        return $this->status === 'Action_Required';
    }

    public function isPending()
    {
        return $this->status === 'Pending';
    }

    public function isInProgress()
    {
        return $this->status === 'In_Progress';
    }
}
