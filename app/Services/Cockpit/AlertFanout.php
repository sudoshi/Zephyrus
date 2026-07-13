<?php

namespace App\Services\Cockpit;

use App\Contracts\AlertChannel;
use App\Models\Cockpit\CockpitAlert;
use App\Models\Ops\MetricDefinition;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Illuminate\Support\Facades\Log;

/**
 * Zephyrus 2.0 P6 workstream 3 — fan-out for alerts that just OPENED.
 *
 * The paging policy is the Earned-Urgency ration applied to notifications:
 * crit always fans out; warn fans out only when the KPI definition opts in
 * (metadata.page_on_warn) — everything else surfaces in-app only. The
 * AlertEngine calls this exactly once per open TRANSITION (never on held
 * snapshots), so the flap-damping upstream is what keeps phones quiet.
 */
class AlertFanout
{
    private readonly ClinicalContentGuard $clinicalContent;

    /** @param list<AlertChannel> $channels */
    public function __construct(private readonly array $channels, ?ClinicalContentGuard $clinicalContent = null)
    {
        $this->clinicalContent = $clinicalContent ?? app(ClinicalContentGuard::class);
    }

    public function alertOpened(CockpitAlert $alert): void
    {
        if ($this->clinicalContent->contains([
            'facility_key' => $alert->facility_key,
            'key' => $alert->key,
            'status' => $alert->status,
            'text' => $alert->text,
        ])) {
            Log::warning('cockpit.alerts.clinical_content_suppressed', [
                'key' => $alert->key,
                'status' => $alert->status,
            ]);

            return;
        }

        if (! $this->shouldPage($alert)) {
            return;
        }

        foreach ($this->channels as $channel) {
            try {
                $channel->send($alert);
            } catch (\Throwable $e) {
                // A broken lane must never break the snapshot refresh.
                Log::warning('cockpit.alerts.fanout_failed', [
                    'channel' => $channel::class,
                    'key' => $alert->key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function shouldPage(CockpitAlert $alert): bool
    {
        if ($alert->status === 'crit') {
            return true;
        }

        if ($alert->status !== 'warn') {
            return false;
        }

        $metadata = MetricDefinition::query()
            ->where('metric_key', $alert->key)
            ->value('metadata');

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return (bool) (($metadata ?? [])['page_on_warn'] ?? false);
    }
}
