<?php

namespace Tests\Feature\Eddy;

use App\Models\Eddy\EddyUserProfile;
use App\Models\User;
use App\Services\Eddy\EddyLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 6 preference learning: human decisions on Eddy proposals roll up into the
 * user's action preferences and feed back into the chat envelope.
 */
class EddyLearningTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(array $override = []): array
    {
        return array_merge([
            'action_type' => 'flag_barrier',
            'title' => 'Imaging delay blocking 3W discharges',
            'surface' => 'rtdc',
            'rationale' => 'Two discharges held on pending CT reads.',
            'params' => ['unit' => '3W'],
        ], $override);
    }

    public function test_approving_an_eddy_proposal_bumps_frequently_used(): void
    {
        $user = User::factory()->create(['role' => 'bed_manager']);

        $this->actingAs($user)
            ->postJson('/api/eddy/actions/propose', $this->proposal(['approve' => true]))
            ->assertCreated();

        $profile = EddyUserProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1, (int) ($profile->frequently_used['flag_barrier']['approved'] ?? 0));
    }

    public function test_preferences_reflect_approvals_and_rejections(): void
    {
        $user = User::factory()->create();
        $learning = $this->app->make(EddyLearningService::class);

        $learning->recordDecision($user, 'flag_barrier', 'approved');
        $learning->recordDecision($user, 'flag_barrier', 'approved');
        $learning->recordDecision($user, 'propose_bed_placement', 'rejected');

        $prefs = $learning->preferencesFor($user);

        $this->assertContains('flag_barrier', $prefs['preferred_actions']);
        $this->assertContains('propose_bed_placement', $prefs['discouraged_actions']);
    }

    public function test_chat_envelope_carries_learned_preferences(): void
    {
        Http::fake(['*/eddy/chat' => Http::response([
            'reply' => 'ok', 'provider' => 'ollama', 'model' => 'medgemma', 'status' => 'success',
        ], 200)]);

        $user = User::factory()->create();
        $this->app->make(EddyLearningService::class)->recordDecision($user, 'flag_barrier', 'approved');

        $this->actingAs($user)->postJson('/api/eddy/chat', ['message' => 'hello', 'surface' => 'rtdc'])->assertOk();

        Http::assertSent(function ($request): bool {
            $prefs = $request->data()['user_profile']['preferences'] ?? [];

            return is_array($prefs) && in_array('flag_barrier', $prefs['preferred_actions'] ?? [], true);
        });
    }

    public function test_learning_is_a_noop_when_disabled(): void
    {
        config(['eddy.learning.enabled' => false]);
        $user = User::factory()->create();

        $this->app->make(EddyLearningService::class)->recordDecision($user, 'flag_barrier', 'approved');

        $this->assertSame(0, EddyUserProfile::where('user_id', $user->id)->count());
    }
}
