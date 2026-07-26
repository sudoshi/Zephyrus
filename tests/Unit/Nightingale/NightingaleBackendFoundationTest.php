<?php

namespace Tests\Unit\Nightingale;

use App\Nightingale\EncounterAccess\NightingaleEncounterAccessPreconditionGate;
use App\Nightingale\EncounterAccess\NightingalePreconditionDisposition;
use App\Nightingale\Identity\NightingaleIdentityBoundary;
use App\Nightingale\Identity\NightingaleIdentityState;
use App\Nightingale\Identity\UnconfiguredNightingaleIdentityBoundary;
use App\Nightingale\Inpatient\NightingaleInpatientContextSource;
use App\Nightingale\Inpatient\NightingaleInpatientSourceState;
use App\Nightingale\Inpatient\UnconfiguredNightingaleInpatientContextSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NightingaleBackendFoundationTest extends TestCase
{
    public function test_unconfigured_boundaries_fail_closed_without_a_query(): void
    {
        $identity = new UnconfiguredNightingaleIdentityBoundary;
        $source = new UnconfiguredNightingaleInpatientContextSource;

        $this->assertSame(NightingaleIdentityState::Unavailable, $identity->state());
        $this->assertSame(NightingaleInpatientSourceState::Unavailable, $source->state());
        $this->assertSame(
            NightingalePreconditionDisposition::Withhold,
            (new NightingaleEncounterAccessPreconditionGate)->evaluate($identity, $source),
        );
    }

    #[DataProvider('preconditionStates')]
    public function test_only_two_positive_prerequisites_continue_to_later_governed_evaluation(
        NightingaleIdentityState $identityState,
        NightingaleInpatientSourceState $sourceState,
        NightingalePreconditionDisposition $expected,
    ): void {
        $identity = new class($identityState) implements NightingaleIdentityBoundary
        {
            public function __construct(private readonly NightingaleIdentityState $value) {}

            public function state(): NightingaleIdentityState
            {
                return $this->value;
            }
        };
        $source = new class($sourceState) implements NightingaleInpatientContextSource
        {
            public function __construct(private readonly NightingaleInpatientSourceState $value) {}

            public function state(): NightingaleInpatientSourceState
            {
                return $this->value;
            }
        };

        $this->assertSame(
            $expected,
            (new NightingaleEncounterAccessPreconditionGate)->evaluate($identity, $source),
        );
    }

    /** @return iterable<string, array{NightingaleIdentityState, NightingaleInpatientSourceState, NightingalePreconditionDisposition}> */
    public static function preconditionStates(): iterable
    {
        foreach (NightingaleIdentityState::cases() as $identity) {
            foreach (NightingaleInpatientSourceState::cases() as $source) {
                $expected = $identity === NightingaleIdentityState::VerifiedSelf
                    && $source === NightingaleInpatientSourceState::ConfirmedCurrent
                    ? NightingalePreconditionDisposition::ContinueToGovernedEvaluation
                    : NightingalePreconditionDisposition::Withhold;

                yield "{$identity->value}:{$source->value}" => [$identity, $source, $expected];
            }
        }
    }
}
