<?php

namespace App\Models\Org;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7: canonical staff identity (PII-minimized). Deduped on
 * (source_system, external_id) / staff_key, cross-linked by NPI/email, and linked
 * to an app account by nullable soft user_id when one exists. Membership is
 * authoritative independent of account existence.
 */
class StaffMember extends Model
{
    protected $table = 'hosp_org.staff_members';

    protected $primaryKey = 'staff_member_id';

    protected $fillable = [
        'staff_key',
        'source_system',
        'external_id',
        'user_id',
        'npi',
        'license_no',
        'display_name',
        'email',
        'employee_type',
        'employment_status',
        'is_active',
        'first_seen_at',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'is_active' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class, 'staff_member_id', 'staff_member_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
