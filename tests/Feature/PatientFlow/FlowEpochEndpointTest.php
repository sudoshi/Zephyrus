<?php

namespace Tests\Feature\PatientFlow;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/patient-flow/epoch — the dataset epoch behind atomic client
 * rebootstrap (Codex HFE audit F-6 pt 2 / FLOW-4D plan §8 Phase A3, DI-1).
 * The epoch is the newest TERMINAL ops.demo_refresh_runs row; a `failed`
 * run still advances it because the rebase mutates data before the
 * invariant gate runs. PHPUnit class syntax — Pest is excluded here.
 */
class FlowEpochEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = new User;
        $user->name = 'Epoch Test';
        $user->email = 'epochtest@example.com';
        $user->username = 'epochtest';
        $user->password = bcrypt('secret-test-password');
        $user->role = 'admin';
        $user->save();

        return $user;
    }

    /** @param array<string, mixed> $overrides — refresh_id is a uuid column. */
    private function insertRefreshRun(array $overrides = []): string
    {
        $refreshId = (string) ($overrides['refresh_id'] ?? Str::uuid());

        DB::table('ops.demo_refresh_runs')->insert(array_merge([
            'refresh_id' => $refreshId,
            'scenario_key' => 'summit-reference',
            'seed_version' => 'test',
            'anchor_at' => now(),
            'window_start_at' => now()->subHours(24),
            'window_end_at' => now()->addHours(24),
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(5),
            'status' => 'passed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides, ['refresh_id' => $refreshId]));

        return $refreshId;
    }

    public function test_epoch_is_null_before_any_refresh_has_run(): void
    {
        $this->actingAs($this->user())
            ->getJson('/api/patient-flow/epoch')
            ->assertOk()
            ->assertJsonPath('epoch', null);
    }

    public function test_epoch_reports_the_newest_terminal_refresh_run(): void
    {
        $this->insertRefreshRun(['completed_at' => now()->subHours(7)]);
        $newest = $this->insertRefreshRun([
            'completed_at' => now()->subMinutes(3),
            // A failed run still advances the epoch — the rebase already ran.
            'status' => 'failed',
        ]);
        // A running (non-terminal) row never becomes the epoch.
        $this->insertRefreshRun([
            'completed_at' => null,
            'status' => 'running',
        ]);

        $this->actingAs($this->user())
            ->getJson('/api/patient-flow/epoch')
            ->assertOk()
            ->assertJsonPath('epoch.epoch', $newest)
            ->assertJsonPath('epoch.status', 'failed');
    }

    public function test_summary_carries_the_epoch_envelope(): void
    {
        $refreshId = $this->insertRefreshRun();

        $this->actingAs($this->user())
            ->getJson('/api/patient-flow/summary')
            ->assertOk()
            ->assertJsonPath('epoch.epoch', $refreshId);
    }
}
