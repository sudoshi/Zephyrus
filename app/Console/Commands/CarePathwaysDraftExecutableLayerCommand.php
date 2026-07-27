<?php

namespace App\Console\Commands;

use App\Models\CarePathways\PathwayVersion;
use App\Services\CarePathways\CarePathwayExecutableDraftService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Builds the DRAFT executable layer (stages + milestones) for care-pathway
 * versions that have none, so a pathway can be instantiated for an encounter.
 *
 * These drafts are clinical review INPUT, not approved content. The command
 * cannot approve or activate anything — that stays with a clinician and is
 * recorded in care_pathways.reviews / care_pathways.approvals.
 */
class CarePathwaysDraftExecutableLayerCommand extends Command
{
    protected $signature = 'care-pathways:draft-executable-layer
        {--version-id=* : Limit to specific care_pathways.versions ids}
        {--limit=0 : Maximum versions to process (0 = no limit)}
        {--commit : Persist the draft stages and milestones}
        {--confirm-drafts-need-clinical-review= : Required exact acknowledgement when --commit is supplied}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Derive draft pathway stages and milestones from imported source sections (never approves or activates)';

    public const CONFIRMATION = 'drafts-require-clinical-review-before-activation';

    public function handle(CarePathwayExecutableDraftService $service): int
    {
        if ($this->option('commit')
            && ! hash_equals(self::CONFIRMATION, (string) $this->option('confirm-drafts-need-clinical-review'))) {
            $this->error('--commit requires --confirm-drafts-need-clinical-review='.self::CONFIRMATION);

            return self::INVALID;
        }

        $ids = array_filter((array) $this->option('version-id'));
        $limit = max(0, (int) $this->option('limit'));

        $query = PathwayVersion::query()
            ->where('activation_status', '!=', 'active')
            ->where('institutional_approval_status', '!=', 'approved')
            ->orderBy('pathway_version_id');
        if ($ids !== []) {
            $query->whereIn('pathway_version_id', $ids);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }
        $versions = $query->get();

        if ($versions->isEmpty()) {
            $this->warn('No draftable pathway versions matched.');

            return self::SUCCESS;
        }

        if (! $this->option('commit')) {
            $sample = $versions->first();
            $preview = $service->preview($sample);
            $this->line("Dry run over {$versions->count()} pathway version(s). Sample: version {$preview['version_id']}");
            foreach ($preview['stages'] as $stage) {
                $this->line(sprintf('  %-12s %-20s %d milestones', $stage['key'], '('.$stage['label'].')', count($stage['milestones'])));
                foreach (array_slice($stage['milestones'], 0, 2) as $t) {
                    $this->line('      - '.mb_substr($t, 0, 96).(mb_strlen($t) > 96 ? '…' : ''));
                }
            }
            foreach ($preview['skipped'] as $s) {
                $this->warn('  skipped '.$s);
            }
            $this->line('All output is review_state=draft. Pass --commit --confirm-drafts-need-clinical-review='.self::CONFIRMATION);

            return self::SUCCESS;
        }

        $stages = 0;
        $milestones = 0;
        $replayed = 0;
        $failed = [];

        $bar = $this->output->createProgressBar($versions->count());
        $bar->start();
        foreach ($versions as $version) {
            try {
                $r = $service->draft($version);
                $stages += $r['stages_created'];
                $milestones += $r['milestones_created'];
                $replayed += $r['replayed'] ? 1 : 0;
            } catch (Throwable $e) {
                $failed[] = $version->getKey().': '.$e->getMessage();
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'versions' => $versions->count(),
                'stages_created' => $stages,
                'milestones_created' => $milestones,
                'replayed' => $replayed,
                'failed' => $failed,
            ], JSON_THROW_ON_ERROR));
        }

        $this->info("Drafted {$stages} stages and {$milestones} milestones across {$versions->count()} versions ({$replayed} already present).");
        if ($failed !== []) {
            $this->warn(count($failed).' version(s) failed:');
            foreach (array_slice($failed, 0, 10) as $f) {
                $this->warn('  '.$f);
            }
        }
        $this->line('These are DRAFTS. Nothing is approved, activated, or patient-visible.');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
