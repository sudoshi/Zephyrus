<?php

namespace App\Services\Pharmacy;

use App\Models\Pharmacy\AdcStation;
use App\Services\Pharmacy\Forecast\PharmacyForecastModelArtifact;
use App\Services\Pharmacy\Forecast\StockoutFeatureExtractor;
use App\Services\Pharmacy\Forecast\StockoutObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sole server authority for the P4-3 Pharmacy planning forecasts.
 *
 * Queue-depth and stockout targets remain independent. Station inventory is
 * optional: without valid on-hand/par evidence the service emits only a named
 * velocity-pressure observation with null probability/band. All aggregation is
 * station/medication scoped; no user or staff dimension is queried or exposed.
 */
final class PharmacyForecastService
{
    public const QUEUE_HORIZON_HOURS = 8;

    public const STOCKOUT_HORIZON_HOURS = 6;

    public const LOOKBACK_HOURS = 24;

    public const QUEUE_LOOKBACK_DAYS = 28;

    public function __construct(private readonly StockoutFeatureExtractor $extractor) {}

    /** @return array<string, mixed> */
    public function queueForecast(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $artifact = PharmacyForecastModelArtifact::loadOrNull();
        if ($artifact === null) {
            return $this->unavailableQueue('The versioned Pharmacy forecast artifact is missing.');
        }

        $currentDepth = DB::table('prod.rx_verifications')->where('verification_state', 'queued')->count();
        $historyStart = DB::table('prod.rx_verifications')->min('queued_at');
        if ($historyStart === null) {
            return $this->unavailableQueue('No verification-queue history is available; queue depth is observed as zero but no forecast is claimed.', $artifact);
        }

        $windowStart = $now->subDays(self::QUEUE_LOOKBACK_DAYS);
        $arrivals = $this->hourOfWeekRates('queued_at', $windowStart, $now);
        $completions = $this->completionHourOfWeekRates($windowStart, $now);
        $historyHours = max(1, (int) floor(CarbonImmutable::parse($historyStart)->diffInHours($now, false)));
        $weeks = max(1.0, min(self::QUEUE_LOOKBACK_DAYS / 7.0, $historyHours / 168.0));
        $recentArrivals = DB::table('prod.rx_verifications')->whereBetween('queued_at', [$now->subHours(3), $now])->count();
        $recentCompletions = DB::table('prod.rx_verifications')
            ->where(fn ($query) => $query->whereBetween('verified_at', [$now->subHours(3), $now])
                ->orWhereBetween('removed_at', [$now->subHours(3), $now]))
            ->count();
        $recentTrend = ($recentArrivals - $recentCompletions) / 3.0;
        $queueArtifact = $artifact->queue();
        $model = (array) ($queueArtifact['model'] ?? []);
        $seasonal = (array) ($model['seasonalNetByHourOfWeek'] ?? []);
        $scheduledWeight = (float) ($model['scheduledDemandWeight'] ?? 0.0);
        $expectedScheduled = (float) ($model['expectedScheduledDemand'] ?? 0.0);
        $trendWeight = (float) ($model['recentTrendWeight'] ?? 0.0);
        $rmse = max(0.0, (float) ($model['residualRmse'] ?? 0.0));
        $sufficientHistory = $historyHours >= 168;
        $points = [];
        $depth = (float) $currentDepth;

        for ($offset = 1; $offset <= self::QUEUE_HORIZON_HOURS; $offset++) {
            $from = $now->addHours($offset - 1);
            $to = $now->addHours($offset);
            $hourOfWeek = (((int) $to->format('N')) - 1) * 24 + (int) $to->format('G');
            $historicalArrival = ($arrivals[$hourOfWeek] ?? 0.0) / $weeks;
            $historicalCompletion = ($completions[$hourOfWeek] ?? 0.0) / $weeks;
            $artifactNet = (float) ($seasonal[$hourOfWeek] ?? 0.0);
            $historicalNet = array_key_exists($hourOfWeek, $arrivals) || array_key_exists($hourOfWeek, $completions)
                ? $historicalArrival - $historicalCompletion
                : $artifactNet;
            $scheduledDemand = DB::table('prod.rx_orders')
                ->whereNotNull('due_at')
                ->where('due_at', '>', $from)
                ->where('due_at', '<=', $to)
                ->whereNotIn('order_status', ['administered', 'discontinued', 'cancelled', 'completed'])
                ->count();
            $startDepth = $depth;
            $depth = max(0.0, $depth + $historicalNet
                + $scheduledWeight * ($scheduledDemand - $expectedScheduled)
                + $trendWeight * $recentTrend);
            $interval = 1.96 * $rmse * sqrt($offset);
            $points[] = [
                'at' => $to->startOfHour()->toAtomString(),
                'horizonHour' => $offset,
                'startingDepth' => round($startDepth, 1),
                'forecastDepth' => round($depth, 1),
                'lowerDepth' => round(max(0.0, $depth - $interval), 1),
                'upperDepth' => round($depth + $interval, 1),
                'historicalArrivalRate' => round($historicalArrival, 2),
                'historicalCompletionRate' => round($historicalCompletion, 2),
                'scheduledDemand' => $scheduledDemand,
                'scheduledDemandContribution' => round($scheduledWeight * ($scheduledDemand - $expectedScheduled), 2),
            ];
        }

        return [
            'kind' => 'forecast',
            'target' => 'verification_queue_depth',
            'status' => $sufficientHistory ? 'available' : 'low_confidence',
            'horizonHours' => self::QUEUE_HORIZON_HOURS,
            'currentDepth' => $currentDepth,
            'history' => [
                'lookbackDays' => self::QUEUE_LOOKBACK_DAYS,
                'historyHours' => $historyHours,
                'seasonality' => 'hour_of_week',
                'recentNetPerHour' => round($recentTrend, 2),
            ],
            'points' => $points,
            'missingSignals' => $sufficientHistory ? [] : ['seven_days_of_observed_queue_history'],
            'explanation' => $sufficientHistory
                ? 'Hourly queue depth combines observed hour-of-week arrivals/completions, recent trend, and orders already scheduled in each horizon bucket.'
                : 'Less than seven days of observed queue history is available; the synthetic artifact seasonality fills sparse hours and the series is low confidence.',
            'provenance' => $artifact->provenance(),
        ];
    }

