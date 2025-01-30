<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    public function index()
    {
        $services = DB::table('prod.services')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($services);
    }
}
