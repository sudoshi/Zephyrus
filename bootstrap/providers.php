<?php

use App\Providers\AppServiceProvider;
use App\Providers\HummingbirdServiceProvider;

return [
    AppServiceProvider::class,
    HummingbirdServiceProvider::class,
    // App\Providers\CsrfServiceProvider::class, // Disabled to remove CSRF token requirement
];
