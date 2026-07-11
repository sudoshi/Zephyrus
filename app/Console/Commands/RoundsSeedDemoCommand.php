<?php

namespace App\Console\Commands;

use App\Models\Encounter;
use App\Models\Rounds\RoundRun;
use App\Models\Rounds\RoundTemplate;
use App\Models\Unit;
use App\Models\User;
use App\Services\Rounds\RoundCommandService;
use App\Services\Rounds\RoundContributionService;
use Database\Seeders\RoundTemplateSeeder;
use Illuminate\Console\Command;

/**
 * Synthetic round generator tied to the demo census (plan §15 Phase 1).
 * Creates today's run for a unit with active encounters, starts it, and
 * submits a plausible nursing note on the first patients so the board
 * demonstrates the full contribution → review workflow. Idempotent per
 * unit/day: an open run for the unit is reused, never duplicated.
 */
class RoundsSeedDemoCommand extends Command
{
    protected $signature = 'rounds:seed-demo
        {unit? : Unit abbreviation or id (default: the busiest unit)}
        {--template= : Template name (default: Unit Multidisciplinary Round)}';

    protected $description = 'Create a demo Virtual Rounds run over the current active census';

    public function handle(RoundCommandService $commands, RoundContributionService $contributions): int
    {
        if (! config('rounds.enabled')) {
            $this->warn('VIRTUAL_ROUNDS_ENABLED is off — seeding data anyway; the UI stays hidden until the flag is on.');
        }

        if (RoundTemplate::query()->count() === 0) {
            $this->call('db:seed', ['--class' => RoundTemplateSeeder::class, '--force' => true]);
        }

        $unit = $this->resolveUnit();

        if ($unit === null) {
            $this->error('No unit with active encounters found. Seed demo data first (php artisan db:seed).');

            return self::FAILURE;
        }

        $templateName = $this->option('template') ?: 'Unit Multidisciplinary Round';
        $template = RoundTemplate::query()->active()->where('name', $templateName)->first();

        if ($template === null) {
            $this->error("Template '{$templateName}' not found.");

            return self::FAILURE;
        }

        $actor = User::query()->where('role', 'admin')->orderBy('id')->first()
            ?? User::query()->orderBy('id')->firstOrFail();

        $existing = RoundRun::query()
            ->open()
            ->where('scope_type', 'unit')
            ->where('scope_key', (string) $unit->unit_id)
            ->whereDate('created_at', now()->toDateString())
            ->first();

        if ($existing !== null) {
            $this->info("Open run already exists for {$unit->name} today: {$existing->run_uuid}");

            return self::SUCCESS;
        }

        $run = $commands->createRun($actor, [
            'template_uuid' => $template->template_uuid,
            'scope_type' => 'unit',
            'scope_key' => (string) $unit->unit_id,
        ]);

        $run = $commands->start($actor, $run);

        // A nursing note on the first two patients makes the board tell a
        // story: partial inputs, one patient nudged into in_progress.
        $notes = [
            'Comfortable overnight; ambulating with assist. Family visiting this morning.',
            'Intermittent pain overnight, controlled with PRN. Asking about discharge timing.',
        ];

        foreach ($run->patients()->orderBy('queue_position')->limit(2)->get() as $index => $patient) {
            $contributions->compose($actor, $patient, [
                'section_code' => 'overnight_events',
                'author_role' => 'bedside_nurse',
                'structured_data' => ['events' => $notes[$index] ?? $notes[0]],
                'summary' => 'Overnight nursing note (demo)',
                'submit' => true,
            ]);
        }

        $this->info("Demo run created for {$unit->name}: {$run->run_uuid} ({$run->patients()->count()} patients)");

        return self::SUCCESS;
    }

    private function resolveUnit(): ?Unit
    {
        $argument = $this->argument('unit');

        if ($argument) {
            $query = Unit::query()->where('is_deleted', false);

            return ctype_digit((string) $argument)
                ? $query->where('unit_id', (int) $argument)->first()
                : $query->whereRaw('LOWER(abbreviation) = ?', [strtolower((string) $argument)])->first();
        }

        $unitId = Encounter::query()
            ->active()
            ->selectRaw('unit_id, count(*) as census')
            ->whereNotNull('unit_id')
            ->groupBy('unit_id')
            ->orderByDesc('census')
            ->value('unit_id');

        return $unitId ? Unit::query()->find($unitId) : null;
    }
}
