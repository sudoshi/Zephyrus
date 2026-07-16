<?php

namespace App\Services\Auth;

use RuntimeException;

/** Fail closed when production browser sessions are configured below baseline. */
class ProductionSessionConfiguration
{
    public function assertSecure(): void
    {
        $violations = $this->violations((array) config('session'), (string) config('app.url'));

        if ($violations !== []) {
            throw new RuntimeException('Unsafe production session configuration: '.implode('; ', $violations));
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @return list<string>
     */
    public function violations(array $session, string $appUrl): array
    {
        $violations = [];

        if (! in_array($session['driver'] ?? null, ['database', 'redis'], true)) {
            $violations[] = 'SESSION_DRIVER must be database or redis';
        }
        if (($session['encrypt'] ?? null) !== true) {
            $violations[] = 'SESSION_ENCRYPT must be true';
        }
        if (($session['secure'] ?? null) !== true) {
            $violations[] = 'SESSION_SECURE_COOKIE must be true';
        }
        if (($session['http_only'] ?? null) !== true) {
            $violations[] = 'SESSION_HTTP_ONLY must be true';
        }
        if (! in_array(strtolower((string) ($session['same_site'] ?? '')), ['lax', 'strict'], true)) {
            $violations[] = 'SESSION_SAME_SITE must be lax or strict';
        }
        if (($session['path'] ?? null) !== '/') {
            $violations[] = 'SESSION_PATH must be /';
        }

        $domain = $session['domain'] ?? null;
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if ($domain !== null && $domain !== ''
            && (! is_string($appHost) || strcasecmp(ltrim((string) $domain, '.'), $appHost) !== 0
                || str_starts_with((string) $domain, '.'))) {
            $violations[] = 'SESSION_DOMAIN must be host-only or exactly match APP_URL';
        }

        return $violations;
    }
}
