<?php

namespace Tests\Feature\Patient;

use App\Services\Patient\Projection\PatientPathwayHistoryDraftService;
use Illuminate\Console\Command;
use Mockery\MockInterface;
use Tests\TestCase;

class PatientPathwayHistoryDraftCommandTest extends TestCase
{
    public function test_command_requires_one_bounded_batch(): void
    {
        $this->artisan('hummingbird:draft-patient-pathway-history')
            ->expectsOutputToContain('Only bounded --once execution is supported; run it under the approved scheduler.')
            ->assertExitCode(Command::INVALID);

        foreach (['0', '501', 'not-an-integer'] as $limit) {
            $this->artisan('hummingbird:draft-patient-pathway-history', [
                '--once' => true,
                '--limit' => $limit,
            ])
                ->expectsOutputToContain('The batch limit must be an integer from 1 through 500.')
                ->assertExitCode(Command::INVALID);
        }
    }

    public function test_dry_run_and_commit_emit_only_aggregate_results(): void
    {
        $this->mock(PatientPathwayHistoryDraftService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('previewPending')->once()->with(25)->andReturn(['selected' => 7]);
        });
        $this->artisan('hummingbird:draft-patient-pathway-history', [
            '--once' => true,
            '--limit' => '25',
        ])
            ->expectsOutputToContain('Patient pathway-history draft dry run: selected=7 drafted=0 replayed=0 failed=0.')
            ->assertSuccessful();

        $this->mock(PatientPathwayHistoryDraftService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('draftPending')->once()->with(25)->andReturn([
                'selected' => 7,
                'drafted' => 2,
                'replayed' => 5,
                'failed' => 0,
            ]);
        });
        $this->artisan('hummingbird:draft-patient-pathway-history', [
            '--once' => true,
            '--limit' => '25',
            '--commit' => true,
        ])
            ->expectsOutputToContain('Patient pathway-history draft batch complete: selected=7 drafted=2 replayed=5 failed=0.')
            ->assertSuccessful();
    }
}
