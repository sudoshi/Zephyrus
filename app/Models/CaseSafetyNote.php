<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseSafetyNote extends Model
{
    protected $fillable = [
        'case_id',
        'note_type',
        'content',
        'severity',
        'created_by',
        'acknowledged_by',
        'acknowledged_at'
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime'
    ];

    // Constants
    const TYPE_SAFETY_ALERT = 'Safety_Alert';
    const TYPE_BARRIER = 'Barrier';
    const TYPE_GENERAL = 'General';

    const SEVERITY_LOW = 'Low';
    const SEVERITY_MEDIUM = 'Medium';
    const SEVERITY_HIGH = 'High';
    const SEVERITY_CRITICAL = 'Critical';

    // Relationships
    public function case()
    {
        return $this->belongsTo(ORCase::class, 'case_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    // Scopes
    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }

    public function scopeAcknowledged($query)
    {
        return $query->whereNotNull('acknowledged_at');
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('note_type', $type);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('severity', [self::SEVERITY_CRITICAL, self::SEVERITY_HIGH]);
    }

    public function scopeCreatedBy($query, $userId)
    {
        return $query->where('created_by', $userId);
    }

    public function scopeAcknowledgedBy($query, $userId)
    {
        return $query->where('acknowledged_by', $userId);
    }

    // Helper Methods
    public function acknowledge($userId)
    {
        $this->update([
            'acknowledged_by' => $userId,
            'acknowledged_at' => now()
        ]);
    }

    public function escalate()
    {
        $severities = [
            self::SEVERITY_LOW => self::SEVERITY_MEDIUM,
            self::SEVERITY_MEDIUM => self::SEVERITY_HIGH,
            self::SEVERITY_HIGH => self::SEVERITY_CRITICAL
        ];

        if (isset($severities[$this->severity])) {
            $this->update([
                'severity' => $severities[$this->severity],
                'acknowledged_at' => null,
                'acknowledged_by' => null
            ]);
        }
    }

    public function deescalate()
    {
        $severities = [
            self::SEVERITY_CRITICAL => self::SEVERITY_HIGH,
            self::SEVERITY_HIGH => self::SEVERITY_MEDIUM,
            self::SEVERITY_MEDIUM => self::SEVERITY_LOW
        ];

        if (isset($severities[$this->severity])) {
            $this->update(['severity' => $severities[$this->severity]]);
        }
    }

    // Status Checks
    public function isAcknowledged()
    {
        return !is_null($this->acknowledged_at);
    }

    public function isCritical()
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    public function isHighPriority()
    {
        return in_array($this->severity, [self::SEVERITY_CRITICAL, self::SEVERITY_HIGH]);
    }

    public function isSafetyAlert()
    {
        return $this->note_type === self::TYPE_SAFETY_ALERT;
    }

    public function isBarrier()
    {
        return $this->note_type === self::TYPE_BARRIER;
    }

    public function requiresImmediate()
    {
        return $this->isCritical() || 
               ($this->isHighPriority() && $this->isSafetyAlert());
    }

    // Computed Properties
    public function getTimeToAcknowledgementAttribute()
    {
        if ($this->acknowledged_at) {
            return $this->created_at->diffInMinutes($this->acknowledged_at);
        }
        return null;
    }

    public function getAgeAttribute()
    {
        return $this->created_at->diffInMinutes(now());
    }

    public function getIsOverdueAttribute()
    {
        if ($this->isAcknowledged()) {
            return false;
        }

        $thresholds = [
            self::SEVERITY_CRITICAL => 15,  // 15 minutes
            self::SEVERITY_HIGH => 30,      // 30 minutes
            self::SEVERITY_MEDIUM => 60,    // 1 hour
            self::SEVERITY_LOW => 120       // 2 hours
        ];

        return $this->age > ($thresholds[$this->severity] ?? 60);
    }
}
