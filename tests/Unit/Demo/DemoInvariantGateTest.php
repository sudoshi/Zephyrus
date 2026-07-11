<?php

namespace Tests\Unit\Demo;

use App\Services\Demo\DemoInvariantService;
use App\Services\Demo\DistributionProfile;
use Tests\TestCase;

class DemoInvariantGateTest extends TestCase
{
    /** passed() is the gate the refresh pipeline uses; it must fail iff a critical finding fails. */
    public function test_gates_on_critical_failures_only(): void
    {
        $svc = new DemoInvariantService(new DistributionProfile);

        $this->assertTrue($svc->passed([$this->f('critical', true), $this->f('warning', true)]));
        $this->assertTrue($svc->passed([$this->f('critical', true), $this->f('warning', false)]));
        $this->assertFalse($svc->passed([$this->f('critical', false), $this->f('warning', true)]));
        $this->assertTrue($svc->passed([]));
    }

    private function f(string $severity, bool $passed): array
    {
        return ['key' => 'x', 'category' => 'c', 'severity' => $severity, 'passed' => $passed,
            'observed' => '', 'expected' => '', 'detail' => ''];
    }
}
