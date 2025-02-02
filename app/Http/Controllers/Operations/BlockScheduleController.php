<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class BlockScheduleController extends Controller
{
    public function index()
    {
        return Inertia::render('Operations/BlockSchedule');
    }
}
