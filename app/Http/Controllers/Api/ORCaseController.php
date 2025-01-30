<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ORCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ORCaseController extends Controller
{
    protected function validateCase(Request $request)
    {
        return Validator::make($request->all(), [
            'patient_name' => 'required|string|max:255',
            'mrn' => 'required|string|max:50',
            'procedure_name' => 'required|string|max:255',
            'service_id' => 'required|exists:prod.service,service_id',
            'room_id' => 'required|exists:prod.room,room_id',
            'primary_surgeon_id' => 'required|exists:prod.provider,provider_id',
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
        try {
            $query = ORCase::with(['surgeon', 'room', 'service', 'status'])
                ->where('is_deleted', false);

            // Apply filters
            if ($request->has('date')) {
                $query->where('surgery_date', $request->date);
            }

            if ($request->has('status') && $request->status !== '') {
                $query->where('status_id', $request->status);
            }

            if ($request->has('service') && $request->service !== '') {
                $query->where('case_service_id', $request->service);
            }

            if ($request->has('room') && $request->room !== '') {
                $query->where('room_id', $request->room);
            }

            $cases = $query
                ->orderBy('surgery_date', 'desc')
                ->orderBy('scheduled_start_time', 'asc')
                ->get();

            return response()->json($cases);
        } catch (\Exception $e) {
            Log::error('Error in index: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function todaysCases()
    {
        try {
            Log::info('Fetching today\'s cases for date: ' . now()->toDateString());
            
            $cases = ORCase::with(['surgeon', 'room', 'service', 'status'])
                ->where('surgery_date', now()->toDateString())
                ->where('is_deleted', false)
                ->orderBy('scheduled_start_time', 'asc')
                ->get();

            Log::info('Found ' . $cases->count() . ' cases for today');
            return response()->json($cases);
        } catch (\Exception $e) {
            Log::error('Error in todaysCases: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function metrics()
    {
        try {
            Log::info('Fetching metrics for last 7 days');
            
            $utilization = DB::table('prod.case_metrics')
                ->join('prod.orcase', 'case_metrics.case_id', '=', 'orcase.case_id')
                ->where('orcase.surgery_date', '>=', now()->subDays(7)->toDateString())
                ->select(
                    'orcase.surgery_date',
                    DB::raw('AVG(utilization_percentage) as utilization'),
                    DB::raw('AVG(turnover_time) as avg_turnover'),
                    DB::raw('COUNT(*) as case_count')
                )
                ->groupBy('orcase.surgery_date')
                ->orderBy('orcase.surgery_date')
                ->get();

            Log::info('Found metrics for ' . $utilization->count() . ' days');
            return response()->json([
                'utilization' => $utilization,
                'summary' => [
                    'avg_utilization' => $utilization->avg('utilization'),
                    'avg_turnover' => $utilization->avg('avg_turnover'),
                    'total_cases' => $utilization->sum('case_count')
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in metrics: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function roomStatus()
    {
        try {
            Log::info('Fetching room status');
            
            // Get all active rooms
            $rooms = DB::table('prod.room')
                ->where('active_status', true)
                ->orderBy('name')
                ->get();

            Log::info('Found ' . $rooms->count() . ' active rooms');

            // Get current cases and logs
            $currentCases = DB::table('prod.orcase as c')
                ->join('prod.orlog as l', 'c.case_id', '=', 'l.case_id')
                ->join('prod.provider as p', 'c.primary_surgeon_id', '=', 'p.provider_id')
                ->join('prod.service as s', 'c.case_service_id', '=', 's.service_id')
                ->select(
                    'c.room_id',
                    'c.case_id',
                    'c.procedure_name',
                    'c.scheduled_duration',
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

            Log::info('Found ' . $currentCases->count() . ' current cases');

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
                    'scheduled_duration' => $currentCase ? $currentCase->scheduled_duration : null,
                    'or_in_time' => $currentCase ? $currentCase->or_in_time : null,
                    'or_out_time' => $currentCase ? $currentCase->or_out_time : null,
                    'status' => $currentCase ? $currentCase->status : 'Available'
                ];
            });

            return response()->json($status);
        } catch (\Exception $e) {
            Log::error('Error in roomStatus: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}
