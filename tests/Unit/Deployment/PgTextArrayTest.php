<?php

namespace Tests\Unit\Deployment;

use App\Casts\PgTextArray;
use Tests\TestCase;

/**
 * Unit coverage for the Postgres text[] cast helpers (no database).
 */
class PgTextArrayTest extends TestCase
{
    public function test_literal_formats_php_lists_as_postgres_arrays(): void
    {
        $this->assertSame('{}', PgTextArray::literal([]));
        $this->assertSame('{a}', PgTextArray::literal(['a']));
        $this->assertSame('{trauma_surgery,medicine}', PgTextArray::literal(['trauma_surgery', 'medicine']));
    }

    public function test_literal_quotes_elements_needing_escaping(): void
    {
        $this->assertSame('{"a b"}', PgTextArray::literal(['a b']));
        $this->assertSame('{"c,d"}', PgTextArray::literal(['c,d']));
        $this->assertSame('{"he\\"llo"}', PgTextArray::literal(['he"llo']));
    }

    public function test_parse_reads_postgres_array_literals(): void
    {
        $this->assertSame([], PgTextArray::parse(null));
        $this->assertSame([], PgTextArray::parse(''));
        $this->assertSame([], PgTextArray::parse('{}'));
        $this->assertSame(['a'], PgTextArray::parse('{a}'));
        $this->assertSame(['trauma_surgery', 'medicine'], PgTextArray::parse('{trauma_surgery,medicine}'));
    }

    public function test_parse_handles_quoted_and_escaped_elements(): void
    {
        $this->assertSame(['a b', 'c,d', 'he"llo'], PgTextArray::parse('{"a b","c,d","he\\"llo"}'));
    }

    public function test_literal_and_parse_round_trip(): void
    {
        $cases = [
            [],
            ['emergency'],
            ['arrival', 'triage', 'critical_care'],
            ['a b', 'c,d', 'he"llo', 'back\\slash'],
        ];

        foreach ($cases as $case) {
            $this->assertSame(
                array_values($case),
                PgTextArray::parse(PgTextArray::literal($case)),
                'round-trip failed for '.json_encode($case)
            );
        }
    }
}
