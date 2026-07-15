<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Pharmacy\ControlledSubstanceOperationsService;
use App\Services\Pharmacy\PharmacyDischargeReadinessService;
use App\Services\Pharmacy\PharmacyDispenseService;
use App\Services\Pharmacy\PharmacyFlowBoardService;
use App\Services\Pharmacy\PharmacyIvRoomService;
use App\Services\Pharmacy\PharmacyTatAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * X-14 — PHASE-WIDE PHARMACY SAFETY GATE.
 *
 * X-10 (`PharmacyControlledTest`) proves the controlled-substance surface carries
 * no individual dimension. This gate broadens that guarantee across the ENTIRE
 * Pharmacy phase: every browser-facing service payload (Flow Board, Discharge
 * Meds, IV Room, Dispense, Controlled, TAT Study) AND the pharmacy source itself
 * are searched for forbidden individual risk/actor fields.
 *
 * The plan's §13 non-negotiable: "Pharmacy controlled-substance screens prohibit
 * individual scoring in service, API, UI, tests, exports, and documentation."
 * The brief broadens the safety boundary to the whole pharmacy surface: NO
 * individual diversion-risk scoring, staff ranking, or user-level risk output in
 * ANY service, DTO, API, page, test, or export — unit/station aggregates only.
 */
final class PharmacyPhaseSafetyGateTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    /**
     * Forbidden individual-level KEY fragments. A payload KEY containing any of
     * these anywhere under the data surface fails the gate.
     *
     * `name` is deliberately excluded because `unitName`/`stationName`/
     * `medicationLabel` are legitimate LOCATION and drug labels. Bare `rank` is
     * also excluded because `sourceRank` is the milestone SOURCE-PRECEDENCE rank
     * from §6.5 (which feed's assertion won), not a staff ranking — the
     * individual-ranking forms (`riskRank`, `staffRank`, `userRank`, `ranked...`)
     * are forbidden explicitly instead. Person-name and score/rank fields are
     * caught by the precise fragments below and the value-fragment scan.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEY_FRAGMENTS = [
        'user', 'staff', 'person', 'employee', 'badge', 'actor',
        'performed_by', 'performedby', 'verifier', 'pharmacist', 'technician',
        'nurse', 'risk_score', 'riskscore', 'riskrank', 'staffrank', 'userrank',
        'ranked', 'diversion', 'outlier',
    ];

    /**
     * Forbidden VALUE fragments in the serialized data surface. These would only
     * appear if an individual field name were being emitted into the browser.
     *
     * @var list<string>
     */
    private const FORBIDDEN_VALUE_FRAGMENTS = [
        'user_id', 'user_name', 'username', 'staff_id', 'staff_name',
        'employee_id', 'badge_id', 'performed_by', 'verifier_ref', 'verifier_id',
        'diversion_risk', 'diversion_score', 'risk_score', 'risk_rank',
        'ranked_staff', 'outlier_user', 'staff_ranking',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([
            RtdcSeeder::class,
            CaseManagementSeeder::class,
            StaffingReferenceSeeder::class,
            CommandCenterDemoSeeder::class,
            AncillaryReferenceSeeder::class,
        ]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Build every browser-facing pharmacy payload with the MAXIMALLY elevated
     * capabilities (patient detail + barrier annotation), so the gate scans the
     * most exposed data surface the app can ever ship.
     *
     * @return array<string, array<string, mixed>>
     */
    private function elevatedPharmacyPayloads(): array
    {
        return [
            '/pharmacy' => app(PharmacyFlowBoardService::class)->build([], true, true),
            '/pharmacy/discharge-meds' => app(PharmacyDischargeReadinessService::class)->build([], true),
            '/pharmacy/iv-room' => app(PharmacyIvRoomService::class)->build([], true),
            '/pharmacy/dispense' => app(PharmacyDispenseService::class)->build([], true),
            '/pharmacy/controlled' => app(ControlledSubstanceOperationsService::class)->build(),
            '/analytics/pharmacy-tat' => app(PharmacyTatAnalyticsService::class)->build(),
        ];
    }

    /**
     * The OPERATIONAL data surface for a page: everything the browser receives
     * EXCEPT the deliberate governance/disclaimer blocks (`privacy`, `scope`,
     * `policy`, `meta`, `governance`). Those blocks intentionally NAME the
     * forbidden dimensions in prose ("no pharmacist, verifier, or user-level ...")
     * and via boolean flag keys (`userLevelDimensionIncluded`), so they are
     * excluded from the forbidden-field scan — exactly as X-10 scans only `data`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dataSurface(array $payload): array
    {
        // Flat payloads (the TAT Study) carry operational blocks at the top level;
        // enveloped payloads carry them under `data`. In BOTH cases the deliberate
        // governance/disclaimer blocks may sit at either level, so strip them from
        // whichever surface we scan.
        $surface = array_key_exists('data', $payload) && is_array($payload['data'])
            ? $payload['data']
            : $payload;

        foreach (['privacy', 'scope', 'policy', 'meta', 'governance'] as $disclaimerBlock) {
            unset($surface[$disclaimerBlock]);
        }

        return $surface;
    }

    /**
     * The identifier-policy disclaimer string for a page, wherever it lives
     * (top-level `privacy`, or nested `data.privacy`, or the Controlled `scope`).
     */
    private function identifierPolicy(array $payload): string
    {
        $privacy = $payload['privacy']
            ?? ($payload['data']['privacy'] ?? null)
            ?? [];

        return strtolower((string) ($privacy['identifierPolicy'] ?? ''));
    }

    private function individualPerformanceIncluded(array $payload): bool
    {
        $privacy = $payload['privacy']
            ?? ($payload['data']['privacy'] ?? null)
            ?? [];

        return (bool) ($privacy['individualPerformanceIncluded'] ?? true);
    }

    public function test_every_pharmacy_page_payload_is_non_empty_so_the_gate_scans_real_data(): void
    {
        // A forbidden-field scan over empty payloads would pass vacuously. Prove
        // each surface actually rendered populated demo data before scanning it.
        foreach ($this->elevatedPharmacyPayloads() as $route => $payload) {
            $surface = $this->dataSurface($payload);
            $this->assertNotEmpty($surface, "{$route} must expose an operational data surface");
            $flat = (string) json_encode($surface);
            $this->assertGreaterThan(200, strlen($flat), "{$route} rendered an empty data surface");
        }
    }

    public function test_no_pharmacy_page_payload_carries_an_individual_risk_or_actor_field(): void
    {
        foreach ($this->elevatedPharmacyPayloads() as $route => $payload) {
            $surface = $this->dataSurface($payload);
            $dataFlat = strtolower((string) json_encode($surface));
            foreach (self::FORBIDDEN_VALUE_FRAGMENTS as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $dataFlat,
                    "{$route}: forbidden individual-level fragment '{$forbidden}' leaked into the data surface",
                );
            }
            $this->assertNoIndividualKeys($surface, $route);
        }
    }

    public function test_source_side_verifier_reference_never_reaches_any_browser_payload(): void
    {
        // The order projector stores a pseudonymous `verifier_ref` in server-side
        // verification metadata (X-2). It is minimum-necessary source provenance
        // and must NEVER surface to the browser. Scan the FULL payload (not just
        // `data`) of every pharmacy page for it.
        foreach ($this->elevatedPharmacyPayloads() as $route => $payload) {
            $full = strtolower((string) json_encode($payload));
            $this->assertStringNotContainsString('verifier_ref', $full, "{$route}: verifier_ref reached the browser");
            $this->assertStringNotContainsString('verifierref', $full, "{$route}: verifierRef reached the browser");
        }
    }

    public function test_each_pharmacy_page_declares_its_no_individual_dimension_posture(): void
    {
        // Every operational/analytics page carries an explicit privacy block that
        // (a) sets individualPerformanceIncluded = false and (b) disclaims any
        // user-level dimension in its identifierPolicy prose.
        $privacyRoutes = [
            '/pharmacy', '/pharmacy/discharge-meds', '/pharmacy/iv-room',
            '/pharmacy/dispense', '/analytics/pharmacy-tat',
        ];
        $payloads = $this->elevatedPharmacyPayloads();
        foreach ($privacyRoutes as $route) {
            $this->assertFalse(
                $this->individualPerformanceIncluded($payloads[$route]),
                "{$route}: individualPerformanceIncluded must be false",
            );
            $policy = $this->identifierPolicy($payloads[$route]);
            $this->assertNotSame('', $policy, "{$route} must carry an identifierPolicy disclaimer");
            $this->assertTrue(
                str_contains($policy, 'user-level') || str_contains($policy, 'no user'),
                "{$route}: identifierPolicy must disclaim any user/actor-level dimension (got: {$policy})",
            );
        }

        // Controlled declares the strongest, richest posture explicitly (§X-10).
        $controlled = $payloads['/pharmacy/controlled'];
        $this->assertFalse($controlled['scope']['individualScoringInScope']);
        $this->assertFalse($controlled['scope']['userLevelDimensionIncluded']);
        $this->assertFalse($controlled['scope']['individualPerformanceIncluded']);
        $this->assertSame('unit_and_station', $controlled['scope']['aggregationLevel']);
    }

    public function test_pharmacy_service_source_declares_no_individual_scoring_and_defines_no_actor_columns(): void
    {
        // Static guard: the pharmacy service source must not SELECT or emit an
        // individual actor/score/rank column. We scan for column-name-shaped
        // fragments (snake_case selects / array keys), NOT prose disclaimers.
        $serviceDir = app_path('Services/Pharmacy');
        $files = glob($serviceDir.'/*.php') ?: [];
        $this->assertNotEmpty($files, 'Pharmacy service directory must contain source files');

        // Column/array-key shaped tokens that would indicate an individual
        // dimension being computed or emitted. Prose like "no pharmacist ..."
        // is allowed; these snake_case field tokens are not.
        $forbiddenColumnTokens = [
            "'user_id'", "'user_name'", "'staff_id'", "'staff_name'",
            "'performed_by'", "'diversion_score'", "'diversion_risk'",
            "'risk_score'", "'risk_rank'", "'staff_ranking'", "'outlier_user'",
            '->user_id', '->staff_id', '->performed_by',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $base = basename($file);
            foreach ($forbiddenColumnTokens as $token) {
                $this->assertStringNotContainsString(
                    $token,
                    $source,
                    "{$base}: forbidden individual-level column token {$token} present in pharmacy service source",
                );
            }
        }
    }

    /**
     * Recursively walk an array asserting no KEY carries an individual-level
     * fragment.
     */
    private function assertNoIndividualKeys(mixed $value, string $path): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $lower = strtolower($key);
                foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
                    $this->assertStringNotContainsString(
                        $fragment,
                        $lower,
                        "Forbidden individual-level key '{$key}' at {$path}",
                    );
                }
            }
            $this->assertNoIndividualKeys($nested, $path.'.'.$key);
        }
    }
}
