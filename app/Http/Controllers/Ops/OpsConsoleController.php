<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class OpsConsoleController extends Controller
{
    public function agentInbox(): InertiaResponse
    {
        return Inertia::render('Ops/AgentInbox', ['workflow' => 'superuser']);
    }

    public function executiveBrief(): InertiaResponse
    {
        return Inertia::render('Ops/ExecutiveBrief', ['workflow' => 'superuser']);
    }
}
