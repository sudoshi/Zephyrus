<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Fail before Laravel boots if the test runner can resolve a production
 * database, secret directory, application URL, or outbound service endpoint.
 */
final class TestEnvironmentGuard
{
    private const REQUIRED_TEST_KEY = 'base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=';

    private const URL_KEYS = [
        'APP_URL',
        'OIDC_DISCOVERY_URL',
        'OIDC_REDIRECT_URI',
        'EPIC_FHIR_BASE_URL',
        'EPIC_SMART_CONFIGURATION_URL',
        'EPIC_FHIR_TOKEN_URL',
        'EDDY_BASE_URL',
        'ARENA_BASE_URL',
        'TEAMS_ALERT_WEBHOOK_URL',
    ];

    private const HOST_LIST_KEYS = [
        'OIDC_ALLOWED_HOSTS',
        'INTEGRATION_ALLOWED_HOSTS',
    ];

    private const URL_LIST_KEYS = [
        'OIDC_ALLOWED_REDIRECT_URIS',
    ];

    public static function enforce(string $basePath): void
    {
        $environment = [];
        foreach (self::environmentKeys() as $key) {
            $value = getenv($key);
            $environment[$key] = $value === false ? null : $value;
        }

        $violations = self::violations($environment, $basePath);
        if ($violations !== []) {
            throw new RuntimeException(
                "Unsafe PHPUnit environment:\n- ".implode("\n- ", $violations),
            );
        }
    }

    /**
     * @param  array<string, string|null>  $environment
     * @return list<string>
     */
    public static function violations(array $environment, string $basePath): array
    {
        $violations = [];

        if (($environment['APP_ENV'] ?? null) !== 'testing') {
            $violations[] = 'APP_ENV must be testing';
        }
        if (($environment['APP_KEY'] ?? null) !== self::REQUIRED_TEST_KEY) {
            $violations[] = 'APP_KEY must be the deterministic test-only key';
        }
        if (($environment['DB_CONNECTION'] ?? null) !== 'pgsql') {
            $violations[] = 'DB_CONNECTION must be pgsql';
        }
        if (! in_array($environment['DB_HOST'] ?? null, ['localhost', '127.0.0.1', '::1'], true)) {
            $violations[] = 'DB_HOST must be loopback';
        }
        if (! preg_match('/^zephyrus_test(?:_[a-z0-9]+)?$/', (string) ($environment['DB_DATABASE'] ?? ''))) {
            $violations[] = 'DB_DATABASE must use the zephyrus_test namespace';
        }
        if (! filter_var($environment['TEST_DB_ISOLATION'] ?? false, FILTER_VALIDATE_BOOL)) {
            $violations[] = 'TEST_DB_ISOLATION must be true';
        }
        if (! filter_var($environment['TEST_NETWORK_GUARD'] ?? false, FILTER_VALIDATE_BOOL)) {
            $violations[] = 'TEST_NETWORK_GUARD must be true';
        }

        $secretRoot = self::absolutePath((string) ($environment['INTEGRATION_SECRET_FILE_ROOT'] ?? ''), $basePath);
        $safeSecretRoot = self::absolutePath('storage/framework/testing/secrets', $basePath);
        if ($secretRoot === '' || ! str_starts_with($secretRoot.'/', $safeSecretRoot.'/')) {
            $violations[] = 'INTEGRATION_SECRET_FILE_ROOT must stay under storage/framework/testing/secrets';
        }

        foreach (self::URL_KEYS as $key) {
            $value = trim((string) ($environment[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $host = parse_url($value, PHP_URL_HOST);
            if (! is_string($host) || ! self::isTestHost($host)) {
                $violations[] = "{$key} must resolve only to a .test or loopback host";
            }
        }

        foreach (self::HOST_LIST_KEYS as $key) {
            foreach (array_filter(array_map('trim', explode(',', (string) ($environment[$key] ?? '')))) as $host) {
                if (! self::isTestHost(ltrim($host, '*.'))) {
                    $violations[] = "{$key} contains a non-test host";
                    break;
                }
            }
        }

        foreach (self::URL_LIST_KEYS as $key) {
            foreach (array_filter(array_map('trim', explode(',', (string) ($environment[$key] ?? '')))) as $url) {
                $host = parse_url($url, PHP_URL_HOST);
                if (! is_string($host) || ! self::isTestHost($host)) {
                    $violations[] = "{$key} contains a URL with a non-test host";
                    break;
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private static function environmentKeys(): array
    {
        return array_values(array_unique([
            'APP_ENV',
            'APP_KEY',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_DATABASE',
            'TEST_DB_ISOLATION',
            'TEST_NETWORK_GUARD',
            'INTEGRATION_SECRET_FILE_ROOT',
            ...self::URL_KEYS,
            ...self::HOST_LIST_KEYS,
            ...self::URL_LIST_KEYS,
        ]));
    }

    private static function isTestHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.test');
    }

    private static function absolutePath(string $path, string $basePath): string
    {
        if ($path === '') {
            return '';
        }

        $path = str_replace('\\', '/', $path);
        if (! str_starts_with($path, '/')) {
            $path = rtrim(str_replace('\\', '/', $basePath), '/').'/'.$path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
