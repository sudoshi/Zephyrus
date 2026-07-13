<?php

use App\Auth\Drivers\AuthentikOidcAuthDriver;

$defaultDiscoveryUrl = (string) env(
    'OIDC_DISCOVERY_URL',
    'https://auth.acumenus.net/application/o/zephyrus-oidc/.well-known/openid-configuration',
);
$defaultOidcHost = (string) (parse_url($defaultDiscoveryUrl, PHP_URL_HOST) ?: '');
$defaultRedirectUri = (string) env(
    'OIDC_REDIRECT_URI',
    'https://zephyrus.acumenus.net/auth/oidc/callback',
);
$csv = static fn (string $value): array => array_values(array_filter(array_map(
    static fn (string $item): string => trim($item),
    explode(',', $value),
)));

return [
    'local' => [
        'enabled' => filter_var(env('LOCAL_AUTH_ENABLED', true), FILTER_VALIDATE_BOOL),
        'registration_enabled' => filter_var(
            env('LOCAL_REGISTRATION_ENABLED', false),
            FILTER_VALIDATE_BOOL,
        ),
    ],

    'drivers' => [
        'authentik-oidc' => AuthentikOidcAuthDriver::class,
    ],

    'oidc_network' => [
        'require_https' => filter_var(env('OIDC_REQUIRE_HTTPS', true), FILTER_VALIDATE_BOOL),
        'allowed_hosts' => $csv((string) env('OIDC_ALLOWED_HOSTS', $defaultOidcHost)),
        'allowed_ports' => array_map('intval', $csv((string) env('OIDC_ALLOWED_PORTS', '443'))),
        'allowed_redirect_uris' => $csv((string) env('OIDC_ALLOWED_REDIRECT_URIS', $defaultRedirectUri)),
        'require_dns_resolution' => filter_var(env('OIDC_REQUIRE_DNS_RESOLUTION', true), FILTER_VALIDATE_BOOL),
        'allow_private_networks' => filter_var(env('OIDC_ALLOW_PRIVATE_NETWORKS', false), FILTER_VALIDATE_BOOL),
        'connect_timeout_seconds' => max(1, (int) env('OIDC_CONNECT_TIMEOUT_SECONDS', 3)),
        'timeout_seconds' => max(2, (int) env('OIDC_TIMEOUT_SECONDS', 8)),
        'max_response_bytes' => max(16_384, (int) env('OIDC_MAX_RESPONSE_BYTES', 1_048_576)),
        'require_client_secret' => filter_var(env('OIDC_REQUIRE_CLIENT_SECRET', true), FILTER_VALIDATE_BOOL),
    ],
];