    /** @return array<string, mixed> */
    public function stockoutForecast(?CarbonImmutable $now = null, ?string $stationType = null): array
    {
        $now ??= CarbonImmutable::now();
        $artifact = PharmacyForecastModelArtifact::loadOrNull();
        if ($artifact === null) {
            return $this->unavailableStockout('The versioned Pharmacy forecast artifact is missing.');
        }

        $stations = AdcStation::query()->with('unit')
            ->when($stationType, fn ($query, string $type) => $query->where('station_type', $type))
            ->orderBy('adc_station_id')->get();
        if ($stations->isEmpty()) {
            return $this->unavailableStockout('No ADC station registry is available.', $artifact);
        }
        $transactions = DB::table('prod.adc_transactions')
            ->whereBetween('occurred_at', [$now->subHours(self::LOOKBACK_HOURS), $now])
            ->orderBy('occurred_at')
            ->get(['adc_station_id', 'transaction_type', 'quantity', 'occurred_at', 'metadata']);
        $shortageCodes = DB::table('prod.rx_orders')
            ->where('on_shortage', true)
            ->whereNotIn('order_status', ['administered', 'discontinued', 'cancelled', 'completed'])
            ->pluck('local_code')->map(fn (mixed $code): string => (string) $code)->flip();
        $terminology = DB::table('prod.rx_orders')
            ->whereNotNull('local_code')
            ->selectRaw('local_code, min(terminology_status) AS terminology_status')
            ->groupBy('local_code')
            ->pluck('terminology_status', 'local_code');
        $rows = [];
        foreach ($stations as $station) {
            $stationTransactions = $transactions->where('adc_station_id', $station->adc_station_id);
            $metadata = is_array($station->metadata) ? $station->metadata : [];
            $inventory = is_array($metadata['inventory'] ?? null) ? $metadata['inventory'] : [];
            $transactionCodes = $stationTransactions->map(fn (object $transaction): ?string => $this->transactionMetadata($transaction)['local_code'] ?? null)
                ->filter()->unique()->values();
            $codes = collect(array_keys($inventory))->merge($transactionCodes)->unique()->sort()->values();
            foreach ($codes as $localCode) {
                $entry = is_array($inventory[$localCode] ?? null) ? $inventory[$localCode] : null;
                $rows[] = $this->stockoutRow(
                    $artifact,
                    $station,
                    (string) $localCode,
                    $entry,
                    $stationTransactions,
                    $shortageCodes->has((string) $localCode),
                    (string) ($terminology->get((string) $localCode) ?? 'unmapped_local'),
                    $now,
                );
            }
        }

        usort($rows, static function (array $left, array $right): int {
            $observed = ['observed' => 0, 'available' => 1, 'low_confidence' => 2, 'velocity_only' => 3, 'unavailable' => 4];
            $availability = ($observed[$left['availability']] ?? 9) <=> ($observed[$right['availability']] ?? 9);
            if ($availability !== 0) {
                return $availability;
            }

            return ($right['probability'] ?? -1.0) <=> ($left['probability'] ?? -1.0)
                ?: strcmp($left['stationLabel'].'|'.$left['localCode'], $right['stationLabel'].'|'.$right['localCode']);
        });

        return [
            'kind' => 'forecast',
            'target' => 'station_medication_stockout_within_six_hours',
            'status' => $rows === [] ? 'unavailable' : (collect($rows)->contains(fn (array $row): bool => in_array($row['availability'], ['available', 'low_confidence'], true)) ? 'available' : 'velocity_only'),
            'horizonHours' => self::STOCKOUT_HORIZON_HOURS,
            'lookbackHours' => self::LOOKBACK_HOURS,
            'rows' => $rows,
            'coverage' => [
                'stationMedicationPairs' => count($rows),
                'probabilityAvailable' => collect($rows)->whereIn('availability', ['available', 'low_confidence'])->count(),
                'velocityOnly' => collect($rows)->where('availability', 'velocity_only')->count(),
                'observedStockouts' => collect($rows)->where('availability', 'observed')->count(),
            ],
            'explanation' => 'Probability is computed only for a station-medication pair with valid on-hand/par and refill evidence. Missing inventory yields a separate velocity-pressure observation with null probability and band.',
            'provenance' => $artifact->provenance(),
            'privacy' => [
                'stationMedicationAggregatesOnly' => true,
                'individualStaffFeaturesIncluded' => false,
                'controlledDiversionScoreIncluded' => false,
            ],
        ];
    }

