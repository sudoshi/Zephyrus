<?php

namespace App\Integrations\Healthcare\Services;

use App\Services\Alerting\OperationalAlert;
use App\Services\Alerting\OperationalAlertDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * INT-SECRET — deliver credential-rotation threshold crossings through the
 * shared INT-OBS operational-alert lifecycle instead of a console badge.
 *
 * The rotation threshold states (90/60/30/14/7-day + expired) already exist in
 * CredentialValidationService. This service turns a NEW band crossing into a
 * page through the SAME OperationalAlertDispatcher the SLO-breach path uses:
 *
 *   - PHI-free / secret-free payload: source label, credential id, days-until-
 *     expiry, and the threshold band only. The dispatcher runs every field
 *     through ClinicalContentGuard as defense in depth (the breach path does).
 *   - env-gated / inert by default: the dispatcher's channels are the same
 *     inert-by-default Teams/Push lanes, so nothing pages until a deployment
 *     opts in — and every attempt (including the inert no-op) is recorded.
 *   - fires ONCE per band crossing: an append-only per-band dedupe ledger
 *     mirrors the breach path's flap damping, so a daily sweep does not re-page
 *     a credential that already alerted for its current band.
 */
final class CredentialRotationAlertService
{
    /** Bands that warrant a page, from earliest to most urgent. */
    private const ALERTABLE_BANDS = ['due_90', 'due_60', 'due_30', 'due_14', 'due_7', 'expired'];

    public function __construct(
        private readonly OperationalAlertDispatcher $dispatcher,
    ) {}

    /**
     * Evaluate every active credential's rotation band and page ONCE per new
     * band crossing. Returns bounded counts only — never a reference or secret.
     *
     * @return array{evaluated:int, dispatched:int, deduped:int, inert:int}
     */
    public function sweep(?CarbonImmutable $now = null, ?string $correlationUuid = null, int $limit = 500): array
    {
        $now ??= CarbonImmutable::now();
        $limit = max(1, min(2000, $limit));
        $thresholds = $this->thresholds();

        $credentials = DB::table('integration.source_credentials as credential')
            ->join('integration.sources as source', 'source.source_id', '=', 'credential.source_id')
            ->join('integration.source_credential_versions as version', function ($join): void {
                $join->on('version.source_credential_version_id', '=', 'credential.current_credential_version_id')
                    ->on('version.source_credential_id', '=', 'credential.source_credential_id');
            })
            ->whereIn('credential.credential_state', ['active', 'rotating'])
            ->where('source.lifecycle_state', '<>', 'retired')
            ->orderBy('credential.source_credential_id')
            ->limit($limit)
            ->get([
                'credential.source_credential_id',
                'credential.source_id',
                'credential.credential_key',
                'source.source_key',
                'version.rotates_at',
                'version.expires_at',
            ]);

        $evaluated = 0;
        $dispatched = 0;
        $deduped = 0;
        $inert = 0;
        foreach ($credentials as $credential) {
            $evaluated++;
            $band = $this->band($credential, $now, $thresholds);
            if ($band === null) {
                continue;
            }
            $outcome = $this->dispatchBand($credential, $band, $now, $correlationUuid);
            match ($outcome) {
                'dispatched' => $dispatched++,
                'inert' => $inert++,
                default => $deduped++,
            };
        }

        return ['evaluated' => $evaluated, 'dispatched' => $dispatched, 'deduped' => $deduped, 'inert' => $inert];
    }

    /**
     * The active rotation band for a credential version, or null when it is not
     * yet inside any configured threshold. Mirrors CredentialValidationService.
     *
     * @param  list<int>  $thresholds
     */
    private function band(object $credential, CarbonImmutable $now, array $thresholds): ?string
    {
        $deadline = collect([$credential->rotates_at, $credential->expires_at])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse((string) $value))
            ->sort()
            ->first();
        if (! $deadline instanceof CarbonImmutable) {
            return null;
        }
        if ($deadline->lessThanOrEqualTo($now)) {
            return 'expired';
        }
        $days = (int) $now->diffInDays($deadline, false);
        foreach ($thresholds as $threshold) {
            if ($days <= $threshold) {
                return 'due_'.$threshold;
            }
        }

        return null;
    }

    private function dispatchBand(object $credential, string $band, CarbonImmutable $now, ?string $correlationUuid): string
    {
        if (! in_array($band, self::ALERTABLE_BANDS, true)) {
            return 'deduped';
        }
        // Dedupe: one dispatched alert per credential per band. A re-entry of the
        // same band never pages again until the credential is rotated (which
        // produces a new version and therefore a fresh evaluation).
        $alreadyDispatched = DB::table('integration.credential_rotation_alert_states')
            ->where('source_credential_id', $credential->source_credential_id)
            ->where('rotation_band', $band)
            ->where('dispatch_outcome', 'dispatched')
            ->exists();
        if ($alreadyDispatched) {
            return 'deduped';
        }

        $days = $this->daysUntil($credential, $now);
        $result = $this->dispatcher->dispatch(
            new OperationalAlert(
                severity: $band === 'expired' || $band === 'due_7' ? 'crit' : 'warn',
                domain: 'integration',
                code: 'credential_rotation_due',
                title: $this->title($band),
                sourceLabel: (string) $credential->source_key,
                deepLink: '/integrations?tab=credentials',
                facts: [
                    'credential_id' => (int) $credential->source_credential_id,
                    'source_id' => (int) $credential->source_id,
                    'band' => $band,
                    'days_until_expiry' => $days ?? -1,
                ],
            ),
            'credential_rotation',
            'credential:'.$credential->source_credential_id.':'.$band,
            $correlationUuid,
            $now,
        );
        $outcome = $result['delivered'] > 0 ? 'dispatched' : 'inert';

        DB::table('integration.credential_rotation_alert_states')->insert([
            'state_uuid' => (string) Str::uuid7(),
            'source_id' => $credential->source_id,
            'source_credential_id' => $credential->source_credential_id,
            'rotation_band' => $band,
            'days_until_expiry' => $days,
            'dispatch_outcome' => $outcome,
            'recipient_count' => (int) $result['recipients'],
            'reason_code' => $outcome === 'dispatched' ? 'threshold_band_crossed' : 'no_channel_bound',
            'correlation_uuid' => $correlationUuid !== null && Str::isUuid($correlationUuid) ? $correlationUuid : null,
            'observed_at' => $now,
            'created_at' => $now,
        ]);

        return $outcome;
    }

    private function daysUntil(object $credential, CarbonImmutable $now): ?int
    {
        $deadline = collect([$credential->rotates_at, $credential->expires_at])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse((string) $value))
            ->sort()
            ->first();

        return $deadline instanceof CarbonImmutable ? (int) $now->diffInDays($deadline, false) : null;
    }

    private function title(string $band): string
    {
        return $band === 'expired'
            ? 'Integration credential rotation overdue'
            : 'Integration credential rotation due within '.substr($band, 4).' days';
    }

    /** @return list<int> */
    private function thresholds(): array
    {
        $thresholds = array_map('intval', (array) config('integrations.credential_rotation_threshold_days', [90, 60, 30, 14, 7]));
        sort($thresholds, SORT_NUMERIC);

        return array_values(array_filter($thresholds, fn (int $threshold): bool => $threshold > 0));
    }
}
