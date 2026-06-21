<?php

use App\Auth\Drivers\AuthentikOidcAuthDriver;

return [
    'local' => [
        'enabled' => filter_var(env('LOCAL_AUTH_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    'drivers' => [
        'authentik-oidc' => AuthentikOidcAuthDriver::class,
    ],
];
