<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class ProviderController extends Controller
{
    public function index()
    {
        $providers = DB::table('prod.providers')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($providers);
    }
}
