<?php

namespace Tests\Feature\Cockpit;

use App\Contracts\AlertChannel;
use App\Contracts\PushNotifier;
use App\Models\Cockpit\CockpitAlert;
use App\Models\User;
use App\Services\Cockpit\AlertEngine;
use App\Services\Cockpit\AlertFanout;
use App\Services\Cockpit\Channels\PushAlertChannel;
use App\Services\Cockpit\Channels\TeamsAlertChannel;
use App\Services\Eddy\EddyApprovalNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpyAlertChannel implements AlertChannel
{
    /** @var list<CockpitAlert> */
    public array $sent = [];

    public function send(CockpitAlert $alert): int
    {
        $this->sent[] = $alert;

        return 1;
    }
}

class SpyPushNotifier implements PushNotifier
{
    /** @var list<array{user: User, title: string, data: array<string, mixed>}> */
    public array $pushes = [];

    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $this->pushes[] = ['user' => $user, 'title' => $title, 'data' => $data];

        return 1;
    }
}

/**
 * P6 workstreams 3+6 — fan-out fires on the open TRANSITION only, pages
 * crit (+ opted-in warn) only, stays inert without EDDY_PUSH_ENABLED, and
 * the risk→cockpit-status mapping is the single severity vocabulary.
 */
class AlertFanoutTest extends TestCase
{
    use RefreshDatabase;

    private SpyAlertChannel $spy;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'cockpit.alerts.open_holds' => 2,
            'cockpit.alerts.clear_holds' => 2,
            'cockpit.alerts.min_reconcile_interval' => 0,
        ]);
        $this->spy = new SpyAlertChannel;
        $this->app->instance(AlertFanout::class, new AlertFanout([$this->spy]));
    }

    private function crit(): array
    {
        return ['key' => 'ed.nedocs', 'status' => 'crit', 'text' => 'ED OVERCROWDED — NEDOCS 142'];
    }

    public function test_fanout_fires_once_on_open_transition_and_never_on_held_snapshots(): void
    {
        $engine = app(AlertEngine::class);

        $engine->reconcile('HOSP1', [$this->crit()]);
        $this->assertCount(0, $this->spy->sent);

        $engine->reconcile('HOSP1', [$this->crit()]);
        $this->assertCount(1, $this->spy->sent);
        $this->assertSame('ed.nedocs', $this->spy->sent[0]->key);

        // Held snapshots (candidate persists) never re-page.
        $engine->reconcile('HOSP1', [$this->crit()]);
        $engine->reconcile('HOSP1', [$this->crit()]);
        $this->assertCount(1, $this->spy->sent);

        // Clearing never pages either.
        $engine->reconcile('HOSP1', []);
        $engine->reconcile('HOSP1', []);
        $this->assertCount(1, $this->spy->sent);
    }

    public function test_warn_alerts_page_only_when_the_kpi_opts_in(): void
    {
        $fanout = new AlertFanout([$this->spy]);

        $warn = new CockpitAlert(['facility_key' => 'HOSP1', 'key' => 'staffing.overtime', 'status' => 'warn', 'text' => 'OT high']);
        $fanout->alertOpened($warn);
        $this->assertCount(0, $this->spy->sent);

        \App\Models\Ops\MetricDefinition::query()->create([
            'metric_definition_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'metric_key' => 'staffing.overtime',
            'label' => 'Overtime',
            'domain' => 'staffing',
            'definition' => 'Overtime % of worked hours',
            'direction' => 'down',
            'cadence' => 'live',
            'is_active' => true,
            'metadata' => ['page_on_warn' => true],
        ]);

        $fanout->alertOpened($warn);
        $this->assertCount(1, $this->spy->sent);
    }

    public function test_push_channel_is_inert_without_eddy_push_enabled(): void
    {
        config(['eddy.push.enabled' => false]);

        $push = new SpyPushNotifier;
        $channel = new PushAlertChannel($push);

        $alert = new CockpitAlert(['facility_key' => 'HOSP1', 'key' => 'ed.nedocs', 'status' => 'crit', 'text' => 'x']);

        $this->assertSame(0, $channel->send($alert));
        $this->assertCount(0, $push->pushes);
    }

    public function test_push_channel_pages_active_admins_phi_free_when_enabled(): void
    {
        config(['eddy.push.enabled' => true]);

        Role::findOrCreate('super-admin', 'web');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super-admin');
        $bystander = User::factory()->create(['is_active' => true]); // non-admin: never paged

        $push = new SpyPushNotifier;
        $channel = new PushAlertChannel($push);

        $alert = new CockpitAlert(['facility_key' => 'HOSP1', 'key' => 'ed.nedocs', 'status' => 'crit', 'text' => 'NEDOCS 142']);

        // The seed_rbac_roles migration grants super-admin to base users, so
        // assert membership rather than an exact count.
        $expected = User::query()->where('is_active', true)->role(['super-admin', 'admin'])->count();
        $this->assertSame($expected, $channel->send($alert));

        $pagedIds = array_map(fn (array $p): int => $p['user']->id, $push->pushes);
        $this->assertContains($admin->id, $pagedIds);
        $this->assertNotContains($bystander->id, $pagedIds);

        // Doorbell, not letter: identifiers only — the rendered alert text
        // never rides in the push payload.
        $data = $push->pushes[0]['data'];
        $this->assertSame('cockpit_alert', $data['kind']);
        $this->assertSame('ed.nedocs', $data['key']);
        $this->assertArrayNotHasKey('text', $data);
    }

    public function test_teams_channel_is_inert_without_a_webhook_url(): void
    {
        Http::fake();

        config(['services.teams.alert_webhook_url' => '']);
        $alert = new CockpitAlert(['facility_key' => 'HOSP1', 'key' => 'ed.nedocs', 'status' => 'crit', 'text' => 'NEDOCS 142']);

        $this->assertSame(0, (new TeamsAlertChannel)->send($alert));
        Http::assertNothingSent();

        config(['services.teams.alert_webhook_url' => 'https://example.test/webhook']);
        $this->assertSame(1, (new TeamsAlertChannel)->send($alert));
        Http::assertSentCount(1);
    }

    public function test_risk_to_cockpit_status_mapping_is_the_single_severity_vocabulary(): void
    {
        $notifier = app(EddyApprovalNotifier::class);

        $this->assertSame('crit', $notifier->statusForRisk('critical')->value);
        $this->assertSame('warn', $notifier->statusForRisk('high')->value);
        $this->assertSame('watch', $notifier->statusForRisk('medium')->value);
        $this->assertSame('ok', $notifier->statusForRisk('low')->value);
        // Never escalate on uncertainty.
        $this->assertSame('watch', $notifier->statusForRisk('unknown')->value);
    }
}
