<?php

namespace App\Services\CarePathways;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwaySection;
use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\CarePathways\PathwayVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Derives the DRAFT executable layer — stages and milestones — from the
 * already-imported care-pathway source sections.
 *
 * The catalog ships a rich content layer (28 source sections per pathway) but
 * no executable structure, so a pathway version cannot be instantiated for an
 * encounter and nothing can ever reach a patient projection. This service fills
 * exactly that gap and nothing more.
 *
 * What it deliberately does NOT do:
 *  - it never sets institutional_approval_status or activation_status;
 *  - it never writes review_state anything but 'draft';
 *  - it never puts unreviewed clinical prose in an `approved_*` column —
 *    approved_label carries only a structural label ("Day 1"), and
 *    approved_explanation is left null for a reviewer to author;
 *  - it never touches an already-reviewed definition.
 *
 * The clinical text it lifts is `source_candidate` material. Turning these
 * drafts into approved, activated content is a clinician's act, recorded in
 * care_pathways.reviews and care_pathways.approvals — not this service's job.
 */
class CarePathwayExecutableDraftService
{
    /**
     * Stage plan. Each entry maps a structural stage to the source section that
     * supplies its milestone candidates.
     *
     * @var list<array{key: string, label: string, section: string, range: array<string, int|null>}>
     */
    public const STAGE_PLAN = [
        ['key' => 'admission', 'label' => 'Admission', 'section' => 'admission_criteria', 'range' => ['day_offset_min' => 0, 'day_offset_max' => 0]],
        ['key' => 'day_1', 'label' => 'Day 1', 'section' => 'day1_milestones', 'range' => ['day_offset_min' => 1, 'day_offset_max' => 1]],
        ['key' => 'day_2', 'label' => 'Day 2', 'section' => 'day2_milestones', 'range' => ['day_offset_min' => 2, 'day_offset_max' => 2]],
        ['key' => 'day_3_plus', 'label' => 'Day 3 and beyond', 'section' => 'day3plus_milestones', 'range' => ['day_offset_min' => 3, 'day_offset_max' => null]],
        ['key' => 'discharge', 'label' => 'Discharge', 'section' => 'discharge_criteria', 'range' => ['day_offset_min' => null, 'day_offset_max' => null]],
    ];

    /** Milestone titles longer than this are truncated on a word boundary. */
    private const MAX_TITLE = 400;

    /** Clauses shorter than this are never sentence-split. */
    private const SENTENCE_SPLIT_MIN = 120;

    /** Clauses shorter than this are never comma-split. */
    private const COMMA_SPLIT_MIN = 250;

    /** A comma segment shorter than this is merged back into its predecessor. */
    private const MIN_SEGMENT = 15;

    /**
     * Report what draft(): would create for a version, without writing.
     *
     * @return array{version_id: int, stages: list<array{key: string, label: string, milestones: list<string>}>, skipped: list<string>}
     */
    public function preview(PathwayVersion $version): array
    {
        $sections = $this->sectionsFor($version);
        $stages = [];
        $skipped = [];

        foreach (self::STAGE_PLAN as $plan) {
            $text = $sections[$plan['section']] ?? null;
            if ($text === null || trim($text) === '') {
                $skipped[] = $plan['key'].' (no '.$plan['section'].' section)';

                continue;
            }
            $stages[] = [
                'key' => $plan['key'],
                'label' => $plan['label'],
                'milestones' => $this->milestoneTitles($text),
            ];
        }

        return ['version_id' => (int) $version->getKey(), 'stages' => $stages, 'skipped' => $skipped];
    }

