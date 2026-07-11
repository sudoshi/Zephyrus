<?php

namespace Tests\Unit\Demo;

use Tests\TestCase;

/** Retention must never delete on a live deployment: the gate refuses before any DELETE. */
class DemoPruneGateTest extends TestCase
{
    public function test_refuses_to_prune_when_demo_mode_is_off_and_not_forced(): void
    {
        config(['demo.enabled' => false]);

        $this->artisan('zephyrus:demo-prune')
            ->expectsOutputToContain('Refusing to prune')
            ->assertExitCode(1);
    }
}
