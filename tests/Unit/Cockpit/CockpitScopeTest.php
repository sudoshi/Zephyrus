<?php

namespace Tests\Unit\Cockpit;

use App\Support\Cockpit\CockpitScope;
use PHPUnit\Framework\TestCase;

/**
 * Zephyrus 2.0 P8 WS-1 — the CockpitScope value object. Pure (no app boot, no DB);
 * PHPUnit class syntax (Pest is excluded on this environment).
 */
class CockpitScopeTest extends TestCase
{
    public function test_house_scope_has_no_key_and_a_house_token(): void
    {
        $scope = CockpitScope::house('Summit Regional Medical Center');

        $this->assertTrue($scope->isHouse());
        $this->assertSame(CockpitScope::LEVEL_HOUSE, $scope->level);
        $this->assertNull($scope->key);
        $this->assertSame('house', $scope->token());
        $this->assertSame('Summit Regional Medical Center', $scope->label);
    }

    public function test_scoped_tokens_are_level_colon_key(): void
    {
        $this->assertSame('unit:MICU', CockpitScope::unit('MICU', 'Medical ICU')->token());
        $this->assertSame(
            'service_line:critical_care',
            CockpitScope::serviceLine('critical_care', 'Critical Care')->token(),
        );
        $this->assertSame('department:ed', CockpitScope::department('ed', 'Emergency Department')->token());
    }

    public function test_scoped_values_are_not_house(): void
    {
        $this->assertFalse(CockpitScope::unit('MICU', 'Medical ICU')->isHouse());
        $this->assertFalse(CockpitScope::department('ed', 'Emergency Department')->isHouse());
    }

    public function test_to_array_carries_level_key_label_token(): void
    {
        $this->assertSame(
            [
                'level' => 'unit',
                'key' => 'MICU',
                'label' => 'Medical ICU',
                'token' => 'unit:MICU',
            ],
            CockpitScope::unit('MICU', 'Medical ICU')->toArray(),
        );
    }
}