    /** @param Collection<int, object> $transactions @param array<string,mixed>|null $entry @return array<string,mixed> */
    private function stockoutRow(PharmacyForecastModelArtifact $artifact, AdcStation $station, string $localCode, ?array $entry, Collection $transactions, bool $shortage, string $terminologyStatus, CarbonImmutable $now): array
    {
        $medicationTransactions = $transactions->filter(fn (object $transaction): bool => ($this->transactionMetadata($transaction)['local_code'] ?? null) === $localCode);
        $vends = $medicationTransactions->whereIn('transaction_type', ['vend', 'override']);
        $refills = $medicationTransactions->where('transaction_type', 'refill');
        $vendUnits = $vends->sum(fn (object $row): float => max(0.0, (float) ($row->quantity ?? 1.0)));
        $refillUnits = $refills->sum(fn (object $row): float => max(0.0, (float) ($row->quantity ?? 0.0)));
        $lastRefill = $refills->max('occurred_at');
        $vendPerHour = round($vendUnits / self::LOOKBACK_HOURS, 4);
        $refillPerHour = round($refillUnits / self::LOOKBACK_HOURS, 4);
        $minutesSinceRefill = $lastRefill !== null
            ? max(0.0, CarbonImmutable::parse($lastRefill)->diffInMinutes($now, false))
            : (isset($entry['refill_cadence_minutes']) && is_numeric($entry['refill_cadence_minutes']) ? (float) $entry['refill_cadence_minutes'] : null);
        $metadata = is_array($station->metadata) ? $station->metadata : [];
        $openStockouts = is_array($metadata['open_stockouts'] ?? null) ? $metadata['open_stockouts'] : [];
        $observedStockout = array_key_exists($localCode, $openStockouts) || array_key_exists('*', $openStockouts);
        $medicationLabel = (string) ($entry['medication_label'] ?? $medicationTransactions->map(fn (object $row): ?string => $this->transactionMetadata($row)['medication_label'] ?? null)->filter()->first() ?? $localCode);
        $inventoryCapturedAt = $this->inventoryCapturedAt($entry);
        $base = [
            'stationId' => (int) $station->adc_station_id,
            'stationKey' => (string) $station->source_station_key,
            'stationLabel' => (string) $station->label,
            'unitLabel' => $station->unit?->name,
            'localCode' => $localCode,
            'medicationLabel' => $medicationLabel,
            'terminologyStatus' => in_array($terminologyStatus, ['mapped', 'unmapped_local'], true) ? $terminologyStatus : 'unmapped_local',
            'horizonHours' => self::STOCKOUT_HORIZON_HOURS,
            'inventory' => [
                'onHand' => isset($entry['on_hand']) && is_numeric($entry['on_hand']) ? (float) $entry['on_hand'] : null,
                'parLevel' => isset($entry['par_level']) && is_numeric($entry['par_level']) ? (float) $entry['par_level'] : null,
                'capturedAt' => $inventoryCapturedAt?->format(DATE_ATOM),
            ],
            'velocityPressure' => [
                'vendUnitsPerHour' => $vendPerHour,
                'refillUnitsPerHour' => $refillPerHour,
                'minutesSinceRefill' => $minutesSinceRefill === null ? null : round($minutesSinceRefill, 1),
                'shortageFlag' => $shortage,
                'basis' => 'Station-level ADC transactions only; no on-hand inventory is inferred from velocity.',
            ],
        ];

        if ($observedStockout) {
            return [...$base,
                'availability' => 'observed',
                'observedState' => 'stockout_open',
                'probability' => null,
                'band' => null,
                'factors' => [],
                'missingSignals' => [],
                'explanation' => 'An open stockout is an observed operational fact, not a forecast; probability is intentionally withheld.',
            ];
        }
        if ($entry === null) {
            return [...$base,
                'availability' => 'velocity_only',
                'observedState' => 'none',
                'probability' => null,
                'band' => null,
                'factors' => [],
                'missingSignals' => ['on_hand', 'par_level', 'inventory_captured_at'],
                'explanation' => 'No inventory snapshot exists for this station-medication pair; only observed velocity pressure is available and no stockout probability is claimed.',
            ];
        }

        $observation = new StockoutObservation(
            (int) $station->adc_station_id,
            (string) $station->source_station_key,
            $localCode,
            $now->toDateTimeImmutable(),
            isset($entry['on_hand']) && is_numeric($entry['on_hand']) ? (float) $entry['on_hand'] : null,
            isset($entry['par_level']) && is_numeric($entry['par_level']) ? (float) $entry['par_level'] : null,
            $inventoryCapturedAt,
            $vendPerHour,
            $refillPerHour,
            $minutesSinceRefill,
            $shortage,
            $station->status !== 'operational',
        );
        $extracted = $this->extractor->extract($observation, $now);
        if ($extracted['availability'] === 'unavailable') {
            return [...$base,
                'availability' => 'unavailable',
                'observedState' => 'none',
                'probability' => null,
                'band' => null,
                'factors' => [],
                'missingSignals' => $extracted['missing'],
                'explanation' => $extracted['explanation'],
            ];
        }

        $model = $artifact->stockoutModel();
        $probability = $model->calibratedProbability($extracted['features']);
        $factors = collect($model->weights)->map(fn (float $weight, string $feature): array => [
            'feature' => $feature,
            'effect' => round($weight * $extracted['features'][$feature], 4),
            'direction' => $weight * $extracted['features'][$feature] >= 0 ? 'pressure' : 'protective',
        ])->sortByDesc(fn (array $factor): float => abs($factor['effect']))->take(3)->values()->all();

        return [...$base,
            'availability' => $extracted['availability'],
            'observedState' => 'none',
            'probability' => round($probability, 4),
            'band' => match (true) {
                $probability >= 0.6 => 'elevated',
                $probability >= 0.3 => 'watch',
                default => 'low',
            },
            'factors' => $factors,
            'missingSignals' => $extracted['missing'],
            'explanation' => $extracted['explanation'],
        ];
    }

