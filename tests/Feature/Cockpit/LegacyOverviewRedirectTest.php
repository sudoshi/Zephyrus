<?php

namespace Tests\Feature\Cockpit;

use App\Models\User;
use Tests\TestCase;

/**
 * P4a acceptance: every legacy /dashboard/* overview bookmark resolves to the
 * cockpit with the matching drill open (D4 — permanent redirects), the route
 * names survive for internal resolvers, and /home is gone.
 */
class LegacyOverviewRedirectTest extends TestCase
{
    /** The D4 map: legacy overview URL → cockpit drill domain. */
    private const REDIRECTS = [
        '/dashboard/rtdc' => 'rtdc',
        '/dashboard/perioperative' => 'periop',
        '/dashboard/emergency' => 'ed',
        '/dashboard/improvement' => 'quality',
        '/dashboard/transport' => 'flow',
    ];

    public function test_legacy_overviews_redirect_to_the_matching_cockpit_drill(): void
    {
        $user = User::query()->first();
        $this->assertNotNull($user, 'seeded user required');

        foreach (self::REDIRECTS as $uri => $domain) {
            $this->actingAs($user)
                ->get($uri)
                ->assertRedirect("/dashboard?drill={$domain}");
        }
    }

    public function test_legacy_overview_route_names_still_resolve(): void
    {
        // /improvement/overview and setPreference() resolve these by name;
        // the redirect conversion must not orphan them.
        foreach (['rtdc', 'perioperative', 'emergency', 'improvement', 'transport'] as $key) {
            $this->assertSame(url("/dashboard/{$key}"), route("dashboard.{$key}"));
        }
    }

    public function test_home_route_is_deleted(): void
    {
        $user = User::query()->first();
        $this->assertNotNull($user, 'seeded user required');

        $this->actingAs($user)->get('/home')->assertNotFound();
    }
}
