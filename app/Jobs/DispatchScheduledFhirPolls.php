<?php

namespace App\Jobs;

use App\Integrations\Healthcare\Services\EnterpriseConnectorControlService;
use App\Jobs\Middleware\FailClinicalJobSafely;
use App\Security\ClinicalPayloads\ClinicalPayloadSafeQueueJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DispatchScheduledFhirPolls implements ClinicalPayloadSafeQueueJob, ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onConnection('database')->onQueue('integrations');
    }

    public function handle(EnterpriseConnectorControlService $connectors): void
    {
        DB::table('integration.sources as source')
            ->join('integration.fhir_client_connections as connection', 'connection.source_id', '=', 'source.source_id')
            ->join('integration.smart_backend_credentials as credential', 'credential.source_id', '=', 'source.source_id')
            ->join('integration.fhir_resource_profiles as profile', 'profile.source_id', '=', 'source.source_id')
            ->join('integration.source_capabilities as capability', function ($join): void {
                $join->on('capability.source_id', '=', 'profile.source_id')
                    ->on('capability.resource_type', '=', 'profile.resource_type')
                    ->where('capability.capability_type', 'fhir_resource')
                    ->where('capability.supported', true);
            })
            ->where('source.active_status', 'active')
            ->where('connection.health_status', 'healthy')
            ->whereIn('credential.status', ['ready', 'active'])
            ->where('profile.profile_status', 'enabled')
            ->where('profile.poll_enabled', true)
            ->distinct()
            ->get(['source.source_id', 'profile.resource_type'])
            ->each(function (object $profile) use ($connectors): void {
                $connectors->queueFhirPoll(
                    (int) $profile->source_id,
                    (string) $profile->resource_type,
                    null,
                    (string) Str::uuid(),
                );
            });
    }

    /** @return list<FailClinicalJobSafely> */
    public function middleware(): array
    {
        return [new FailClinicalJobSafely];
    }

    public function clinicalPayloadSafeArguments(): array
    {
        return [];
    }
}