    /** @return array<int,float> */
    private function hourOfWeekRates(string $column, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('prod.rx_verifications')->whereBetween($column, [$from, $to])
            ->selectRaw("(((extract(isodow from {$column})::int - 1) * 24) + extract(hour from {$column})::int) AS hour_of_week, count(*)::float AS event_count")
            ->groupByRaw("((extract(isodow from {$column})::int - 1) * 24) + extract(hour from {$column})::int")
            ->pluck('event_count', 'hour_of_week')->map(fn (mixed $value): float => (float) $value)->all();
    }

    /** @return array<int,float> */
    private function completionHourOfWeekRates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT (((extract(isodow from completed_at)::int - 1) * 24) + extract(hour from completed_at)::int) AS hour_of_week,
                   count(*)::float AS event_count
            FROM (
                SELECT COALESCE(verified_at, removed_at) AS completed_at
                FROM prod.rx_verifications
                WHERE COALESCE(verified_at, removed_at) BETWEEN ? AND ?
            ) completed
            GROUP BY (((extract(isodow from completed_at)::int - 1) * 24) + extract(hour from completed_at)::int)
        SQL, [$from, $to]);

        return collect($rows)->mapWithKeys(fn (object $row): array => [(int) $row->hour_of_week => (float) $row->event_count])->all();
    }

    /** @return array<string,mixed> */
    private function transactionMetadata(object $transaction): array
    {
        return is_array($transaction->metadata)
            ? $transaction->metadata
            : (json_decode((string) $transaction->metadata, true) ?: []);
    }

    /** Invalid inventory cutoffs are treated as missing evidence, never as a request failure. */
    private function inventoryCapturedAt(?array $entry): ?\DateTimeImmutable
    {
        if (! is_string($entry['captured_at'] ?? null)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($entry['captured_at'])->toDateTimeImmutable();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function unavailableQueue(string $explanation, ?PharmacyForecastModelArtifact $artifact = null): array
    {
        return [
            'kind' => 'forecast', 'target' => 'verification_queue_depth', 'status' => 'unavailable',
            'horizonHours' => self::QUEUE_HORIZON_HOURS, 'currentDepth' => 0, 'history' => null,
            'points' => [], 'missingSignals' => ['queue_history'], 'explanation' => $explanation,
            'provenance' => $artifact?->provenance(),
        ];
    }

    /** @return array<string,mixed> */
    private function unavailableStockout(string $explanation, ?PharmacyForecastModelArtifact $artifact = null): array
    {
        return [
            'kind' => 'forecast', 'target' => 'station_medication_stockout_within_six_hours', 'status' => 'unavailable',
            'horizonHours' => self::STOCKOUT_HORIZON_HOURS, 'lookbackHours' => self::LOOKBACK_HOURS,
            'rows' => [], 'coverage' => ['stationMedicationPairs' => 0, 'probabilityAvailable' => 0, 'velocityOnly' => 0, 'observedStockouts' => 0],
            'explanation' => $explanation, 'provenance' => $artifact?->provenance(),
            'privacy' => ['stationMedicationAggregatesOnly' => true, 'individualStaffFeaturesIncluded' => false, 'controlledDiversionScoreIncluded' => false],
        ];
    }
}
