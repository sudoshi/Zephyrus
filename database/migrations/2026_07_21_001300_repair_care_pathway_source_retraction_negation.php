<?php

/**
 * Repair source statuses produced by the original importer when an explicit
 * negative phrase such as "No PubMed retraction indicator detected" was
 * misread as a positive retraction. Immutable source rows remain untouched;
 * each correction is an append-only status fact with a deterministic digest.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const RECORDED_BY = 'care-pathway-importer-negation-repair-v1';

    public function up(): void
    {
        $recordedAt = now();

        DB::table('care_pathways.sources')
            ->select(['source_id', 'source_uuid', 'retraction_indicator', 'supersession_state'])
            ->orderBy('source_id')
            ->chunkById(250, function ($sources) use ($recordedAt): void {
                $events = [];

                foreach ($sources as $source) {
                    $correctedState = $this->retractionState($source->retraction_indicator);
                    if ($correctedState === $source->supersession_state) {
                        continue;
                    }

                    $eventDigest = hash('sha256', implode('|', [
                        self::RECORDED_BY,
                        (string) $source->source_uuid,
                        $correctedState,
                        trim((string) $source->retraction_indicator),
                    ]));

                    $events[] = [
                        'status_event_uuid' => (string) Str::uuid(),
                        'source_id' => $source->source_id,
                        'supersession_state' => $correctedState,
                        'reason' => 'Corrected the original importer negation-classification defect without mutating the imported source record.',
                        'observed_at' => $recordedAt,
                        'effective_at' => $recordedAt,
                        'recorded_by_ref' => self::RECORDED_BY,
                        'metadata' => json_encode([
                            'repair_version' => 1,
                            'imported_state' => $source->supersession_state,
                            'source_indicator' => $source->retraction_indicator,
                        ], JSON_THROW_ON_ERROR),
                        'event_digest' => $eventDigest,
                        'created_at' => $recordedAt,
                    ];
                }

                if ($events !== []) {
                    DB::table('care_pathways.source_status_events')->insertOrIgnore($events);
                }
            }, 'source_id');

        $this->recordReleaseControls($recordedAt);
    }

    public function down(): void
    {
        // Forward-only data repair. Append-only audit facts must not be removed.
    }

    private function recordReleaseControls(mixed $recordedAt): void
    {
        $releases = DB::table('care_pathways.catalog_releases')
            ->select('catalog_release_id')
            ->orderBy('catalog_release_id')
            ->get();

        foreach ($releases as $release) {
            $correctedSources = DB::table('care_pathways.source_status_events as status_events')
                ->join('care_pathways.claim_sources as claim_sources', 'claim_sources.source_id', '=', 'status_events.source_id')
                ->join('care_pathways.evidence_claims as claims', 'claims.evidence_claim_id', '=', 'claim_sources.evidence_claim_id')
                ->where('claims.catalog_release_id', $release->catalog_release_id)
                ->where('status_events.recorded_by_ref', self::RECORDED_BY)
                ->distinct('status_events.source_id')
                ->count('status_events.source_id');

            if ($correctedSources === 0) {
                continue;
            }

            DB::table('care_pathways.catalog_release_controls')->insertOrIgnore([
                'control_uuid' => (string) Str::uuid(),
                'catalog_release_id' => $release->catalog_release_id,
                'control_key' => 'source_retraction_negation_repair',
                'observed_value' => json_encode(['corrected_source_count' => $correctedSources], JSON_THROW_ON_ERROR),
                'reference_value' => json_encode(['residual_noncurrent_source_count' => 0], JSON_THROW_ON_ERROR),
                'status' => 'passed',
                'rationale' => 'Explicit negative retraction-indicator phrases were restored to current status through append-only corrective events.',
                'evidence' => json_encode(['repair_actor' => self::RECORDED_BY], JSON_THROW_ON_ERROR),
                'recorded_at' => $recordedAt,
            ]);
        }
    }

    private function retractionState(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return 'unknown';
        }

        if (in_array($value, ['no', 'none', 'false', '0'], true)) {
            return 'current';
        }

        if (preg_match('/\b(?:no|not|without)\b(?:[\W_]+\w+){0,8}[\W_]+\bretract(?:ed|ion)?\b/u', $value) === 1) {
            return 'current';
        }

        if (preg_match('/\bretract(?:ed|ion)?\b.{0,40}\b(?:no|none|negative|absent)\b/u', $value) === 1) {
            return 'current';
        }

        if (str_contains($value, 'supersed')) {
            return 'superseded';
        }

        if (str_contains($value, 'retract') || in_array($value, ['yes', 'true', '1'], true)) {
            return 'retracted';
        }

        return 'unknown';
    }
};
