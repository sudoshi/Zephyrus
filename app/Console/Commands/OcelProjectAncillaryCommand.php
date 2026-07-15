<?php

namespace App\Console\Commands;

use App\Domain\Ocel\OcelProjector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class OcelProjectAncillaryCommand extends Command
{
    protected $signature = 'ocel:project-ancillary
        {--order-id=* : Restrict projection to ancillary_order_id values}
        {--since= : Inclusive source-event window start}
        {--until= : Inclusive source-event window end; defaults to now}
        {--days=90 : Trailing window when --since is absent}
        {--json : Emit a machine-readable result}';

    protected $description = 'Idempotently backfill ancillary milestones into the existing OCEL log.';

    public function handle(OcelProjector $projector): int
    {
        $until = filled($this->option('until'))
            ? CarbonImmutable::parse((string) $this->option('until'))
            : CarbonImmutable::now();
        $since = filled($this->option('since'))
            ? CarbonImmutable::parse((string) $this->option('since'))
            : $until->subDays(max(1, (int) $this->option('days')));
        if ($since->greaterThan($until)) {
            $this->components->error('The ancillary OCEL window start must not be after its end.');

            return self::INVALID;
        }

        $orderIds = array_values(array_unique(array_filter(array_map('intval', (array) $this->option('order-id')))));
        $result = $projector->projectAncillary($since, $until, $orderIds);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->components->info(sprintf(
                'Ancillary OCEL projection: %d events, %d objects, %d E2O, %d O2O.',
                $result['events'],
                $result['objects'],
                $result['e2o'],
                $result['o2o'],
            ));
        }

        return self::SUCCESS;
    }
}