    /**
     * Idempotently create the draft stages and milestones for one version.
     *
     * @return array{version_id: int, stages_created: int, milestones_created: int, replayed: bool}
     */
    public function draft(PathwayVersion $version): array
    {
        $this->assertVersionDraftable($version);

        return DB::transaction(function () use ($version): array {
            $locked = PathwayVersion::query()->lockForUpdate()->find($version->getKey());
            if (! $locked instanceof PathwayVersion) {
                throw new RuntimeException('care_pathway_version_missing');
            }
            $this->assertVersionDraftable($locked);

            $existingStages = PathwayStageDefinition::query()
                ->where('pathway_version_id', $locked->getKey())
                ->lockForUpdate()
                ->get();
            if ($existingStages->isNotEmpty()) {
                // Never rewrite anything a reviewer has already touched.
                $nonDraft = $existingStages->first(fn ($s) => $s->review_state !== 'draft');
                if ($nonDraft !== null) {
                    throw new RuntimeException('care_pathway_stage_definitions_already_reviewed');
                }

                return [
                    'version_id' => (int) $locked->getKey(),
                    'stages_created' => 0,
                    'milestones_created' => 0,
                    'replayed' => true,
                ];
            }

            $sections = $this->sectionsFor($locked);
            $stagesCreated = 0;
            $milestonesCreated = 0;
            $displayOrder = 0;

            foreach (self::STAGE_PLAN as $plan) {
                $text = $sections[$plan['section']] ?? null;
                if ($text === null || trim($text) === '') {
                    continue;
                }
                $displayOrder++;

                PathwayStageDefinition::query()->create([
                    'stage_uuid' => (string) Str::uuid7(),
                    'pathway_version_id' => $locked->getKey(),
                    'stable_key' => $plan['key'],
                    'display_order' => $displayOrder,
                    // Structural only. No unreviewed clinical claim lands in an
                    // approved_* column; approved_explanation stays null.
                    'approved_label' => $plan['label'],
                    'approved_explanation' => null,
                    'expected_range' => $this->rangeJson($plan['range']),
                    'review_state' => 'draft',
                    'content_digest' => hash('sha256', $plan['key'].'|'.$text),
                ]);
                $stagesCreated++;

                $sequence = 0;
                foreach ($this->milestoneTitles($text) as $title) {
                    $sequence++;
                    MilestoneDefinition::query()->create([
                        'milestone_uuid' => (string) Str::uuid7(),
                        'pathway_version_id' => $locked->getKey(),
                        'stable_key' => sprintf('%s_m%02d', $plan['key'], $sequence),
                        'title' => $title,
                        'phase' => $plan['key'],
                        'sequence' => $sequence,
                        'predecessor_keys' => [],
                        'expected_range' => $this->rangeJson($plan['range']),
                        'review_state' => 'draft',
                    ]);
                    $milestonesCreated++;
                }
            }

            return [
                'version_id' => (int) $locked->getKey(),
                'stages_created' => $stagesCreated,
                'milestones_created' => $milestonesCreated,
                'replayed' => false,
            ];
        }, 3);
    }

    /**
     * Both range columns are constrained to `jsonb_typeof(...) = 'object'`.
     * An empty PHP array encodes to `[]`, which is a JSON *array* and fails
     * that check, so an empty range must become `{}` explicitly.
     *
     * @param  array<string, int|null>  $range
     */
    private function rangeJson(array $range): array|object
    {
        $filtered = array_filter($range, static fn (?int $v): bool => $v !== null);

        return $filtered === [] ? (object) [] : $filtered;
    }

    /**
     * A version that a reviewer has already approved or activated is off
     * limits: its executable layer is part of what was approved.
     */
    private function assertVersionDraftable(PathwayVersion $version): void
    {
        if ($version->activation_status === 'active'
            || $version->institutional_approval_status === 'approved') {
            throw new RuntimeException('care_pathway_version_already_approved_or_active');
        }
    }

    /** @return array<string, string> section_code => source_text */
    private function sectionsFor(PathwayVersion $version): array
    {
        return PathwaySection::query()
            ->where('pathway_version_id', $version->getKey())
            ->whereIn('section_code', array_column(self::STAGE_PLAN, 'section'))
            ->pluck('source_text', 'section_code')
            ->all();
    }

    /**
     * Split one source section into milestone candidate titles.
     *
     * The imported prose is semicolon-delimited clauses, frequently prefixed by
     * a short day label ("DOL0 (birth day): ..."). Semicolons inside brackets
     * are not separators.
     *
     * @return list<string>
     */
    public function milestoneTitles(string $text): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        // Drop a short leading label such as "DOL1:" or "Day 2 (post-op):".
        if (preg_match('/^(?<label>[^:]{1,40}):\s*(?<rest>.+)$/u', $text, $m) === 1) {
            $text = $m['rest'];
        }

