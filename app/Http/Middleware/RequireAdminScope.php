<?php

namespace App\Http\Middleware;

use App\Services\Authorization\AdminScopeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdminScope
{
    public function __construct(private readonly AdminScopeService $scopes) {}

    public function handle(Request $request, Closure $next, string $requirement = 'organization'): Response
    {
        match ($requirement) {
            'organization' => $this->scopes->requireOrganization($request),
            'facility' => $this->scopes->requireFacility($request),
            'source' => $this->scopes->requireSource($request, $this->requestedSourceId($request)),
            'governed_change' => $this->scopes->requireGovernedChange(
                $request,
                (string) ($request->route('changeRequestUuid') ?? $request->input('change_request_uuid')),
            ),
            default => throw new \InvalidArgumentException('Unknown Admin scope middleware requirement.'),
        };

        return $next($request);
    }

    private function requestedSourceId(Request $request): ?int
    {
        $source = $request->route('source', $request->input('source_id'));

        return is_numeric($source) && (int) $source > 0 ? (int) $source : null;
    }
}
