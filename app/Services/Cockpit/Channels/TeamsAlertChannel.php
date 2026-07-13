<?php

namespace App\Services\Cockpit\Channels;

use App\Contracts\AlertChannel;
use App\Models\Cockpit\CockpitAlert;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Teams webhook lane for opened cockpit alerts. Inert until
 * TEAMS_ALERT_WEBHOOK_URL is configured. The alert text is the rendered
 * alert_template — aggregate operational counts only (same PHI-free class as
 * the public Reverb board channels).
 */
class TeamsAlertChannel implements AlertChannel
{
    private readonly ClinicalContentGuard $clinicalContent;

    public function __construct(?ClinicalContentGuard $clinicalContent = null)
    {
        $this->clinicalContent = $clinicalContent ?? app(ClinicalContentGuard::class);
    }

    public function send(CockpitAlert $alert): int
    {
        $url = (string) config('services.teams.alert_webhook_url', '');

        if ($url === '') {
            return 0;
        }
        $this->clinicalContent->assertSafe(
            ['key' => $alert->key, 'status' => $alert->status, 'text' => $alert->text],
            'clinical_content_alert_rejected',
        );

        try {
            $response = Http::timeout(5)->post($url, [
                'text' => sprintf(
                    '%s **Zephyrus %s** — %s',
                    $alert->status === 'crit' ? '◆' : '▲',
                    $alert->status === 'crit' ? 'CRITICAL' : 'warning',
                    $alert->text,
                ),
            ]);

            return $response->successful() ? 1 : 0;
        } catch (\Throwable $e) {
            Log::warning('cockpit.alerts.teams_send_failed', ['key' => $alert->key, 'error' => $e->getMessage()]);

            return 0;
        }
    }
}
