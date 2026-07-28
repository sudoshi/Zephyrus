<?php

declare(strict_types=1);

namespace Tests\Unit\CarePathways;

use App\Services\CarePathways\ReferenceModelCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Pure-transform contract for the governed-catalog → conformance reference-model
 * compiler (FLOW-4D plan Phase D1). No framework boot: the declarative compile()
 * takes catalog metadata + milestone rows and is deterministic.
 */
final class ReferenceModelCompilerTest extends TestCase
{
    /**
     * A stable three-milestone fixture spanning the two expected_range shapes the
     * catalog uses: numeric day offsets and a human display string.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function fixture(): array
    {
        $meta = [
            'pathway_key' => 'acute-example-a',
            'label' => 'Acute Example A',
            'semantic_version' => '43.1-source.1',
            'service_line_code' => 'NEURO',
            'care_type' => 'Medical',
        ];

        // Deliberately out of order so ordering is exercised.
        $milestones = [
            ['stable_key' => 'day_2_m01', 'title' => 'Repeat imaging reviewed', 'phase' => 'day_2', 'sequence' => 2, 'expected_range' => ['day_offset_min' => 1, 'day_offset_max' => 2]],
            ['stable_key' => 'day_1_m01', 'title' => 'Initial workup complete', 'phase' => 'day_1', 'sequence' => 1, 'expected_range' => ['day_offset_min' => 0, 'day_offset_max' => 1]],
            ['stable_key' => 'arrival_m00', 'title' => 'Admitted and settled', 'phase' => 'arrival', 'sequence' => 0, 'expected_range' => ['display' => 'Today']],
        ];

        return [$meta, $milestones];
    }

    public function test_compiles_the_sidecar_spec_shape(): void
    {
        [$meta, $milestones] = $this->fixture();
        $model = (new ReferenceModelCompiler)->compile($meta, $milestones);

        $this->assertNotNull($model);
        $this->assertSame('acute-example-a', $model['pathway_key']);
        $this->assertSame('Acute Example A', $model['label']);
        $this->assertSame(43, $model['version']); // major int from '43.1-source.1'
        $this->assertSame('43.1-source.1', $model['semantic_version']);
        $this->assertSame('clinical:neuro', $model['owner']);
        $this->assertSame('Encounter', $model['case_type']);
    }

    public function test_orders_activities_by_sequence_then_stable_key(): void
    {
        [$meta, $milestones] = $this->fixture();
        $model = (new ReferenceModelCompiler)->compile($meta, $milestones);

        $this->assertSame(['arrival_m00', 'day_1_m01', 'day_2_m01'], $model['activities']);
        // trigger is the first ordered activity.
        $this->assertSame('arrival_m00', $model['trigger']);
    }

    public function test_extracts_timing_targets_from_both_expected_range_shapes(): void
    {
        [$meta, $milestones] = $this->fixture();
        $model = (new ReferenceModelCompiler)->compile($meta, $milestones);

        $byActivity = array_column($model['timing_targets'], null, 'activity');

        $this->assertSame(['activity' => 'arrival_m00', 'day_offset_min' => null, 'day_offset_max' => null, 'display' => 'Today'], $byActivity['arrival_m00']);
        $this->assertSame(['activity' => 'day_1_m01', 'day_offset_min' => 0, 'day_offset_max' => 1, 'display' => null], $byActivity['day_1_m01']);
        $this->assertSame(['activity' => 'day_2_m01', 'day_offset_min' => 1, 'day_offset_max' => 2, 'display' => null], $byActivity['day_2_m01']);
    }

    public function test_derives_a_data_driven_deviation_vocabulary(): void
    {
        [$meta, $milestones] = $this->fixture();
        $model = (new ReferenceModelCompiler)->compile($meta, $milestones);

        // A "missing" code for every milestone.
        $this->assertSame('Initial workup complete not yet observed', $model['deviation_labels']['day_1_m01_missing']);
        // A "late" code only where a numeric day ceiling exists.
        $this->assertSame('Initial workup complete beyond day 1', $model['deviation_labels']['day_1_m01_late']);
        $this->assertArrayHasKey('arrival_m00_missing', $model['deviation_labels']);
        $this->assertArrayNotHasKey('arrival_m00_late', $model['deviation_labels']); // display-only, no ceiling
    }

    public function test_never_emits_an_evaluate_callable(): void
    {
        [$meta, $milestones] = $this->fixture();
        $model = (new ReferenceModelCompiler)->compile($meta, $milestones);

        // The imperative evaluator is the open gap (D2/D3), deliberately absent.
        $this->assertArrayNotHasKey('evaluate', $model);
    }

    public function test_digest_is_deterministic_and_content_addressed(): void
    {
        [$meta, $milestones] = $this->fixture();
        $compiler = new ReferenceModelCompiler;

        $a = $compiler->compile($meta, $milestones);
        // Re-shuffle input order: the digest must be identical (content, not order).
        $b = $compiler->compile($meta, array_reverse($milestones));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a['digest']);
        $this->assertSame($a['digest'], $b['digest']);

        // Changing content (a timing ceiling) must change the digest.
        $mutated = $milestones;
        $mutated[1]['expected_range'] = ['day_offset_min' => 0, 'day_offset_max' => 5];
        $c = $compiler->compile($meta, $mutated);
        $this->assertNotSame($a['digest'], $c['digest']);
    }

    public function test_digest_is_pinned_for_the_fixture(): void
    {
        [$meta, $milestones] = $this->fixture();
        $model = (new ReferenceModelCompiler)->compile($meta, $milestones);

        // Content-address pin: a change to the compiler's declarative body shape
        // is a governance-relevant event and must be an explicit, reviewed edit.
        $this->assertSame(
            'ea3e0ff9b1d0921b6f08c92f8017e5a9bc490cf7f191601c004d0719d9b618ee',
            $model['digest'],
        );
    }

    public function test_returns_null_when_the_version_has_no_executable_layer(): void
    {
        [$meta] = $this->fixture();

        // No milestones → nothing to compile. The honest state for every version
        // in the current inactive catalog release, not an error.
        $this->assertNull((new ReferenceModelCompiler)->compile($meta, []));
    }

    public function test_owner_falls_back_when_no_service_line(): void
    {
        $model = (new ReferenceModelCompiler)->compile(
            ['pathway_key' => 'x', 'label' => 'X', 'semantic_version' => '1.0.0'],
            [['stable_key' => 'm1', 'title' => 'M1', 'sequence' => 1, 'expected_range' => []]],
        );

        $this->assertSame('clinical:unassigned', $model['owner']);
        $this->assertSame(1, $model['version']);
        // A milestone with an empty range still appears as an activity, with a
        // missing-code but no timing target.
        $this->assertSame(['m1'], $model['activities']);
        $this->assertSame([], $model['timing_targets']);
        $this->assertArrayHasKey('m1_missing', $model['deviation_labels']);
    }

    public function test_duplicate_stable_keys_are_deduplicated_first_occurrence_wins(): void
    {
        // compile() is public; the DB unique constraint only protects
        // compileVersion(). A duplicate key must not double an activity.
        $model = (new ReferenceModelCompiler)->compile(
            ['pathway_key' => 'x', 'label' => 'X', 'semantic_version' => '1.0.0'],
            [
                ['stable_key' => 'm1', 'title' => 'First', 'sequence' => 1, 'expected_range' => ['day_offset_max' => 1]],
                ['stable_key' => 'm1', 'title' => 'Duplicate', 'sequence' => 1, 'expected_range' => ['day_offset_max' => 9]],
                ['stable_key' => 'm2', 'title' => 'Second', 'sequence' => 2, 'expected_range' => []],
            ],
        );

        $this->assertSame(['m1', 'm2'], $model['activities']);
        // First occurrence wins — the '_late' ceiling is day 1, not 9.
        $this->assertSame('First beyond day 1', $model['deviation_labels']['m1_late']);
    }
}
