<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BlockScheduleService;
use Inertia\Inertia;

class BlockScheduleController extends Controller
{
    public function index(BlockScheduleService $blockSchedule)
    {
        $payload = $blockSchedule->build();

        return Inertia::render('Operations/BlockSchedule', [
            'metrics' => $payload['metrics'],
            'calendar' => $payload['calendar'],
        ]);
    }
}
