<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\RoomRunningService;
use Inertia\Inertia;

class RoomRunningController extends Controller
{
    public function index()
    {
        return Inertia::render('Analytics/RoomRunning', [
            'roomRunning' => (new RoomRunningService)->build(),
        ]);
    }
}
