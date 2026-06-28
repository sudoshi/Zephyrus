<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    // HasApiTokens enables short-TTL, ability-scoped tokens for the Eddy agent
    // (Sanctum is already installed/configured). Additive — changes no auth behavior.
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
}
