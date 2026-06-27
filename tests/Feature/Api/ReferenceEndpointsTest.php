<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for the Phase 0 OR fixes: the reference endpoints filter
 * prod.{services,rooms,providers} by `active_status` — the real column per the
 * core/reference migrations. The previous `is_active` filter referenced a
 * non-existent column and raised a 500. With an empty (migrated) DB these still
 * respond 200, so a 200 here proves the column name is correct.
 */
class ReferenceEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_endpoint_uses_active_status_column(): void
    {
        $this->getJson('/api/services')->assertOk();
    }

    public function test_rooms_endpoint_uses_active_status_column(): void
    {
        $this->getJson('/api/rooms')->assertOk();
    }

    public function test_providers_endpoint_uses_active_status_column(): void
    {
        $this->getJson('/api/providers')->assertOk();
    }
}
