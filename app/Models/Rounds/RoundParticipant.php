<?php

namespace App\Models\Rounds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundParticipant extends Model
{
    public const STATUSES = ['pending', 'invited', 'accepted', 'declined', 'contributed', 'waived'];

    protected $table = 'rounds.participants';

    protected $primaryKey = 'participant_id';

    protected $fillable = [
        'participant_uuid', 'run_id', 'round_patient_id', 'user_id',
        'external_actor_ref', 'role_code', 'required', 'status', 'invited_at',
        'responded_at', 'joined_at', 'waived_by', 'waiver_reason', 'metadata',
    ];

    protected $casts = [
        'run_id' => 'integer',
        'round_patient_id' => 'integer',
        'user_id' => 'integer',
        'required' => 'boolean',
        'invited_at' => 'datetime',
        'responded_at' => 'datetime',
        'joined_at' => 'datetime',
        'waived_by' => 'integer',
        'metadata' => 'array',
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
