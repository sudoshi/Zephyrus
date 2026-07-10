<?php

namespace App\Jobs;

use App\Integrations\Healthcare\Services\EnterpriseConnectorControlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DispatchScheduledIntegrationHealthChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onConnection('database')->onQueue('integrations');
    }

    public function handle(EnterpriseConnectorControlService $connectors): void
    {
        DB::table('integration.sources')
            ->whereIn('active_status', ['active', 'testing'])
            ->where(function ($query): void {
                $query->where('interface_type', 'like', '%fhir%')
                    ->orWhere('interface_type', 'like', '%hl7%');
            })
            ->orderBy('source_id')
            ->pluck('source_id')
            ->each(fn (mixed $sourceId) => $connectors->queueHealthCheck((int) $sourceId, null, (string) Str::uuid()));
    }
}
