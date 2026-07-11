<?php

namespace Tests\Unit\Demo;

use Tests\TestCase;

/**
 * The demo-mode gate is the safety contract: a real/live deployment must never be overwritten
 * by the synthetic refresh. Refusal happens before any DB write, so this needs no database.
 */
class DemoRefreshGateTest extends TestCase
{
    public function test_refuses_to_refresh_when_demo_mode_is_off_and_not_forced(): void
    {
        config(['demo.enabled' => false]);

        $this->artisan('zephyrus:demo-refresh')
            ->expectsOutputToContain('Refusing to refresh')
            ->assertExitCode(1);
    }
}
