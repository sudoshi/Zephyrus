<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BlockUtilizationController extends Controller
{
    public function index()
    {
        return Inertia::render('Analytics/BlockUtilization');
    }
}
