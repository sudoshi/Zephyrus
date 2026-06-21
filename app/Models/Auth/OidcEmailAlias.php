<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Model;

class OidcEmailAlias extends Model
{
    protected $table = 'prod.oidc_email_aliases';

    protected $fillable = ['alias_email', 'canonical_email', 'note'];

    public static function canonicalFor(string $email): ?string
    {
        return static::query()
            ->whereRaw('lower(alias_email) = ?', [strtolower($email)])
            ->first()?->canonical_email;
    }
}
