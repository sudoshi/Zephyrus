<?php

namespace App\Models\Rounds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundQuestion extends Model
{
    public const STATUSES = ['open', 'answered', 'dismissed', 'expired'];

    protected $table = 'rounds.questions';

    protected $primaryKey = 'question_id';

    protected $fillable = [
        'question_uuid', 'round_patient_id', 'raised_by', 'raised_role',
        'target_role', 'target_user_id', 'question_text', 'status',
        'response_contribution_id', 'due_at', 'answered_at', 'provenance',
    ];

    protected $casts = [
        'round_patient_id' => 'integer',
        'raised_by' => 'integer',
        'target_user_id' => 'integer',
        'response_contribution_id' => 'integer',
        'due_at' => 'datetime',
        'answered_at' => 'datetime',
        'provenance' => 'array',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(RoundPatient::class, 'round_patient_id', 'round_patient_id');
    }
}
