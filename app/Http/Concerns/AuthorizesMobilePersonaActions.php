<?php

namespace App\Http\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

trait AuthorizesMobilePersonaActions
{
    /**
     * @param  list<string>  $allowedPersonas
     *
     * @throws AuthorizationException
     */
    private function authorizeMobilePersonaAction(Request $request, array $allowedPersonas, string $action): string
    {
        $roleId = $this->personas->fromRequest($request);

        if (! in_array($roleId, $allowedPersonas, true)) {
            throw new AuthorizationException("The {$roleId} persona cannot {$action}.");
        }

        return $roleId;
    }
}
