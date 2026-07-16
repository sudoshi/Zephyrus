<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ClinicalPayloadProtectionService;
use App\Services\Authorization\AdminScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class DataProtectionController extends Controller
{
    public function __construct(
        private readonly ClinicalPayloadProtectionService $protection,
        private readonly AdminScopeService $scopes,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewIntegrations');

        return Inertia::render('Admin/DataProtection', [
            'snapshot' => $this->protection->snapshot($this->scopes->current($request)),
        ]);
    }
}
