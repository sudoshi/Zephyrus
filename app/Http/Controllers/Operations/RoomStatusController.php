<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\RoomStatusService;
use Inertia\Inertia;

class RoomStatusController extends Controller
{
    public function index(RoomStatusService $roomStatus)
    {
        return Inertia::render('Operations/RoomStatus', [
            'roomStatus' => $roomStatus->build(),
        ]);
    }
}
