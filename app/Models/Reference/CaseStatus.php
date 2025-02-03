<?php

namespace App\Models\Reference;

use App\Models\ORCase;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseStatus extends BaseReference
{
    public $timestamps = false;
    protected $table = 'prod.case_statuses';
    protected $primaryKey = 'status_id';

    // Status Constants
    const SCHEDULED = 1;
    const IN_PROGRESS = 2;
    const DELAYED = 3;
    const COMPLETED = 4;
    const CANCELLED = 5;

    protected $fillable = [
        'name',
        'code',
        'active_status',
        'created_by',
        'modified_by',
        'created_date',
        'modified_date',
        'is_deleted'
    ];

    protected $casts = [
        'active_status' => 'boolean',
        'is_deleted' => 'boolean',
        'created_date' => 'datetime',
        'modified_date' => 'datetime'
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(ORCase::class, 'status_id', 'status_id');
    }

    public static function getStatusMap(): array
    {
        return [
            self::SCHEDULED => 'scheduled',
            self::IN_PROGRESS => 'in_progress',
            self::DELAYED => 'delayed',
            self::COMPLETED => 'completed',
            self::CANCELLED => 'cancelled'
        ];
    }

    public static function getStatusId(string $status): ?int
    {
        $map = array_flip(self::getStatusMap());
        return $map[$status] ?? null;
    }

    public static function getStatusName(int $statusId): ?string
    {
        return self::getStatusMap()[$statusId] ?? null;
    }

    public static function getActiveStatuses(): array
    {
        return [self::SCHEDULED, self::IN_PROGRESS, self::DELAYED];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_date = $model->freshTimestamp();
            $model->modified_date = $model->freshTimestamp();
        });

        static::updating(function ($model) {
            $model->modified_date = $model->freshTimestamp();
        });
    }
}
