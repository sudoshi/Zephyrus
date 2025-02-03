<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\ORCase;
use App\Models\Reference\CaseStatus;
use App\Models\Reference\Service;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CaseManagementController extends Controller
{
    public function index()
    {
        // Get active cases with related data
        $cases = ORCase::with(['provider', 'room', 'service', 'status'])
            ->whereHas('status', function($query) {
                $query->whereIn('code', ['SCHED', 'INPROG', 'DELAY']);
            })
            ->get()
            ->map(function ($case) {
                return [
                    'id' => $case->case_id,
                    'patient' => $case->patient_id,
                    'type' => $case->procedure_name,
                    'specialty' => $case->service->name,
                    'status' => $case->statusCode,
                    'phase' => $case->phase ?? 'Pre-Op',
                    'location' => $case->room->name,
                    'startTime' => $case->scheduled_start_time->format('H:i'),
                    'expectedDuration' => $case->scheduled_duration,
                    'provider' => $case->provider->name,
                    'resourceStatus' => $case->status->code === 'DELAY' ? 'Delayed' : 'On Time',
                    'journey' => $case->journey_progress ?? 0,
                    'staff' => [], // TODO: Implement staff assignments
                    'resources' => [] // TODO: Implement resource tracking
                ];
            });

        // Get specialty statistics
        $specialtyStats = Service::select('services.name', 'services.code')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(CASE WHEN cs.code != \'DELAY\' THEN 1 ELSE 0 END) as onTime')
            ->selectRaw('SUM(CASE WHEN cs.code = \'DELAY\' THEN 1 ELSE 0 END) as delayed')
            ->join('prod.or_cases as oc', 'services.service_id', '=', 'oc.case_service_id')
            ->join('prod.case_statuses as cs', 'oc.status_id', '=', 'cs.status_id')
            ->whereIn('cs.code', ['SCHED', 'INPROG', 'DELAY'])
            ->groupBy('services.name', 'services.code')
            ->get()
            ->mapWithKeys(function ($specialty) {
                $colors = [
                    'GS' => 'info',
                    'ORTHO' => 'success',
                    'OBGYN' => 'warning',
                    'CARD' => 'error',
                    'NEURO' => 'primary'
                ];
                return [
                    $specialty->name => [
                        'color' => $colors[$specialty->code] ?? 'info',
                        'count' => $specialty->count,
                        'onTime' => $specialty->onTime,
                        'delayed' => $specialty->delayed
                    ]
                ];
            });

        // Get location statistics
        $locationStats = Room::select('rooms.name')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN oc.case_id IS NOT NULL THEN 1 ELSE 0 END) as inUse')
            ->leftJoin('prod.or_cases as oc', function($join) {
                $join->on('rooms.room_id', '=', 'oc.room_id')
                    ->whereIn('oc.status_id', function($query) {
                        $query->select('status_id')
                            ->from('prod.case_statuses')
                            ->whereIn('code', ['SCHED', 'INPROG', 'DELAY']);
                    });
            })
            ->groupBy('rooms.name')
            ->get()
            ->mapWithKeys(function ($location) {
                return [$location->name => [
                    'total' => $location->total,
                    'inUse' => $location->inUse
                ]];
            });

        // Get overall statistics
        $stats = [
            'totalPatients' => $cases->count(),
            'inProgress' => $cases->where('statusCode', 'inprog')->count(),
            'delayed' => $cases->where('statusCode', 'delay')->count(),
            'completed' => ORCase::whereHas('status', function($query) {
                $query->where('code', 'COMP');
            })->count(),
            'preOp' => $cases->where('phase', 'Pre-Op')->count()
        ];

        // Get analytics data
        $analyticsData = ORCase::select(
                DB::raw('DATE_FORMAT(surgery_date, \'%b %y\') as month'),
                DB::raw('COUNT(*) as cases'),
                DB::raw('AVG(scheduled_duration) as avgDuration'),
                DB::raw('SUM(scheduled_duration) as totalTime')
            )
            ->whereRaw('surgery_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)')
            ->groupBy('month')
            ->orderBy('surgery_date')
            ->get();

        return Inertia::render('Operations/CaseManagement', [
            'procedures' => $cases,
            'specialties' => $specialtyStats,
            'locations' => $locationStats,
            'stats' => $stats,
            'analyticsData' => $analyticsData
        ]);
    }
}
