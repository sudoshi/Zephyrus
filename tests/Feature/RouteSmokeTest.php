<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteSmokeTest extends TestCase
{
    public function test_all_get_pages_render_without_server_error(): void
    {
        $user = User::query()->first();
        $this->assertNotNull($user, 'seeded user required');

        // 'up/demo' is a health endpoint: it answers 503 by design when demo mode is
        // off or the rolling-demo data is unhealthy (correct health semantics), so it
        // is not a "page" and must not be asserted for a 2xx render.
        $skip = ['logout', 'login', 'register', 'password.', 'verification.', 'storage.', 'sanctum.', 'up/demo'];
        $failures = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            $name = $route->getName() ?? '';
            if (str_contains($uri, '{') || str_starts_with($uri, 'api/') || str_starts_with($uri, '_') || str_starts_with($uri, 'design/')) {
                continue;
            }
            foreach ($skip as $s) {
                if (str_starts_with($name, $s) || $uri === $s) {
                    continue 2;
                }
            }

            $checked++;
            try {
                $res = $this->actingAs($user)->get('/'.ltrim($uri, '/'));
                $status = $res->status();
                if ($status >= 500) {
                    $failures[] = "/{$uri} -> {$status}";
                }
            } catch (\Throwable $e) {
                $failures[] = "/{$uri} -> EXCEPTION: ".$e->getMessage();
            }
        }

        fwrite(STDERR, "\nRouteSmoke: checked {$checked} GET routes, ".count($failures)." failure(s)\n");
        foreach ($failures as $f) {
            fwrite(STDERR, "  FAIL {$f}\n");
        }

        $this->assertSame([], $failures, 'Pages returning 5xx / throwing: '.implode(' | ', $failures));
    }
}
