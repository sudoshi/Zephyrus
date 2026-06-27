<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    public function index()
    {
        $rooms = DB::table('prod.rooms')
            ->where('active_status', true)
            ->orderBy('name')
            ->get();

        return response()->json($rooms);
    }
}
