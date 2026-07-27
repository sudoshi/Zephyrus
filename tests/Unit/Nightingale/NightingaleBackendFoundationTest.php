<?php

namespace Tests\Unit\Nightingale;

use App\Nightingale\Disclosure\NightingaleDisclosureDisposition;
use App\Nightingale\Disclosure\NightingaleEncounterBindingState;
use App\Nightingale\Disclosure\NightingaleGenericNonDisclosureGate;
use App\Nightingale\Disclosure\NightingaleRelationshipState;
use App\Nightingale\Disclosure\NightingaleResourceState;
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

    #[DataProvider('genericNonDisclosureStates')]
    public function test_non_disclosable_request_states_have_one_public_failure(
        NightingalePreconditionDisposition $preconditions,
        NightingaleRelationshipState $relationship,
        NightingaleEncounterBindingState $encounterBinding,
        NightingaleResourceState $resource,
    ): void {
        $result = (new NightingaleGenericNonDisclosureGate)->evaluate(
            $preconditions,
            $relationship,
            $encounterBinding,
            $resource,
        );

        $this->assertSame(NightingaleDisclosureDisposition::WithholdNotFound, $result);
        $this->assertSame([
            'status' => 404,
            'code' => 'not_found',
            'cache_control' => 'private, no-store, max-age=0',
        ], $result->publicFailure());
    }

    /**
     * @return iterable<string, array{
     *     NightingalePreconditionDisposition,
     *     NightingaleRelationshipState,
     *     NightingaleEncounterBindingState,
     *     NightingaleResourceState
     * }>
     */
    public static function genericNonDisclosureStates(): iterable
    {
        $continue = NightingalePreconditionDisposition::ContinueToGovernedEvaluation;
        $active = NightingaleRelationshipState::Active;
        $matches = NightingaleEncounterBindingState::MatchesCurrentContext;
        $released = NightingaleResourceState::Released;

        yield 'unknown relationship' => [
            $continue,
            NightingaleRelationshipState::Unknown,
            $matches,
            $released,
        ];
        yield 'revoked relationship' => [
            $continue,
            NightingaleRelationshipState::Revoked,
            $matches,
            $released,
        ];
        yield 'expired relationship' => [
            $continue,
            NightingaleRelationshipState::Expired,
            $matches,
            $released,
        ];
        yield 'cross-principal relationship' => [
            $continue,
            NightingaleRelationshipState::CrossPrincipal,
            $matches,
            $released,
        ];
        yield 'wrong encounter' => [
            $continue,
            $active,
            NightingaleEncounterBindingState::WrongEncounter,
            $released,
        ];
        yield 'omitted resource' => [
            $continue,
            $active,
            $matches,
            NightingaleResourceState::Omitted,
        ];
        yield 'failed upstream precondition' => [
            NightingalePreconditionDisposition::Withhold,
            $active,
            $matches,
            $released,
        ];
    }

    public function test_only_the_fully_positive_disclosure_state_continues(): void
    {
        $gate = new NightingaleGenericNonDisclosureGate;
        $continueCount = 0;
        $withholdCount = 0;

        foreach (NightingalePreconditionDisposition::cases() as $preconditions) {
            foreach (NightingaleRelationshipState::cases() as $relationship) {
                foreach (NightingaleEncounterBindingState::cases() as $encounterBinding) {
                    foreach (NightingaleResourceState::cases() as $resource) {
                        $result = $gate->evaluate(
                            $preconditions,
                            $relationship,
                            $encounterBinding,
                            $resource,
                        );
                        $shouldContinue = $preconditions
                            === NightingalePreconditionDisposition::ContinueToGovernedEvaluation
                            && $relationship === NightingaleRelationshipState::Active
                            && $encounterBinding
                            === NightingaleEncounterBindingState::MatchesCurrentContext
                            && $resource === NightingaleResourceState::Released;

                        if ($shouldContinue) {
                            $continueCount++;
                            $this->assertSame(
                                NightingaleDisclosureDisposition::ContinueToGovernedProjectionEvaluation,
                                $result,
                            );
                            $this->assertNull($result->publicFailure());

                            continue;
                        }

                        $withholdCount++;
                        $this->assertSame(NightingaleDisclosureDisposition::WithholdNotFound, $result);
                        $this->assertSame('not_found', $result->publicFailure()['code']);
                    }
                }
            }
        }

        $this->assertSame(1, $continueCount);
        $this->assertSame(39, $withholdCount);
    }
}
