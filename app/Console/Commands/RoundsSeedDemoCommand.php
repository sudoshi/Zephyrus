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
 * demonstrates the full contribution → review workflow.
 *
 * Idempotent per unit/day: an open run for the unit is reused, never
 * duplicated — UNLESS --refresh is passed, in which case the stale open run is
 * cancelled and a fresh cohort is built against the CURRENT census. The 6-hourly
 * demo refresh calls this with --units + --refresh so a walkthrough always opens
 * onto a live board re-anchored to "now".
 */
class RoundsSeedDemoCommand extends Command
{
    protected $signature = 'rounds:seed-demo
        {unit? : Unit abbreviation or id (default: the busiest unit)}
        {--units= : Comma-separated units (abbreviation or id); overrides the positional arg}
        {--refresh : Cancel the unit'."'".'s existing open run and rebuild against the current census}
        {--template= : Template name (default: Unit Multidisciplinary Round)}';

    protected $description = 'Create (or refresh) demo Virtual Rounds runs over the current active census';

    public function handle(RoundCommandService $commands, RoundContributionService $contributions): int
    {
        if (! config('rounds.enabled')) {
            $this->warn('VIRTUAL_ROUNDS_ENABLED is off — seeding data anyway; the UI stays hidden until the flag is on.');
        }

        if (RoundTemplate::query()->count() === 0) {
            $this->call('db:seed', ['--class' => RoundTemplateSeeder::class, '--force' => true]);
        }

        $templateName = $this->option('template') ?: 'Unit Multidisciplinary Round';
        $template = RoundTemplate::query()->active()->where('name', $templateName)->first();

        if ($template === null) {
            $this->error("Template '{$templateName}' not found.");

            return self::FAILURE;
        }

        $actor = User::query()->where('role', 'admin')->orderBy('id')->first()
            ?? User::query()->orderBy('id')->firstOrFail();

        $units = $this->resolveUnits();

        if ($units === []) {
            $this->error('No unit with active encounters found. Seed demo data first (php artisan db:seed).');

            return self::FAILURE;
        }

        $refresh = (bool) $this->option('refresh');
        $seeded = 0;

        foreach ($units as $unit) {
            if ($this->seedUnit($unit, $template, $actor, $commands, $contributions, $refresh)) {
                $seeded++;
            }
        }

        $this->info("Rounds demo: processed {$seeded}/".count($units).' unit(s).');

        return self::SUCCESS;
    }

    private function seedUnit(
        Unit $unit,
        RoundTemplate $template,
        User $actor,
        RoundCommandService $commands,
        RoundContributionService $contributions,
        bool $refresh,
    ): bool {
        $openRuns = RoundRun::query()
            ->open()
            ->where('scope_type', 'unit')
            ->where('scope_key', (string) $unit->unit_id)
            ->get();

        if (! $refresh) {
            $today = $openRuns->first(fn (RoundRun $r) => $r->created_at?->isToday() ?? false);
            if ($today !== null) {
                $this->info("Open run already exists for {$unit->name} today: {$today->run_uuid}");

                return false;
            }
        } else {
            // Retire EVERY open run for this unit (any date) so the fresh cohort
            // is the only board — the census shifts to "now", and cancelling only
            // today's run would leak yesterday's straggler on each daily refresh.
            foreach ($openRuns as $stale) {
                $commands->cancel($actor, $stale, ['reason' => 'demo-refresh rebuild']);
            }
        }

        $run = $commands->createRun($actor, [
            'template_uuid' => $template->template_uuid,
            'scope_type' => 'unit',
            'scope_key' => (string) $unit->unit_id,
        ]);
        $run = $commands->start($actor, $run);

        // A nursing note on the first two patients makes the board tell a story:
        // partial inputs, one patient nudged into in_progress.
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

        return true;
    }

    /** @return list<Unit> */
    private function resolveUnits(): array
    {
        $list = (string) $this->option('units');
        if ($list !== '') {
            $tokens = array_values(array_filter(array_map('trim', explode(',', $list))));

            return array_values(array_filter(array_map(fn ($t) => $this->resolveUnit($t), $tokens)));
        }

        $argument = $this->argument('unit');
        if ($argument) {
            $unit = $this->resolveUnit((string) $argument);

            return $unit ? [$unit] : [];
        }

        $unit = $this->busiestUnit();

        return $unit ? [$unit] : [];
    }

    private function resolveUnit(string $token): ?Unit
    {
        $query = Unit::query()->where('is_deleted', false);

        return ctype_digit($token)
            ? $query->where('unit_id', (int) $token)->first()
            : $query->whereRaw('LOWER(abbreviation) = ?', [strtolower($token)])->first();
    }

    private function busiestUnit(): ?Unit
    {
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
