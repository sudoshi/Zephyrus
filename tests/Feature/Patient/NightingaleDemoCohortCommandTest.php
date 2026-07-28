<?php

namespace Tests\Feature\Patient;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class NightingaleDemoCohortCommandTest extends TestCase
{
    public function test_command_definition_has_no_password_or_secret_option(): void
    {
        $definition = Artisan::all()['nightingale:demo-cohort']->getDefinition();

        $this->assertTrue($definition->hasArgument('action'));
        $this->assertTrue($definition->hasOption('unit-id'));
        $this->assertTrue($definition->hasOption('confirm'));
        $this->assertTrue($definition->hasOption('json'));
        $this->assertFalse($definition->hasOption('password'));
        $this->assertFalse($definition->hasOption('secret'));
        $this->assertFalse($definition->hasOption('credential'));
    }

    public function test_unknown_action_fails_before_provisioning(): void
    {
        $this->artisan('nightingale:demo-cohort', [
            'action' => 'delete',
        ])
            ->expectsOutput('Action must be preview, apply, verify, or suspend.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_preview_and_apply_require_an_explicit_positive_unit(): void
    {
        $this->artisan('nightingale:demo-cohort', [
            'action' => 'preview',
        ])
            ->expectsOutput('A positive --unit-id is required.')
            ->assertExitCode(Command::INVALID);

        $this->artisan('nightingale:demo-cohort', [
            'action' => 'apply',
            '--unit-id' => '0',
        ])
            ->expectsOutput('A positive --unit-id is required.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_mutating_actions_require_their_exact_distinct_confirmation_phrases(): void
    {
        $this->artisan('nightingale:demo-cohort', [
            'action' => 'apply',
            '--unit-id' => '85',
            '--confirm' => 'suspend-five-synthetic-nightingale-demo-accounts',
        ])
            ->expectsOutput('Apply requires the exact cohort confirmation phrase.')
            ->assertExitCode(Command::INVALID);

        $this->artisan('nightingale:demo-cohort', [
            'action' => 'suspend',
            '--confirm' => 'apply-five-synthetic-nightingale-demo-accounts',
        ])
            ->expectsOutput('Suspend requires the exact cohort confirmation phrase.')
            ->assertExitCode(Command::INVALID);
    }
}
