<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ORCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ORCaseController extends Controller
{
    protected function validateCase(Request $request)
    {
        return Validator::make($request->all(), [
            'patient_name' => 'required|string|max:255',
            'mrn' => 'required|string|max:50',
            'procedure_name' => 'required|string|max:255',
            'service_id' => 'required|exists:prod.services,service_id',
            'room_id' => 'required|exists:prod.rooms,room_id',
            'primary_surgeon_id' => 'required|exists:prod.providers,provider_id',
            'surgery_date' => 'required|date',
            'scheduled_start_time' => 'required|date_format:H:i',
            'estimated_duration' => 'required|integer|min:15',
            'case_class' => 'required|in:Elective,Urgent,Emergency',
            'notes' => 'nullable|string'
        ]);
    }

    public function store(Request $request)
    {
        $validator = $this->validateCase($request);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $case = new ORCase();
        $case->fill($request->all());
        $case->status = 'Scheduled';
        $case->is_deleted = false;
        $case->save();

        return response()->json($case, 201);
    }

    public function update(Request $request, $id)
    {
        $validator = $this->validateCase($request);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $case = ORCase::findOrFail($id);
        $case->fill($request->all());
        $case->save();

        return response()->json($case);
    }

    public function index(Request $request)
    {
        $query = DB::table('prod.or_cases')
            ->join('prod.providers', 'or_cases.primary_surgeon_id', '=', 'providers.provider_id')
            ->join('prod.rooms', 'or_cases.room_id', '=', 'rooms.room_id')
            ->join('prod.services', 'or_cases.case_service_id', '=', 'services.service_id')
            ->select(
                'or_cases.*',
                'providers.name as surgeon_name',
                'rooms.name as room_name',
                'services.name as service_name'
            )
            ->where('or_cases.is_deleted', false);

        // Apply filters
        if ($request->has('date')) {
            $query->where('or_cases.surgery_date', $request->date);
        }

        if ($request->has('status') && $request->status !== '') {
            $query->where('or_cases.status', $request->status);
        }

        if ($request->has('service') && $request->service !== '') {
            $query->where('or_cases.case_service_id', $request->service);
        }

        if ($request->has('room') && $request->room !== '') {
            $query->where('or_cases.room_id', $request->room);
        }

        $cases = $query
            ->orderBy('or_cases.surgery_date', 'desc')
            ->orderBy('or_cases.scheduled_start_time', 'asc')
            ->get();

        return response()->json($cases);
    }

    public function todaysCases()
    {
        $cases = DB::table('prod.or_cases')
            ->join('prod.providers', 'or_cases.primary_surgeon_id', '=', 'providers.provider_id')
            ->join('prod.rooms', 'or_cases.room_id', '=', 'rooms.room_id')
            ->join('prod.services', 'or_cases.case_service_id', '=', 'services.service_id')
            ->select(
                'or_cases.*',
                'providers.name as surgeon_name',
                'rooms.name as room_name',
                'services.name as service_name'
            )
            ->where('or_cases.surgery_date', now()->toDateString())
            ->where('or_cases.is_deleted', false)
            ->orderBy('or_cases.scheduled_start_time', 'asc')
            ->get();

        return response()->json($cases);
    }

    public function metrics()
    {
        $utilization = DB::table('prod.case_metrics')
            ->join('prod.or_cases', 'case_metrics.case_id', '=', 'or_cases.case_id')
            ->where('or_cases.surgery_date', '>=', now()->subDays(7)->toDateString())
            ->select(
                'or_cases.surgery_date',
                DB::raw('AVG(utilization_percentage) as utilization'),
                DB::raw('AVG(turnover_time) as avg_turnover'),
                DB::raw('COUNT(*) as case_count')
            )
            ->groupBy('or_cases.surgery_date')
            ->orderBy('or_cases.surgery_date')
            ->get();

        return response()->json([
            'utilization' => $utilization,
            'summary' => [
                'avg_utilization' => $utilization->avg('utilization'),
                'avg_turnover' => $utilization->avg('avg_turnover'),
                'total_cases' => $utilization->sum('case_count')
            ]
        ]);
    }

    public function roomStatus()
    {
        // Get all active rooms
        $rooms = DB::table('prod.rooms')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Get current cases and logs
        $currentCases = DB::table('prod.or_cases as c')
            ->join('prod.or_logs as l', 'c.case_id', '=', 'l.case_id')
            ->join('prod.providers as p', 'c.primary_surgeon_id', '=', 'p.provider_id')
            ->join('prod.services as s', 'c.case_service_id', '=', 's.service_id')
            ->select(
                'c.room_id',
                'c.case_id',
                'c.procedure_name',
                'c.estimated_duration',
                'p.name as surgeon_name',
                's.name as service_name',
                'l.or_in_time',
                'l.or_out_time',
                DB::raw("
                    CASE 
                        WHEN l.or_in_time IS NOT NULL AND l.or_out_time IS NULL THEN 'In Progress'
                        WHEN l.or_out_time IS NOT NULL THEN 'Turnover'
                        ELSE 'Available'
                    END as status
                ")
            )
            ->where('c.surgery_date', now()->toDateString())
            ->where('c.is_deleted', false)
            ->get();

        // Map room status
        $status = $rooms->map(function ($room) use ($currentCases) {
            $currentCase = $currentCases->firstWhere('room_id', $room->room_id);
            
            return [
                'room_id' => $room->room_id,
                'room_name' => $room->name,
                'case_id' => $currentCase ? $currentCase->case_id : null,
                'procedure_name' => $currentCase ? $currentCase->procedure_name : null,
                'surgeon_name' => $currentCase ? $currentCase->surgeon_name : null,
                'service_name' => $currentCase ? $currentCase->service_name : null,
                'estimated_duration' => $currentCase ? $currentCase->estimated_duration : null,
                'or_in_time' => $currentCase ? $currentCase->or_in_time : null,
                'or_out_time' => $currentCase ? $currentCase->or_out_time : null,
                'status' => $currentCase ? $currentCase->status : 'Available'
            ];
        });

        return response()->json($status);
    }
}
