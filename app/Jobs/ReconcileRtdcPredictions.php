<?php

namespace App\Jobs;

use App\Models\Unit;
use App\Services\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileRtdcPredictions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ReconciliationService $service): void
    {
        $yesterday = today()->subDay();
        Unit::where('is_deleted', false)->each(function (Unit $unit) use ($service, $yesterday) {
            $service->reconcile($unit->unit_id, $yesterday);
        });
    }
}
