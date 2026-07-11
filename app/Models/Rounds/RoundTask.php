<?php

namespace App\Models\Rounds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundTask extends Model
{
    public const STATUSES = ['open', 'in_progress', 'completed', 'cancelled'];

    protected $table = 'rounds.tasks';

    protected $primaryKey = 'task_id';

    protected $fillable = [
        'task_uuid', 'run_id', 'round_patient_id', 'owner_user_id', 'owner_role',
        'category', 'title', 'detail', 'due_at', 'status', 'ops_action_uuid',
        'external_task_ref', 'created_by', 'completed_by', 'completed_at',
        'provenance',
    ];

    protected $casts = [
        'run_id' => 'integer',
        'round_patient_id' => 'integer',
        'owner_user_id' => 'integer',
        'due_at' => 'datetime',
        'created_by' => 'integer',
        'completed_by' => 'integer',
        'completed_at' => 'datetime',
        'provenance' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(RoundRun::class, 'run_id', 'run_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(RoundPatient::class, 'round_patient_id', 'round_patient_id');
    }
}