        $clauses = [];
        $buffer = '';
        $depth = 0;
        foreach (mb_str_split($text) as $char) {
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }
            if ($char === ';' && $depth === 0) {
                $clauses[] = $buffer;
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        $clauses[] = $buffer;

        // Second pass: some pathways delimit with sentences rather than
        // semicolons, which would otherwise collapse a whole stage into one
        // mega-milestone. Only split clauses long enough to plausibly hold two.
        $split = [];
        foreach ($clauses as $clause) {
            if (mb_strlen(trim($clause)) > self::SENTENCE_SPLIT_MIN) {
                array_push($split, ...$this->sentences($clause));
            } else {
                $split[] = $clause;
            }
        }

        // Third pass: a minority of pathways use comma-delimited action lists.
        // Only very long survivors are touched, so short enumerations
        // ("stable vitals, temp, color") are never fractured.
        $final = [];
        foreach ($split as $clause) {
            if (mb_strlen(trim($clause)) > self::COMMA_SPLIT_MIN) {
                array_push($final, ...$this->commaClauses($clause));
            } else {
                $final[] = $clause;
            }
        }

        $titles = [];
        foreach ($final as $clause) {
            $clause = trim($clause, " \t\n\r\0\x0B.;");
            if ($clause === '' || mb_strlen($clause) < 3) {
                continue;
            }
            $titles[] = $this->tidy($clause);
        }

        return array_values(array_unique($titles));
    }

    /**
     * Sentence split that will not fracture clinical abbreviations, decimals,
     * or parenthetical asides.
     *
     * @return list<string>
     */
    private function sentences(string $clause): array
    {
        // Shield abbreviation and decimal periods behind a sentinel, split on
        // what remains, then restore. Cheaper to reason about than a lookbehind
        // stack, and it cannot silently drop characters.
        $sentinel = "\x01";
        $shielded = preg_replace_callback(
            '/\b(e\.g|i\.e|vs|approx|Dr|Mr|Ms|St|etc|cf|al|Fig|No|Inc|q\.d|b\.i\.d|t\.i\.d|q\.i\.d|p\.r\.n|p\.o|I\.V)\./iu',
            static fn (array $m): string => str_replace('.', $sentinel, $m[0]),
            $clause,
        ) ?? $clause;
        // Decimals: 7.5, 36.5C
        $shielded = preg_replace('/(?<=\d)\.(?=\d)/u', $sentinel, $shielded) ?? $shielded;

        // A boundary is ". " followed by a capital, outside brackets.
        $parts = preg_split('/(?<=[a-z0-9\)\]])\.\s+(?=[A-Z])/u', $shielded) ?: [$shielded];

        return array_values(array_map(
            static fn (string $p): string => str_replace($sentinel, '.', $p),
            $parts,
        ));
    }

    /**
     * Split a long comma-delimited action list. Fragments shorter than
     * MIN_SEGMENT are merged back into the preceding item rather than emitted,
     * so "ambulating at baseline, afebrile" stays one milestone instead of
     * becoming a stray "afebrile".
     *
     * @return list<string>
     */
    private function commaClauses(string $clause): array
    {
        $segments = [];
        $buffer = '';
        $depth = 0;
        foreach (mb_str_split($clause) as $char) {
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }
            if ($char === ',' && $depth === 0) {
                $segments[] = $buffer;
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        $segments[] = $buffer;

        $merged = [];
        foreach ($segments as $segment) {
            $trimmed = trim($segment);
            if ($trimmed === '') {
                continue;
            }
            if ($merged !== [] && mb_strlen($trimmed) < self::MIN_SEGMENT) {
                $merged[count($merged) - 1] .= ', '.$trimmed;

                continue;
            }
            $merged[] = $trimmed;
        }

        // A single survivor means the split found nothing useful.
        return count($merged) > 1 ? $merged : [$clause];
    }

    private function tidy(string $clause): string
    {
        // Splitting on a list separator can leave a dangling conjunction
        // ("and a safe disposition confirmed").
        $clause = preg_replace('/^(?:and|or|then|plus)\s+/iu', '', $clause) ?? $clause;

        if (mb_strlen($clause) > self::MAX_TITLE) {
            $cut = mb_substr($clause, 0, self::MAX_TITLE);
            $space = mb_strrpos($cut, ' ');
            $clause = rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ' ,').'…';
        }

        // Sentence-case the first character only; the clinical text keeps its
        // own casing (abbreviations, drug names) untouched.
        return mb_strtoupper(mb_substr($clause, 0, 1)).mb_substr($clause, 1);
    }
}
