<?php

namespace Tests\Feature\Eddy;

use App\Models\Eddy\EddyCloudUsage;
use App\Models\Eddy\EddyConversation;
use App\Models\Eddy\EddyMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EddyChatProxyTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEddy(array $override = []): void
    {
        Http::fake([
            '*/eddy/chat' => Http::response(array_merge([
                'reply' => 'Net bed need is 12; 3 ED boarders. Suggest 2 early geri transfers (runner-up: hold 1 ED admit).',
                'provider' => 'ollama',
                'transport' => 'ollama_chat',
                'model' => 'medgemma',
                'surface' => 'chat',
                'tokens_in' => 50,
                'tokens_out' => 30,
                'cost_usd' => 0.0,
                'latency_ms' => 120.0,
                'route_reason' => 'local_only',
                'fallback_reason' => null,
                'sanitizer_redaction_count' => 0,
                'status' => 'success',
            ], $override), 200),
        ]);
    }

    public function test_chat_creates_conversation_and_persists_user_and_assistant_messages(): void
    {
        $this->fakeEddy();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/eddy/chat', [
            'message' => 'what is the net bed need?',
            'surface' => 'rtdc',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.message.role', 'assistant');

        $conversation = EddyConversation::forUser($user->id)->firstOrFail();
        $this->assertSame('rtdc', $conversation->surface);
        $this->assertSame(2, EddyMessage::where('eddy_conversation_id', $conversation->eddy_conversation_id)->count());
        $this->assertDatabaseHas('eddy.eddy_messages', ['role' => 'user', 'content' => 'what is the net bed need?']);
    }

    public function test_proxies_a_well_formed_envelope_to_the_eddy_service(): void
    {
        $this->fakeEddy();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/eddy/chat', [
            'message' => 'summarize the bed meeting',
            'surface' => 'rtdc',
        ])->assertOk();

        Http::assertSent(function ($request) use ($user) {
            $body = $request->data();

            return str_ends_with($request->url(), '/eddy/chat')
                && $body['message'] === 'summarize the bed meeting'
                && $body['surface'] === 'rtdc'
                && $body['user_id'] === $user->id
                && isset($body['provider_policy']['provider_type'])
                && array_key_exists('history', $body);
        });
    }

    public function test_cloud_provider_response_writes_a_cloud_usage_row(): void
    {
        $this->fakeEddy([
            'provider' => 'anthropic',
            'transport' => 'anthropic_messages',
            'model' => 'claude-sonnet-4-6',
            'tokens_in' => 120,
            'tokens_out' => 60,
            'cost_usd' => 0.0012,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/eddy/chat', ['message' => 'propose a surge plan', 'surface' => 'command_center'])->assertOk();

        $this->assertSame(1, EddyCloudUsage::count());
        $usage = EddyCloudUsage::firstOrFail();
        $this->assertSame('anthropic', $usage->provider);
        $this->assertEqualsWithDelta(0.0012, $usage->cost_usd, 0.00001);
        $this->assertSame('command_center', $usage->request_surface);
    }

    public function test_local_provider_response_writes_no_cloud_usage(): void
    {
        $this->fakeEddy(); // provider=ollama
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/eddy/chat', ['message' => 'what is the census?'])->assertOk();

        $this->assertSame(0, EddyCloudUsage::count());
    }

    public function test_conversation_is_user_isolated(): void
    {
        $this->fakeEddy();
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->postJson('/api/eddy/chat', ['message' => 'hello'])->assertOk();
        $uuid = EddyConversation::forUser($owner->id)->firstOrFail()->eddy_conversation_uuid;

        // The other user cannot read it.
        $this->actingAs($other)->getJson("/api/eddy/conversations/{$uuid}")->assertNotFound();
        // The owner can.
        $this->actingAs($owner)->getJson("/api/eddy/conversations/{$uuid}")->assertOk()->assertJsonPath('data.id', $uuid);
    }

    public function test_continues_an_existing_conversation(): void
    {
        $this->fakeEddy();
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/api/eddy/chat', ['message' => 'first'])->assertOk();
        $uuid = $first->json('data.conversation_id');

        $this->actingAs($user)->postJson('/api/eddy/chat', ['message' => 'second', 'conversation_id' => $uuid])->assertOk();

        $this->assertSame(1, EddyConversation::forUser($user->id)->count());
        $conversation = EddyConversation::forUser($user->id)->firstOrFail();
        $this->assertSame(4, EddyMessage::where('eddy_conversation_id', $conversation->eddy_conversation_id)->count());
    }

    public function test_eddy_unavailable_returns_503_and_does_not_persist_assistant_turn(): void
    {
        Http::fake(['*/eddy/chat' => Http::response('upstream down', 500)]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/eddy/chat', ['message' => 'hello']);

        $response->assertStatus(503)->assertJsonPath('data.status', 'error');
        // The user turn persisted; no assistant turn was written.
        $this->assertSame(0, EddyMessage::where('role', 'assistant')->count());
    }

    public function test_message_is_required(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/eddy/chat', ['surface' => 'rtdc'])->assertStatus(422);
    }
}
