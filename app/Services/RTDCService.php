<?php

namespace App\Services;

class RTDCService
{
    /**
     * Set the RTDC workflow in the session.
     */
    public function activateWorkflow(\Illuminate\Http\Request $request): void
    {
        $request->session()->put('workflow', 'rtdc');
    }
}
