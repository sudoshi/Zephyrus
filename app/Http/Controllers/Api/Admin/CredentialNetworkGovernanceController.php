<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\CredentialAuthorityService;
use App\Integrations\Healthcare\Services\CredentialValidationService;
use App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService;
use App\Integrations\Healthcare\Services\NetworkRouteService;
use App\Security\Secrets\SecretProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class CredentialNetworkGovernanceController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(
        private readonly CredentialAuthorityService $authority,
        private readonly CredentialValidationService $validation,
        private readonly NetworkRouteService $routes,
        private readonly SecretProviderRegistry $providers,
        private readonly IntegrationConfigurationAuditService $audit,
    ) {}

    public function providers(): JsonResponse
    {
        return response()->json(['data' => $this->providers->capabilities()]);
    }

    public function credentialVersions(int $source, int $credential): JsonResponse
    {
        return response()->json(['data' => $this->authority->history($source, $credential)]);
    }

    public function validateCredential(Request $request, int $source, int $credential): JsonResponse
    {
        $this->authority->history($source, $credential);
        $validated = $request->validate([
            'evaluated_for_at' => ['nullable', 'date', 'after_or_equal:now', 'before_or_equal:+1 year'],
        ]);
        $result = $this->validation->evaluate(
            $credential,
            isset($validated['evaluated_for_at'])
                ? CarbonImmutable::parse((string) $validated['evaluated_for_at'])
                : CarbonImmutable::now(),
            $request->user()?->getAuthIdentifier(),
            persist: true,
        );
        if ((int) $result['credentialId'] !== $credential) {
            abort(404);
        }

        return response()->json(['data' => $result], 201);
    }

    public function networkRoutes(int $source): JsonResponse
    {
        return response()->json(['data' => $this->routes->routes($source)]);
    }

    public function createNetworkRoute(Request $request, int $source): JsonResponse
    {
        $route = $this->routes->create(
            $source,
            $request->validate($this->routeRules()),
            $request->user()?->getAuthIdentifier(),
        );
        $this->audit->record(
            $request->user()?->getAuthIdentifier(),
            'created',
            'network_route',
            $route['networkRouteId'],
            'source:'.$source.':'.$route['routeKey'],
            [],
            $this->auditRoute($route),
            $this->correlationId($request),
        );

        return response()->json(['data' => $route], 201);
    }

    public function updateNetworkRoute(Request $request, int $source, int $route): JsonResponse
    {
        $before = collect($this->routes->routes($source))->firstWhere('networkRouteId', $route);
        if (! is_array($before)) {
            abort(404);
        }
        $after = $this->routes->update(
            $source,
            $route,
            $request->validate($this->routeRules(updating: true)),
            $request->user()?->getAuthIdentifier(),
        );
        $this->audit->record(
            $request->user()?->getAuthIdentifier(),
            'updated',
            'network_route',
            $route,
            'source:'.$source.':'.$after['routeKey'],
            $this->auditRoute($before),
            $this->auditRoute($after),
            $this->correlationId($request),
        );

        return response()->json(['data' => $after]);
    }

    public function validateNetworkRoute(Request $request, int $source, int $route): JsonResponse
    {
        return response()->json(['data' => $this->routes->validate(
            $source,
            $route,
            $request->user()?->getAuthIdentifier(),
        )], 201);
    }

    public function retireNetworkRoute(Request $request, int $source, int $route): Response
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $before = collect($this->routes->routes($source))->firstWhere('networkRouteId', $route);
        if (! is_array($before)) {
            abort(404);
        }
        $this->routes->retire($source, $route, (string) $validated['reason']);
        $after = collect($this->routes->routes($source))->firstWhere('networkRouteId', $route);
        $this->audit->record(
            $request->user()?->getAuthIdentifier(),
            'retired',
            'network_route',
            $route,
            'source:'.$source.':'.$before['routeKey'],
            $this->auditRoute($before),
            is_array($after) ? $this->auditRoute($after) : [],
            $this->correlationId($request),
        );

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function routeRules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'route_key' => [$required, 'string', 'min:3', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/'],
            'source_endpoint_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'transport' => [$required, Rule::in(['public_internet', 'vpn', 'private_link', 'direct_connect', 'interface_engine'])],
            'hostname' => [$required, 'string', 'max:253'],
            'port' => [$required, 'integer', 'between:1,65535'],
            'proxy_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'dns_policy' => [$required, Rule::in(['public_only', 'allowlist', 'private_only'])],
            'allowed_ip_cidrs' => ['sometimes', 'array', 'max:32'],
            'allowed_ip_cidrs.*' => ['string', 'max:64'],
            'egress_policy_key' => [$required, 'string', 'min:3', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/'],
            'mtls_required' => ['sometimes', 'boolean'],
            'client_credential_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'server_name' => ['sometimes', 'nullable', 'string', 'max:253'],
            'change_reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /** @param array<string, mixed> $route @return array<string, mixed> */
    private function auditRoute(array $route): array
    {
        return collect($route)->only([
            'networkRouteId', 'sourceId', 'endpointId', 'routeKey', 'environment',
            'transport', 'hostname', 'port', 'proxyConfigured', 'proxyOrigin',
            'dnsPolicy', 'allowedIpCidrs', 'egressPolicyKey', 'mtlsRequired',
            'clientCredentialId', 'serverName', 'status', 'changeReason', 'policySha256',
        ])->all();
    }
}
