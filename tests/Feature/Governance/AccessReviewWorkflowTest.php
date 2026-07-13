<?php

namespace Tests\Feature\Governance;

use App\Models\Auth\UserAccessScope;
use App\Models\Governance\AccessReviewCampaign;
use App\Models\Governance\AccessReviewDecision;
use App\Models\Governance\AccessReviewItem;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccessReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_quarterly_campaign_freezes_privileged_population_assigns_independent_reviewers_and_excludes_plain_users(): void
    {
        [$primary, $alternate] = $this->reviewers();
        $integrationAdmin = User::factory()->create(['role' => 'integration_admin']);
        $plainUser = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($primary)->postJson('/admin/access-reviews', $this->campaignPayload($primary, $alternate))
            ->assertCreated();
        $campaignUuid = $response->json('campaign_uuid');

        $campaign = AccessReviewCampaign::query()->where('campaign_uuid', $campaignUuid)->firstOrFail();
        $this->assertGreaterThanOrEqual(3, $response->json('item_count'));
        $this->assertSame($campaign->items()->count(), $response->json('item_count'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $campaign->snapshot_sha256);
        $this->assertDatabaseHas('governance.access_review_items', [
            'campaign_id' => $campaign->id,
            'subject_user_id' => $primary->id,
            'reviewer_user_id' => $alternate->id,
        ]);
        $this->assertDatabaseHas('governance.access_review_items', [
            'campaign_id' => $campaign->id,
            'subject_user_id' => $alternate->id,
            'reviewer_user_id' => $primary->id,
        ]);
        $this->assertDatabaseHas('governance.access_review_items', [
            'campaign_id' => $campaign->id,
            'subject_user_id' => $integrationAdmin->id,
            'reviewer_user_id' => $primary->id,
        ]);
        $this->assertDatabaseMissing('governance.access_review_items', [
            'campaign_id' => $campaign->id,
            'subject_user_id' => $plainUser->id,
        ]);

        $snapshot = AccessReviewItem::query()
            ->where('campaign_id', $campaign->id)
            ->where('subject_user_id', $integrationAdmin->id)
            ->firstOrFail();
        $this->assertContains('integration_admin', $snapshot->entitlement_snapshot['effective_roles']);
        $this->assertContains('manageIntegrationConfiguration', $snapshot->entitlement_snapshot['effective_capabilities']);
        $this->assertSame(hash('sha256', $this->canonicalJson($snapshot->entitlement_snapshot)), $snapshot->snapshot_sha256);

        $this->actingAs($primary)
            ->get('/admin/access-reviews?campaign='.$campaignUuid)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AccessReviews')
                ->where('selectedCampaign.campaignUuid', $campaignUuid)
                ->has('selectedCampaign.items', $campaign->items()->count()));
    }

    public function test_decisions_require_step_up_assigned_reviewer_and_database_rejects_self_certification(): void
    {
        [$primary, $alternate] = $this->reviewers();
        $campaign = $this->openCampaign($primary, $alternate);
        $primaryItem = AccessReviewItem::query()
            ->where('campaign_id', $campaign->id)
            ->where('subject_user_id', $primary->id)
            ->firstOrFail();

        $payload = $this->retainDecision();
        $this->actingAs($alternate)
            ->postJson("/admin/access-reviews/{$campaign->campaign_uuid}/items/{$primaryItem->item_uuid}/decision", $payload)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'step_up_required');

        $this->actingAs($primary)->withSession($this->stepUp())
            ->postJson("/admin/access-reviews/{$campaign->campaign_uuid}/items/{$primaryItem->item_uuid}/decision", $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'reviewer_mismatch');

        $this->actingAs($alternate)->withSession($this->stepUp())
            ->postJson("/admin/access-reviews/{$campaign->campaign_uuid}/items/{$primaryItem->item_uuid}/decision", $payload)
            ->assertCreated()
            ->assertJsonPath('decision', 'retain');

        DB::beginTransaction();
        try {
            $alternateItem = AccessReviewItem::query()
                ->where('campaign_id', $campaign->id)
                ->where('subject_user_id', $alternate->id)
                ->firstOrFail();
            AccessReviewDecision::query()->create([
                'decision_uuid' => (string) Str::uuid7(),
                'campaign_item_id' => $alternateItem->id,
                'decision' => 'retain',
                'reason_code' => 'business_need_confirmed',
                'rationale' => 'Attempted direct self certification through the model.',
                'decided_by_user_id' => $alternate->id,
                'reviewed_snapshot_sha256' => $alternateItem->snapshot_sha256,
                'decided_at' => now(),
            ]);
            $this->fail('The database trigger must reject self-certification.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('independently assigned reviewer', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_revoke_decision_removes_reviewed_access_scopes_tokens_and_sessions_in_same_transaction(): void
    {
        [$primary, $alternate] = $this->reviewers();
        $subject = User::factory()->create(['role' => 'integration_admin', 'auth_session_version' => 4]);
        $subject->createToken('review-test-token');
        $organization = Organization::query()->create([
            'organization_key' => 'ACCESS_REVIEW_IDN',
            'name' => 'Access Review IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::query()->create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'ACCESS_REVIEW_HOSPITAL',
            'facility_name' => 'Access Review Hospital',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $scope = UserAccessScope::query()->create([
            'user_id' => $subject->id,
            'facility_id' => $facility->facility_id,
            'granted_by_user_id' => $primary->id,
            'grant_reason' => 'Approved integration administration boundary.',
            'valid_from' => now()->subDay(),
        ]);
        $campaign = $this->openCampaign($primary, $alternate);
        $item = AccessReviewItem::query()
            ->where('campaign_id', $campaign->id)
            ->where('subject_user_id', $subject->id)
            ->firstOrFail();
        $this->assertSame('ACCESS_REVIEW_HOSPITAL', $item->entitlement_snapshot['explicit_scopes'][0]['facility_key']);

        $this->actingAs($primary)->withSession($this->stepUp())
            ->postJson("/admin/access-reviews/{$campaign->campaign_uuid}/items/{$item->item_uuid}/decision", [
                'decision' => 'revoke',
                'reason_code' => 'role_or_responsibility_changed',
                'rationale' => 'The owner confirmed this integration administration duty has ended.',
            ])->assertCreated()
            ->assertJsonPath('decision', 'revoke')
            ->assertJsonPath('remediated', true);

        $subject->refresh();
        $this->assertSame('user', $subject->role);
        $this->assertSame(5, $subject->auth_session_version);
        $this->assertSame(0, $subject->tokens()->count());
        $this->assertNotNull($scope->fresh()->revoked_at);
        $this->assertDatabaseHas('governance.access_review_remediations', [
            'executed_by_user_id' => $primary->id,
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'governance.access_review.decided',
            'target_type' => 'access_review_item',
            'target_id' => $item->item_uuid,
        ]);
    }

    public function test_campaign_cannot_complete_early_and_completed_json_and_csv_exports_are_stable_and_digest_addressed(): void
    {
        [$primary, $alternate] = $this->reviewers();
        $campaign = $this->openCampaign($primary, $alternate);

        $this->actingAs($primary)->withSession($this->stepUp())
            ->postJson("/admin/access-reviews/{$campaign->campaign_uuid}/complete")
            ->assertConflict()
            ->assertJsonPath('error.code', 'review_incomplete');

        foreach ($campaign->items()->orderBy('id')->get() as $item) {
            $reviewer = (int) $item->reviewer_user_id === (int) $primary->id ? $primary : $alternate;
            $this->actingAs($reviewer)->withSession($this->stepUp())
                ->postJson("/admin/access-reviews/{$campaign->campaign_uuid}/items/{$item->item_uuid}/decision", $this->retainDecision())
                ->assertCreated();
        }

        $completion = $this->actingAs($primary)->withSession($this->stepUp())
            ->postJson("/admin/access-reviews/{$campaign->campaign_uuid}/complete")
            ->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $completion->json('evidence_sha256'));

        $first = $this->actingAs($primary)->get("/admin/access-reviews/{$campaign->campaign_uuid}/evidence.json")
            ->assertOk()
            ->assertHeader('content-type', 'application/json');
        $second = $this->actingAs($primary)->get("/admin/access-reviews/{$campaign->campaign_uuid}/evidence.json")
            ->assertOk();
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame('zephyrus.access-review-evidence.v1', $first->json('schema'));
        $this->assertSame($campaign->items()->count(), count($first->json('items')));
        $this->assertSame('"'.hash('sha256', $first->getContent()).'"', $first->headers->get('etag'));

        $csv = $this->actingAs($alternate)->get("/admin/access-reviews/{$campaign->campaign_uuid}/evidence.csv")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('campaign_uuid,item_uuid,subject_user_id', $csv->getContent());
        $this->assertDatabaseCount('governance.access_review_exports', 3);
    }

    public function test_access_review_evidence_rows_and_closed_campaigns_are_database_immutable(): void
    {
        [$primary, $alternate] = $this->reviewers();
        $campaign = $this->openCampaign($primary, $alternate);
        $item = $campaign->items()->firstOrFail();

        foreach ([
            fn () => AccessReviewItem::query()->whereKey($item->id)->update(['snapshot_sha256' => str_repeat('0', 64)]),
            fn () => AccessReviewItem::query()->whereKey($item->id)->delete(),
            fn () => AccessReviewCampaign::query()->whereKey($campaign->id)->update(['snapshot_sha256' => str_repeat('0', 64)]),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                $this->fail('Access-review snapshot evidence must be append-only.');
            } catch (QueryException $exception) {
                $this->assertTrue(
                    str_contains($exception->getMessage(), 'append-only')
                    || str_contains($exception->getMessage(), 'immutable'),
                );
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_auditor_is_read_only_and_unprivileged_user_is_denied(): void
    {
        [$primary, $alternate] = $this->reviewers();
        $campaign = $this->openCampaign($primary, $alternate);
        $auditor = User::factory()->create(['role' => 'auditor']);
        $plain = User::factory()->create(['role' => 'user']);

        $this->actingAs($auditor)->get('/admin/access-reviews?campaign='.$campaign->campaign_uuid)->assertOk();
        $this->actingAs($auditor)->postJson('/admin/access-reviews', $this->campaignPayload($primary, $alternate))->assertForbidden();
        $this->actingAs($plain)->get('/admin/access-reviews')->assertForbidden();
    }

    public function test_campaign_period_must_be_a_calendar_quarter_and_duplicate_period_is_serialized(): void
    {
        [$primary, $alternate] = $this->reviewers();
        $invalid = $this->campaignPayload($primary, $alternate);
        $invalid['review_period_start'] = '2026-07-02';

        $this->actingAs($primary)->postJson('/admin/access-reviews', $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_period_end');

        $this->openCampaign($primary, $alternate);
        $this->actingAs($primary)->postJson('/admin/access-reviews', $this->campaignPayload($primary, $alternate))
            ->assertConflict()
            ->assertJsonPath('error.code', 'campaign_period_exists');
        $this->assertDatabaseCount('governance.access_review_campaigns', 1);

        $campaign = AccessReviewCampaign::query()->firstOrFail();
        $this->actingAs($primary)
            ->postJson("/admin/access-reviews/{$campaign->campaign_uuid}/cancel", [
                'reason' => 'The assigned reviewer is unavailable for the certification window.',
            ])->assertStatus(428);
        $this->actingAs($primary)->withSession($this->stepUp())
            ->postJson("/admin/access-reviews/{$campaign->campaign_uuid}/cancel", [
                'reason' => 'The assigned reviewer is unavailable for the certification window.',
            ])->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->actingAs($primary)->postJson('/admin/access-reviews', $this->campaignPayload($primary, $alternate))
            ->assertCreated();
        $this->assertDatabaseCount('governance.access_review_campaigns', 2);

        DB::beginTransaction();
        try {
            AccessReviewCampaign::query()->whereKey($campaign->id)->update(['title' => 'Tampered closed campaign']);
            $this->fail('A cancelled campaign must be immutable.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{User, User} */
    private function reviewers(): array
    {
        return [
            User::factory()->create(['role' => 'admin', 'is_protected' => false]),
            User::factory()->create(['role' => 'admin', 'is_protected' => false]),
        ];
    }

    private function openCampaign(User $primary, User $alternate): AccessReviewCampaign
    {
        $uuid = $this->actingAs($primary)
            ->postJson('/admin/access-reviews', $this->campaignPayload($primary, $alternate))
            ->assertCreated()
            ->json('campaign_uuid');

        return AccessReviewCampaign::query()->where('campaign_uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, int|string> */
    private function campaignPayload(User $primary, User $alternate): array
    {
        return [
            'title' => '2026 Q3 privileged access certification',
            'review_period_start' => '2026-07-01',
            'review_period_end' => '2026-09-30',
            'due_at' => now()->addDays(21)->toIso8601String(),
            'primary_reviewer_user_id' => $primary->id,
            'alternate_reviewer_user_id' => $alternate->id,
        ];
    }

    /** @return array<string, string> */
    private function retainDecision(): array
    {
        return [
            'decision' => 'retain',
            'reason_code' => 'business_need_confirmed',
            'rationale' => 'Current responsibilities and least-privilege access were independently confirmed.',
        ];
    }

    /** @return array<string, int|string> */
    private function stepUp(): array
    {
        return [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $canonicalize = function (array $input) use (&$canonicalize): array {
            foreach ($input as $key => $item) {
                if (is_array($item)) {
                    $input[$key] = $canonicalize($item);
                }
            }
            if (! array_is_list($input)) {
                ksort($input, SORT_STRING);
            }

            return $input;
        };

        return json_encode($canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
