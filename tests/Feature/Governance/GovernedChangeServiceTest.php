<?php

namespace Tests\Feature\Governance;

use App\Authorization\GovernedAction;
use App\Models\Governance\GovernedChangeDecision;
use App\Models\Governance\GovernedChangeExecution;
use App\Models\Governance\GovernedChangeRequest;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use App\Services\Governance\GovernanceViolation;
use App\Services\Governance\GovernedChangeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GovernedChangeServiceTest extends TestCase
{
    use RefreshDatabase;

    private GovernedChangeService $governance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->governance = app(GovernedChangeService::class);
    }

    public function test_separate_author_and_approver_can_execute_the_exact_approved_payload_once(): void
    {
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $payload = ['source_id' => 44, 'active_status' => 'active', 'go_live_status' => 'live'];
        $hash = $this->governance->hashPayload($payload);

        $change = $this->governance->requestChange(
            $this->steppedUpRequest($author),
            GovernedAction::ActivateProductionSource,
            'integration_source',
            '44',
            'Production readiness evidence was reviewed and attached.',
            $hash,
        );

        try {
            $this->governance->decide(
                $this->steppedUpRequest($author),
                $change->getKey(),
                true,
                'I approve the production source activation.',
            );
            $this->fail('The author must not decide their own change.');
        } catch (GovernanceViolation $exception) {
            $this->assertSame('author_approver_conflict', $exception->reason);
        }

        $decision = $this->governance->decide(
            $this->steppedUpRequest($approver),
            $change->getKey(),
            true,
            'Independent readiness evidence and rollback were verified.',
        );
        $this->assertSame('approved', $decision->decision);

        $result = $this->governance->executeApproved(
            $this->steppedUpRequest($author),
            $change->getKey(),
            GovernedAction::ActivateProductionSource,
            'integration_source',
            '44',
            $hash,
            fn (): string => 'activated',
        );
        $this->assertSame('activated', $result);
        $this->assertDatabaseHas('governance.change_executions', [
            'change_request_uuid' => $change->getKey(),
            'outcome' => 'success',
        ]);

        $this->expectException(GovernanceViolation::class);
        $this->governance->executeApproved(
            $this->steppedUpRequest($author),
            $change->getKey(),
            GovernedAction::ActivateProductionSource,
            'integration_source',
            '44',
            $hash,
            fn (): string => 'must-not-run',
        );
    }

    public function test_execution_fails_when_payload_or_subject_differs_from_approval(): void
    {
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $hash = $this->governance->hashPayload(['limit' => 100]);
        $change = $this->governance->requestChange(
            $this->steppedUpRequest($author),
            GovernedAction::ExecuteDestructiveReplay,
            'integration_replay',
            'replay-7',
            'Replay scope and affected projections were reviewed.',
            $hash,
        );
        $this->governance->decide(
            $this->steppedUpRequest($approver),
            $change->getKey(),
            true,
            'Replay preview count and recovery plan were independently checked.',
        );

        foreach ([
            ['integration_replay', 'replay-7', $this->governance->hashPayload(['limit' => 101])],
            ['integration_replay', 'replay-8', $hash],
        ] as [$subjectType, $subjectId, $executionHash]) {
            try {
                $this->governance->executeApproved(
                    $this->steppedUpRequest($author),
                    $change->getKey(),
                    GovernedAction::ExecuteDestructiveReplay,
                    $subjectType,
                    $subjectId,
                    $executionHash,
                    fn () => null,
                );
                $this->fail('A changed execution contract must be denied.');
            } catch (GovernanceViolation $exception) {
                $this->assertSame('approved_payload_mismatch', $exception->reason);
            }
        }

        $this->assertSame(0, GovernedChangeExecution::query()->count());
    }

    public function test_rejection_cannot_be_executed(): void
    {
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $hash = $this->governance->hashPayload(['credential_id' => 91]);
        $change = $this->governance->requestChange(
            $this->steppedUpRequest($author),
            GovernedAction::RotateIntegrationCredential,
            'integration_credential',
            '91',
            'Credential rotation is scheduled before its expiry window.',
            $hash,
        );
        $this->governance->decide(
            $this->steppedUpRequest($approver),
            $change->getKey(),
            false,
            'Replacement credential evidence is incomplete and was rejected.',
        );

        $this->expectException(GovernanceViolation::class);
        $this->governance->executeApproved(
            $this->steppedUpRequest($author),
            $change->getKey(),
            GovernedAction::RotateIntegrationCredential,
            'integration_credential',
            '91',
            $hash,
            fn () => null,
        );
    }

    public function test_role_separation_and_step_up_are_both_required(): void
    {
        $author = User::factory()->create(['role' => 'integration_admin']);
        $approver = User::factory()->create(['role' => 'integration_approver']);
        $hash = $this->governance->hashPayload(['source_id' => 3]);

        try {
            $this->governance->requestChange(
                $this->requestFor($author),
                GovernedAction::ActivateProductionSource,
                'integration_source',
                '3',
                'This request has a reason but no recent authentication.',
                $hash,
            );
            $this->fail('Step-up must be required.');
        } catch (\App\Services\Auth\StepUpRequired) {
            $this->addToAssertionCount(1);
        }

        $change = $this->governance->requestChange(
            $this->steppedUpRequest($author),
            GovernedAction::ActivateProductionSource,
            'integration_source',
            '3',
            'All source activation evidence is ready for independent review.',
            $hash,
        );
        $this->governance->decide(
            $this->steppedUpRequest($approver),
            $change->getKey(),
            true,
            'Independent activation review completed with rollback evidence.',
        );

        try {
            $this->governance->executeApproved(
                $this->steppedUpRequest($approver),
                $change->getKey(),
                GovernedAction::ActivateProductionSource,
                'integration_source',
                '3',
                $hash,
                fn () => null,
            );
            $this->fail('An approver without the author capability must not execute.');
        } catch (GovernanceViolation $exception) {
            $this->assertSame('authorization_denied', $exception->reason);
        }
    }

    public function test_database_trigger_rejects_self_approval_even_if_service_is_bypassed(): void
    {
        $author = User::factory()->create(['role' => 'superuser']);
        $hash = $this->governance->hashPayload(['source_id' => 8]);
        $change = $this->governance->requestChange(
            $this->steppedUpRequest($author),
            GovernedAction::ActivateProductionSource,
            'integration_source',
            '8',
            'Source readiness is being submitted for independent approval.',
            $hash,
        );

        DB::beginTransaction();
        try {
            GovernedChangeDecision::query()->create([
                'change_request_uuid' => $change->getKey(),
                'decision' => 'approved',
                'decided_by_user_id' => $author->id,
                'reason' => 'Attempted direct database self approval.',
                'decided_at' => now(),
            ]);
            $this->fail('The database trigger must reject self approval.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('author cannot approve', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_request_and_decision_ledgers_are_database_append_only(): void
    {
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $hash = $this->governance->hashPayload(['policy' => 'manual_approval']);
        $change = $this->governance->requestChange(
            $this->steppedUpRequest($author),
            GovernedAction::ChangeOutboundDispatchPolicy,
            'outbound_policy',
            'default',
            'Outbound dispatch remains human approved for production traffic.',
            $hash,
        );
        $decision = $this->governance->decide(
            $this->steppedUpRequest($approver),
            $change->getKey(),
            true,
            'Independent reviewer confirms the outbound approval constraint.',
        );

        foreach ([
            fn () => GovernedChangeRequest::query()->whereKey($change->getKey())->update(['reason' => 'tampered reason']),
            fn () => GovernedChangeDecision::query()->whereKey($decision->getKey())->delete(),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                $this->fail('Append-only governance rows must reject mutation.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            } finally {
                DB::rollBack();
            }
        }
    }

    private function steppedUpRequest(User $user): Request
    {
        $request = $this->requestFor($user);
        $request->session()->put([
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ]);

        return $request;
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/governed-change-test', 'POST');
        $request->setUserResolver(fn (): User => $user);
        $session = new Store('governed-change-test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }
}
