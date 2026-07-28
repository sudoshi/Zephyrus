<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CarePathwaysPromoteExecutableLayerCommand extends Command
{
    protected $signature = 'care-pathways:promote-executable-layer
        {--version-id=* : Limit to these pathway_version_id values}
        {--pathway-key=* : Limit to these definition pathway_key values}
        {--confirm= : Must equal promote-drafts-to-approved to write}
        {--dry-run : Report what would be promoted without writing}';

    protected $description = 'Promote drafted care-pathway stage/milestone definitions (review_state draft→approved) for named versions — the reviewed step that makes a version assignable. Scope is REQUIRED; there is no promote-everything.';

    private const CONFIRM = 'promote-drafts-to-approved';

    public function handle(): int
    {
        $versionIds = array_values(array_unique(array_map('intval', (array) $this->option('version-id'))));
        $pathwayKeys = array_values(array_filter(array_map('trim', (array) $this->option('pathway-key'))));

        if ($versionIds === [] && $pathwayKeys === []) {
            $this->error('Scope is required: pass at least one --version-id or --pathway-key. This command never promotes the whole catalog.');

            return self::INVALID;
        }

        $targets = DB::table('care_pathways.versions as v')
            ->join('care_pathways.definitions as d', 'd.pathway_definition_id', '=', 'v.pathway_definition_id')
            ->when($versionIds !== [], fn ($q) => $q->whereIn('v.pathway_version_id', $versionIds))
            ->when($pathwayKeys !== [], fn ($q) => $q->orWhereIn('d.pathway_key', $pathwayKeys))
            ->pluck('v.pathway_version_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($targets->isEmpty()) {
            $this->warn('No matching versions found for the given scope.');

            return self::SUCCESS;
        }

        $draftMilestones = DB::table('care_pathways.milestone_definitions')
            ->whereIn('pathway_version_id', $targets)->where('review_state', 'draft')->count();
        $draftStages = DB::table('care_pathways.stage_definitions')
            ->whereIn('pathway_version_id', $targets)->where('review_state', 'draft')->count();

        $this->line(sprintf('Scope: %d version(s). Drafts to promote: %d milestones, %d stages.', $targets->count(), $draftMilestones, $draftStages));

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        if ($this->option('confirm') !== self::CONFIRM) {
            $this->error('Refusing to write. Re-run with --confirm='.self::CONFIRM.' (records your promotion of these drafts to approved).');

            return self::INVALID;
        }

        [$promotedMilestones, $promotedStages] = DB::transaction(function () use ($targets): array {
            $m = DB::table('care_pathways.milestone_definitions')
                ->whereIn('pathway_version_id', $targets)->where('review_state', 'draft')
                ->update(['review_state' => 'approved', 'updated_at' => now()]);
            $s = DB::table('care_pathways.stage_definitions')
                ->whereIn('pathway_version_id', $targets)->where('review_state', 'draft')
                ->update(['review_state' => 'approved', 'updated_at' => now()]);

            return [$m, $s];
        });

        $this->info(sprintf('Promoted %d milestone(s) and %d stage(s) to approved across %d version(s).', $promotedMilestones, $promotedStages, $targets->count()));

        return self::SUCCESS;
    }
}
