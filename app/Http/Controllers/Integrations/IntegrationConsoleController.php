<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class IntegrationConsoleController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Integrations/Index');
    }
}
