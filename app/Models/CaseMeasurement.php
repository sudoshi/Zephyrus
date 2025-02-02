<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseMeasurement extends Model
{
    protected $fillable = [
        'case_id',
        'measured_at',
        'hr',
        'sbp',
        'dbp',
        'spo2',
        'temp',
        'notes'
    ];

    protected $casts = [
        'measured_at' => 'datetime',
        'hr' => 'integer',
        'sbp' => 'integer',
        'dbp' => 'integer',
        'spo2' => 'integer',
        'temp' => 'decimal:1'
    ];

    public function case()
    {
        return $this->belongsTo(ORCase::class, 'case_id');
    }

    // Helper method to calculate MAP (Mean Arterial Pressure)
    public function getMapAttribute()
    {
        if ($this->sbp && $this->dbp) {
            return round(($this->sbp + (2 * $this->dbp)) / 3);
        }
        return null;
    }

    // Helper method to check if vitals are within normal range
    public function getVitalsStatusAttribute()
    {
        $alerts = [];

        if ($this->hr < 60 || $this->hr > 100) {
            $alerts[] = 'HR out of range';
        }
        if ($this->sbp < 90 || $this->sbp > 140) {
            $alerts[] = 'SBP out of range';
        }
        if ($this->dbp < 60 || $this->dbp > 90) {
            $alerts[] = 'DBP out of range';
        }
        if ($this->spo2 < 95) {
            $alerts[] = 'SpO2 low';
        }
        if ($this->temp < 36.5 || $this->temp > 37.5) {
            $alerts[] = 'Temperature out of range';
        }

        return [
            'status' => empty($alerts) ? 'normal' : 'alert',
            'alerts' => $alerts
        ];
    }

    // Scope to get the latest measurement for a case
    public function scopeLatest($query)
    {
        return $query->orderBy('measured_at', 'desc');
    }

    // Scope to get measurements within a time range
    public function scopeInTimeRange($query, $start, $end)
    {
        return $query->whereBetween('measured_at', [$start, $end]);
    }

    // Scope to get measurements with abnormal values
    public function scopeAbnormal($query)
    {
        return $query->where(function ($q) {
            $q->where('hr', '<', 60)
              ->orWhere('hr', '>', 100)
              ->orWhere('sbp', '<', 90)
              ->orWhere('sbp', '>', 140)
              ->orWhere('dbp', '<', 60)
              ->orWhere('dbp', '>', 90)
              ->orWhere('spo2', '<', 95)
              ->orWhere('temp', '<', 36.5)
              ->orWhere('temp', '>', 37.5);
        });
    }
}
