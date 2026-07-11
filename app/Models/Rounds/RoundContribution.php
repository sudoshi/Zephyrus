<?php

namespace App\Models\Rounds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundContribution extends Model
{
    public const STATUSES = ['draft', 'submitted', 'superseded', 'withdrawn'];

    /** Submitted rows are immutable — corrections create a superseding row (§6.2). */
    public const TRANSITIONS = [
        'draft' => ['submitted', 'withdrawn'],
        'submitted' => ['superseded', 'withdrawn'],
        'superseded' => [],
        'withdrawn' => [],
    ];

    protected $table = 'rounds.contributions';

    protected $primaryKey = 'contribution_id';

    protected $fillable = [
        'contribution_uuid', 'round_patient_id', 'author_user_id', 'author_role',
        'section_code', 'status', 'structured_data', 'summary', 'source_refs',
        'authored_at', 'submitted_at', 'supersedes_id', 'version', 'metadata',
    ];

    protected $casts = [
        'round_patient_id' => 'integer',
        'author_user_id' => 'integer',
        'structured_data' => 'array',
        'source_refs' => 'array',
        'authored_at' => 'datetime',
        'submitted_at' => 'datetime',
        'supersedes_id' => 'integer',
        'version' => 'integer',
        'metadata' => 'array',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(RoundPatient::class, 'round_patient_id', 'round_patient_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id', 'contribution_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
