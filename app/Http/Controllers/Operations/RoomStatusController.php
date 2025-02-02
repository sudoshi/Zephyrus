<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class RoomStatusController extends Controller
{
    public function index()
    {
        return Inertia::render('Operations/RoomStatus');
    }
}
