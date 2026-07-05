<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    // HasApiTokens provides Sanctum token-based API auth — used by the Hummingbird
    // mobile companion (mobile access tokens) and by the Eddy agent (short-TTL,
    // ability-scoped tokens). Additive: the web session-cookie auth flow is unchanged.
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'prod.users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'workflow_preference',
        'must_change_password',
        'role',
        'is_active',
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'must_change_password' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    public function username()
    {
        return 'username';
    }

    /**
     * Units this user is associated with (the assignment model that powers the
     * mobile "For You" queue and notification routing — "assigned to me / my unit").
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'prod.user_unit', 'user_id', 'unit_id')
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Registered mobile devices (APNs/FCM push-token registry) for this user.
     */
    public function mobileDevices(): HasMany
    {
        return $this->hasMany(MobileDevice::class, 'user_id');
    }

    /**
     * Operational staff assignments (Phase 7 Staffing Alignment) linked to this
     * account via hosp_org.staff_members.user_id. Additive: this is the operational
     * layer, never the auth role of record (prod.users.role stays authoritative).
     */
    public function staffAssignments(): HasMany
    {
        return $this->hasMany(\App\Models\Org\StaffMember::class, 'user_id', 'id');
    }

    /**
     * Sanctum token abilities granted to this user, derived from role + workflow.
     * Admins get full access; everyone else gets the read/act baseline plus their
     * workflow scope. Used when issuing mobile access tokens.
     *
     * @return array<int, string>
     */
    public function mobileTokenAbilities(): array
    {
        if ($this->hasRole(['super-admin', 'admin'])) {
            return ['*'];
        }

        $abilities = ['mobile:read', 'mobile:act'];

        if ($this->workflow_preference) {
            $abilities[] = 'workflow:'.$this->workflow_preference;
        }

        return $abilities;
    }
}
