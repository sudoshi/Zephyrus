<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BlockScheduleController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service_id' => 'required|exists:prod.services,service_id',
            'room_id' => 'required|exists:prod.rooms,room_id',
            'block_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $block = new BlockTemplate();
        $block->service_id = $request->service_id;
        $block->room_id = $request->room_id;
        $block->block_date = $request->block_date;
        $block->start_time = $request->start_time;
        $block->end_time = $request->end_time;
        $block->is_deleted = false;
        $block->save();

        return response()->json($block, 201);
    }

    public function index()
    {
        $blocks = DB::table('prod.block_templates as bt')
            ->join('prod.rooms as r', 'bt.room_id', '=', 'r.room_id')
            ->join('prod.services as s', 'bt.service_id', '=', 's.service_id')
            ->leftJoin('prod.providers as p', 'bt.surgeon_id', '=', 'p.provider_id')
            ->select(
                'bt.*',
                'r.name as room_name',
                's.name as service_name',
                'p.name as surgeon_name'
            )
            ->where('bt.is_deleted', false)
            ->orderBy('bt.block_date')
            ->orderBy('bt.start_time')
            ->get();

        return response()->json($blocks);
    }

    public function utilization()
    {
        $utilization = DB::table('prod.block_utilization')
            ->join('prod.block_templates as bt', 'block_utilization.block_id', '=', 'bt.block_id')
            ->join('prod.services as s', 'block_utilization.service_id', '=', 's.service_id')
            ->select(
                'block_utilization.*',
                'bt.title',
                's.name as service_name'
            )
            ->where('block_utilization.date', '>=', now()->subDays(30)->toDateString())
            ->orderBy('block_utilization.date')
            ->get();

        return response()->json([
            'utilization' => $utilization,
            'summary' => [
                'avg_utilization' => $utilization->avg('utilization_percentage'),
                'avg_prime_time' => $utilization->avg('prime_time_percentage'),
                'total_blocks' => $utilization->count()
            ]
        ]);
    }

    public function serviceUtilization()
    {
        $utilization = DB::table('prod.block_utilization as bu')
            ->join('prod.services as s', 'bu.service_id', '=', 's.service_id')
            ->select(
                's.name as service_name',
                DB::raw('AVG(bu.utilization_percentage) as avg_utilization'),
                DB::raw('AVG(bu.prime_time_percentage) as avg_prime_time'),
                DB::raw('COUNT(DISTINCT bu.block_id) as block_count'),
                DB::raw('SUM(bu.cases_performed) as total_cases')
            )
            ->where('bu.date', '>=', now()->subDays(30)->toDateString())
            ->groupBy('s.service_id', 's.name')
            ->orderBy('avg_utilization', 'desc')
            ->get();

        return response()->json($utilization);
    }

    public function roomUtilization()
    {
        $utilization = DB::table('prod.room_utilization as ru')
            ->join('prod.rooms as r', 'ru.room_id', '=', 'r.room_id')
            ->select(
                'r.name as room_name',
                DB::raw('AVG(ru.utilization_percentage) as avg_utilization'),
                DB::raw('AVG(ru.turnover_minutes) as avg_turnover'),
                DB::raw('SUM(ru.cases_performed) as total_cases'),
                DB::raw('AVG(ru.avg_case_duration) as avg_case_duration')
            )
            ->where('ru.date', '>=', now()->subDays(30)->toDateString())
            ->groupBy('r.room_id', 'r.name')
            ->orderBy('avg_utilization', 'desc')
            ->get();

        return response()->json($utilization);
    }
}
