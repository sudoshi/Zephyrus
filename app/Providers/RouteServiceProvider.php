<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('public-health', fn (Request $request) => Limit::perMinute(
            (int) config('ingress.rate_limits.public_health_per_minute', 30),
        )->by('health:'.$request->ip()));

        RateLimiter::for('credential-exchange', function (Request $request) {
            $principal = Str::lower(trim((string) ($request->input('username') ?: $request->input('email'))));
            $principalRef = $principal === ''
                ? 'missing'
                : hash_hmac('sha256', $principal, (string) config('app.key'));

            return Limit::perMinute(
                (int) config('ingress.rate_limits.credential_exchange_per_minute', 5),
            )->by('credential:'.$request->ip().':'.$principalRef);
        });

        RateLimiter::for('web-api', fn (Request $request) => Limit::perMinute(
            (int) config('ingress.rate_limits.web_api_per_minute', 120),
        )->by('web:'.($request->user()?->getAuthIdentifier() ?: $request->ip())));

        RateLimiter::for('sensitive-web-api', fn (Request $request) => Limit::perMinute(
            (int) config('ingress.rate_limits.sensitive_web_api_per_minute', 30),
        )->by('sensitive-web:'.($request->user()?->getAuthIdentifier() ?: $request->ip())));

        RateLimiter::for('machine-ingest', fn (Request $request) => Limit::perMinute(
            (int) config('ingress.rate_limits.machine_ingest_per_minute', 120),
        )->by('machine-ingest:'.($this->tokenKey($request) ?: $request->ip())));

        RateLimiter::for('machine-agent', fn (Request $request) => Limit::perMinute(
            (int) config('ingress.rate_limits.machine_agent_per_minute', 60),
        )->by('machine-agent:'.($this->tokenKey($request) ?: $request->ip())));

        RateLimiter::for('mobile-authenticated', fn (Request $request) => Limit::perMinute(
            (int) config('ingress.rate_limits.mobile_auth_per_minute', 60),
        )->by('mobile-auth:'.($this->tokenKey($request)
            ?: $request->user()?->getAuthIdentifier()
            ?: $request->ip())));

        RateLimiter::for('mobile-api', fn (Request $request) => Limit::perMinute(
            (int) config('ingress.rate_limits.mobile_api_per_minute', 120),
        )->by('mobile:'.($this->tokenKey($request)
            ?: $request->user()?->getAuthIdentifier()
            ?: $request->ip())));

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::middleware('web')
                ->group(base_path('routes/auth.php'));
        });
    }

    private function tokenKey(Request $request): int|string|null
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->getKey() : null;
    }
}
