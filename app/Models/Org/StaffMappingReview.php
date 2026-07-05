<?php

namespace App\Models\Org;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7: the human decision log — one row per reviewer action
 * (accept|edit|split|defer|reject|deactivate) on a staged staff member, with the
 * proposed vs final assignment and an optional rule promotion. Audit-only (append),
 * so it carries created_at but no updated_at.
 */
class StaffMappingReview extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hosp_org.staff_mapping_reviews';

    protected $primaryKey = 'staff_mapping_review_id';

    protected $fillable = [
        'staff_import_run_id',
        'staff_member_id',
        'proposed',
        'final',
        'action',
        'reviewer_id',
        'note',
        'promoted_to_rule_id',
    ];

    protected $casts = [
        'staff_import_run_id' => 'integer',
        'staff_member_id' => 'integer',
        'proposed' => 'array',
        'final' => 'array',
        'reviewer_id' => 'integer',
        'promoted_to_rule_id' => 'integer',
    ];

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(StaffImportRun::class, 'staff_import_run_id', 'staff_import_run_id');
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_member_id', 'staff_member_id');
    }
}
