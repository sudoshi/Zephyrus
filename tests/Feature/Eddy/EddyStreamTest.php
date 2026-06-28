<?php

namespace Tests\Feature\Eddy;

use App\Models\Eddy\EddyCloudUsage;
use App\Models\Eddy\EddyMessage;
use App\Models\User;
use App\Services\Eddy\EddyChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EddyStreamTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EddyChatService
    {
        return app(EddyChatService::class);
    }

    public function test_prepare_stream_persists_the_user_message_and_builds_the_envelope(): void
    {
        $user = User::factory()->create();

        $prep = $this->service()->prepareStream($user, ['message' => 'what is the net bed need?', 'surface' => 'rtdc']);

        $this->assertSame('rtdc', $prep['surface']);
        $this->assertArrayHasKey('allowed_actions', $prep['envelope']);
        $this->assertContains('flag_barrier', $prep['envelope']['allowed_actions']);
        $this->assertDatabaseHas('eddy.eddy_messages', ['role' => 'user', 'content' => 'what is the net bed need?']);
        // No assistant turn yet — that lands on completion.
        $this->assertSame(0, EddyMessage::where('role', 'assistant')->count());
    }

    public function test_persist_stream_result_saves_a_sanitized_proposal(): void
    {
        $user = User::factory()->create();
        $prep = $this->service()->prepareStream($user, ['message' => 'flag it', 'surface' => 'rtdc']);

        // Eddy's complete frame carries the RAW model proposal (no tier/risk/label).
        $assistant = $this->service()->persistStreamResult($user, $prep, [
            'clean_reply' => 'Flagging the imaging barrier.',
            'provider' => 'ollama',
            'model' => 'medgemma',
            'proposed_action' => ['action_type' => 'flag_barrier', 'title' => 'Imaging delay', 'params' => ['unit' => '3W'], 'rationale' => 'held', 'runner_up' => 'escalate'],
        ]);

        $this->assertSame('assistant', $assistant->role);
        $this->assertSame('Flagging the imaging barrier.', $assistant->content);
        $this->assertTrue($assistant->metadata['streamed']);
        // sanitizeProposedAction enriched it with tier/risk/label.
        $this->assertSame('flag_barrier', $assistant->metadata['proposed_action']['action_type']);
        $this->assertSame('T1', $assistant->metadata['proposed_action']['tier']);
    }

    public function test_persist_stream_result_records_cloud_usage_only_for_a_cloud_provider(): void
    {
        $user = User::factory()->create();

        $local = $this->service()->prepareStream($user, ['message' => 'x', 'surface' => 'rtdc']);
        $this->service()->persistStreamResult($user, $local, ['clean_reply' => 'y', 'provider' => 'ollama', 'model' => 'medgemma']);
        $this->assertSame(0, EddyCloudUsage::count());

        $cloud = $this->service()->prepareStream($user, ['message' => 'z', 'surface' => 'command_center']);
        $this->service()->persistStreamResult($user, $cloud, ['clean_reply' => 'w', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);
        $this->assertSame(1, EddyCloudUsage::count());
        $this->assertSame('anthropic', EddyCloudUsage::firstOrFail()->provider);
    }
}
