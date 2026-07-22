<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditUserRequests
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const EXPLICIT_AUTH_ROUTES = [
        'login', 'logout', 'register', 'password.store', 'password.update',
        'password.email', 'verification.send', 'verification.verify',
        'auth.oidc.redirect', 'auth.oidc.callback',
    ];

    public function __construct(private readonly UserAuditRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldAudit($request, $response)) {
            return $response;
        }

        $mutating = in_array($request->getMethod(), self::MUTATING_METHODS, true);
        $status = $response->getStatusCode();

        $this->recorder->bestEffort(
            $mutating ? 'web.request.mutated' : 'web.page.viewed',
            $mutating ? 'activity' : 'access',
            $this->outcome($status),
            [
                'request' => $request,
                'actor' => $request->user(),
                'auth_method' => $request->is('api/*') ? 'session' : 'session',
                'http_status' => $status,
                'metadata' => $mutating ? [] : ['page_visit' => true],
            ],
        );

        return $response;
    }

    private function shouldAudit(Request $request, Response $response): bool
    {
        // Patient principals have their own append-only access/disclosure audit
        // ledger. Never send them through the staff-only UserAuditRecorder.
        if ($request->user() !== null && ! $request->user() instanceof User) {
            return false;
        }

        if ($request->user() === null || $this->recorder->requestWasAudited($request)) {
            return false;
        }

        if ($request->is('up') || $request->is('health') || $request->is('health/*')) {
            return false;
        }

        $routeName = $request->route()?->getName();
        if (is_string($routeName) && in_array($routeName, self::EXPLICIT_AUTH_ROUTES, true)) {
            return false;
        }

        if (in_array($request->getMethod(), self::MUTATING_METHODS, true)) {
            return true;
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $this->isApiRequest($request)) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type');

        return $response instanceof RedirectResponse
            || $response->headers->has('X-Inertia')
            || str_contains($contentType, 'text/html');
    }

    private function isApiRequest(Request $request): bool
    {
        $template = (string) $request->route()?->uri();

        return $request->is('api/*')
            || str_starts_with($template, 'api/')
            || str_contains($template, '/api/');
    }

    private function outcome(int $status): string
    {
        if (in_array($status, [401, 403], true)) {
            return 'denied';
        }

        return $status >= 400 ? 'failure' : 'success';
    }
}
