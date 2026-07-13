<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        View::share('cspNonce', $nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $response->headers->set('Origin-Agent-Cluster', '?1');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if (config('security.content_security_policy.enabled', true)) {
            $header = config('security.content_security_policy.report_only', false)
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->headers->set($header, $this->contentSecurityPolicy($nonce));
        }

        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $configured = (array) config('security.content_security_policy.directives', []);
        $configured['script-src'] = [
            ...($configured['script-src'] ?? ["'self'"]),
            "'nonce-{$nonce}'",
        ];

        $directives = [];
        foreach ($configured as $directive => $sources) {
            if (! preg_match('/^[a-z-]+$/', (string) $directive)) {
                continue;
            }

            $safeSources = collect((array) $sources)
                ->filter(fn (mixed $source): bool => is_string($source)
                    && $source !== ''
                    && ! str_contains($source, ';')
                    && ! preg_match('/[\r\n]/', $source))
                ->unique()
                ->values()
                ->all();

            $directives[] = trim($directive.' '.implode(' ', $safeSources));
        }

        if (app()->environment('production')) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives).';';
    }
}
