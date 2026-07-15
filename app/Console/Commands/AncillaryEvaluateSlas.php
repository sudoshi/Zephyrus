<?php

namespace App\Console\Commands;

use App\Models\Ancillary\AncillaryOrder;
use App\Services\Ancillary\SlaEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AncillaryEvaluateSlas extends Command
{
    protected $signature = 'ancillary:evaluate-slas
        {--order-id=* : Restrict evaluation to ancillary_order_id values}
        {--at= : Evaluation time for deterministic rehearsal; defaults to now}
        {--json : Emit a machine-readable summary}';

    protected $description = 'Evaluate governed ancillary SLA clocks and materialize breach lifecycle transitions.';

    public function handle(SlaEvaluator $evaluator): int
    {
        $at = filled($this->option('at'))
            ? CarbonImmutable::parse((string) $this->option('at'))
            : CarbonImmutable::now();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->option('order-id')))));
        $query = AncillaryOrder::query()
            ->when($ids !== [], fn ($query) => $query->whereIn('ancillary_order_id', $ids))
            ->when($ids === [], fn ($query) => $query->where(function ($eligible): void {
                $eligible->whereNull('terminal_at')
                    ->orWhereHas('breaches', fn ($breaches) => $breaches->where('status', 'open'));
            }))
            ->orderBy('ancillary_order_id');

        $summary = [
            'evaluatedAt' => $at->toIso8601String(),
            'ordersSelected' => (clone $query)->count(),
            'ordersEvaluated' => 0,
            'definitionsEvaluated' => 0,
            'warnings' => 0,
            'openBreaches' => 0,
            'breachesOpened' => 0,
            'breachesCleared' => 0,
            'unknown' => 0,
            'failures' => 0,
            'failureItems' => [],
        ];

        $query->chunkById(250, function ($orders) use ($evaluator, $at, &$summary): void {
            foreach ($orders as $order) {
                $result = $evaluator->evaluateOrderSafely($order, $at);
                if (! $result['ok']) {
                    $summary['failures']++;
                    $summary['failureItems'][] = [
                        'orderUuid' => $result['orderUuid'],
                        'errorCode' => $result['errorCode'],
                        'exceptionClass' => $result['exceptionClass'],
                    ];

                    continue;
                }

                $summary['ordersEvaluated']++;
                foreach ($result['evaluations'] as $evaluation) {
                    $summary['definitionsEvaluated']++;
                    $summary['warnings'] += $evaluation['state'] === 'warning' ? 1 : 0;
                    $summary['openBreaches'] += $evaluation['state'] === 'breached' ? 1 : 0;
                    $summary['breachesOpened'] += $evaluation['breachOpened'] ? 1 : 0;
                    $summary['breachesCleared'] += $evaluation['breachCleared'] ? 1 : 0;
                    $summary['unknown'] += $evaluation['state'] === 'unknown' ? 1 : 0;
                }
            }
        }, 'ancillary_order_id');

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
        } else {
            $this->components->info(sprintf(
                'Ancillary SLA evaluation: %d/%d orders, %d clocks, %d opened, %d cleared, %d failures.',
                $summary['ordersEvaluated'],
                $summary['ordersSelected'],
                $summary['definitionsEvaluated'],
                $summary['breachesOpened'],
                $summary['breachesCleared'],
                $summary['failures'],
            ));
        }

        return $summary['failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
