<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    public function index()
    {
        $services = DB::table('prod.services')
            ->where('active_status', true)
            ->orderBy('name')
            ->get();

        return response()->json($services);
    }
}
