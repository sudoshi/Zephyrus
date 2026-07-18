<?php

namespace App\Services\Cockpit;

use App\Models\Cockpit\CockpitAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Zephyrus 2.0 P6 — the flap-damped alert lifecycle (plan §P6 workstream 1).
 *
 * Alerts are a DERIVATION, never hand-set: SnapshotBuilder::deriveAlerts()
 * produces the per-snapshot CANDIDATE set (warn/crit MetricValues whose
 * kpi_definition carries an alert_template — the Earned-Red ration), and this
 * engine reconciles those candidates against prod.cockpit_alerts so the
 * ticker never strobes:
 *
 *   no row      --candidate--> PENDING (hold_count counts up)   [not shown]
 *   PENDING     --K holds----> OPEN    (opened_at stamped)      [in ticker]
 *   PENDING     --absent-----> deleted (a sub-K flap never fired → no history)
 *   OPEN        --candidate--> held    (hold_count reset; text/severity track)
 *   OPEN        --K absent---> CLEARED (cleared_at stamped → history row)
 *   CLEARED     --candidate--> new PENDING row (history is append-only)
 *
 * Severity moves (warn↔crit) update the open row in place — the entry never
 * closes/reopens, so escalation is immediate without any open/clear flapping.
 * An oscillating metric (alternating alert/normal every snapshot) NEVER opens.
 */
class AlertEngine
{
    /** Pre-open accumulator state — cleared_at NULL but absent from the ticker. */
    public const STATUS_PENDING = 'pending';

    /** Cache::add guard so burst rebuilds (job + serve-path) can't double-count holds. */
    public const RECONCILE_LOCK_KEY = 'cockpit.alerts.reconcile_lock';

    public function __construct(private readonly AlertFanout $fanout) {}

    /**
     * Reconcile this snapshot's candidates and return the damped OPEN set for
     * the payload ticker: crit-first, newest-open first.
     *
     * @param  list<array{key: string, status: string, text: string, provenance?: string}>  $candidates
     * @return list<array<string, mixed>>
     */
    public function reconcile(string $facilityKey, array $candidates): array
    {
        $byKey = [];

        foreach ($candidates as $candidate) {
            $byKey[$candidate['key']] = $candidate;
        }

        if ($this->shouldCountHolds()) {
            try {
                $this->advance($facilityKey, $byKey);
            } catch (\Throwable $e) {
                // Alert bookkeeping trouble must never blank the snapshot.
                Log::warning('cockpit.alerts.reconcile_failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->openAlerts($facilityKey, $byKey);
    }

    /** @param array<string, array<string, mixed>> $byKey */
    private function advance(string $facilityKey, array $byKey): void
    {
        $openHolds = max(1, (int) config('cockpit.alerts.open_holds'));
        $clearHolds = max(1, (int) config('cockpit.alerts.clear_holds'));
        $ttlHours = max(0, (int) config('cockpit.alerts.ttl_hours'));

        $rows = CockpitAlert::query()
            ->where('facility_key', $facilityKey)
            ->whereNull('cleared_at')
            ->get()
            ->keyBy('key');

        foreach ($rows as $key => $row) {
            $candidate = $byKey[$key] ?? null;

            if ($row->status === self::STATUS_PENDING) {
                if ($candidate === null) {
                    // A flap that never reached K holds never fired — leave no history.
                    $row->delete();
                } elseif ($row->hold_count + 1 >= $openHolds) {
                    $row->update([
                        'status' => $candidate['status'],
                        'text' => $candidate['text'],
                        'opened_at' => now(),
                        'hold_count' => 0,
                    ]);
                    // Fan-out fires on the open TRANSITION only — held and
                    // cleared snapshots never page (WS-3 Earned-Urgency).
                    $this->fanout->alertOpened($row);
                } else {
                    $row->update(['hold_count' => $row->hold_count + 1, 'text' => $candidate['text']]);
                }

                continue;
            }

            // OPEN row.
            if ($candidate !== null) {
                // TTL re-raise (HFE audit TIME-01): a condition open longer than
                // the TTL closes to history and re-derives as a FRESH alert on
                // the next snapshots — still visible if still real, but the
                // ticker never carries a weeks-old "active" clock. Deliberately
                // not a silent suppression: hiding a live breach would lie.
                if ($ttlHours > 0 && $row->opened_at !== null && $row->opened_at->lte(now()->subHours($ttlHours))) {
                    $row->update(['cleared_at' => now(), 'hold_count' => 0]);

                    continue;
                }

                // Held: severity and text track the live value; the entry never
                // closes/reopens on a warn↔crit move.
                $row->update([
                    'status' => $candidate['status'],
                    'text' => $candidate['text'],
                    'hold_count' => 0,
                ]);
            } elseif ($row->hold_count + 1 >= $clearHolds) {
                $row->update(['cleared_at' => now(), 'hold_count' => 0]);
            } else {
                $row->update(['hold_count' => $row->hold_count + 1]);
            }
        }

        // Brand-new candidates (no un-cleared row) enter the pending accumulator.
        foreach ($byKey as $key => $candidate) {
            if ($rows->has($key)) {
                continue;
            }

            if ($openHolds <= 1) {
                $this->fanout->alertOpened(CockpitAlert::create([
                    'facility_key' => $facilityKey,
                    'key' => $key,
                    'status' => $candidate['status'],
                    'text' => $candidate['text'],
                    'opened_at' => now(),
                    'hold_count' => 0,
                ]));

                continue;
            }

            CockpitAlert::create([
                'facility_key' => $facilityKey,
                'key' => $key,
                'status' => self::STATUS_PENDING,
                'text' => $candidate['text'],
                // opened_at is NOT NULL by schema; it is re-stamped at the
                // moment the alert actually opens.
                'opened_at' => now(),
                'hold_count' => 1,
            ]);
        }
    }

    /**
     * The damped open set for the payload. provenance rides through from the
     * live candidate when one exists this snapshot (the table deliberately has
     * no provenance column — demo-ness is a property of the value, not the
     * lifecycle).
     *
     * @param  array<string, array<string, mixed>>  $byKey
     * @return list<array<string, mixed>>
     */
    private function openAlerts(string $facilityKey, array $byKey): array
    {
        return CockpitAlert::query()
            ->where('facility_key', $facilityKey)
            ->whereNull('cleared_at')
            ->where('status', '!=', self::STATUS_PENDING)
            ->orderByRaw("CASE WHEN status = 'crit' THEN 0 ELSE 1 END")
            ->orderByDesc('opened_at')
            ->get()
            ->map(function (CockpitAlert $row) use ($byKey): array {
                $alert = [
                    'key' => $row->key,
                    'status' => $row->status,
                    'text' => $row->text,
                    'openedAt' => $row->opened_at?->toIso8601String(),
                ];

                if (($byKey[$row->key]['provenance'] ?? null) === 'demo') {
                    $alert['provenance'] = 'demo';
                }

                return $alert;
            })
            ->values()
            ->all();
    }

    /**
     * Hold counting assumes the ~1/min snapshot cadence. The serve path can
     * rebuild inline seconds after the scheduled job (or a burst of stale
     * requests can rebuild repeatedly) — counting each of those as a
     * "consecutive snapshot" would collapse the damping window, so extra
     * reconciles inside the interval serve reads without advancing state.
     */
    private function shouldCountHolds(): bool
    {
        $interval = (int) config('cockpit.alerts.min_reconcile_interval');

        if ($interval <= 0) {
            return true;
        }

        return Cache::add(self::RECONCILE_LOCK_KEY, 1, $interval);
    }
}
