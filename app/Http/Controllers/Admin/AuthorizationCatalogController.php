<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AuthorizationCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AuthorizationCatalogController extends Controller
{
    public function __construct(private readonly AuthorizationCatalogService $catalog) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAuthorization');

        return Inertia::render('Admin/RolesCapabilities', $this->catalog->catalogFor($request->user()));
    }
}
