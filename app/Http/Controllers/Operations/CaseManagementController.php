<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\ORCase;
use App\Models\Provider;
use App\Models\Room;
use App\Models\Reference\Service;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CaseManagementController extends Controller
{
    public function index()
    {
        // Get active cases with related data
        $cases = ORCase::with(['provider', 'room', 'service'])
            ->whereIn('status', ['scheduled', 'in_progress', 'delayed'])
            ->get()
            ->map(function ($case) {
                return [
                    'id' => $case->id,
                    'patient' => $case->patient_name,
                    'type' => $case->procedure_name,
                    'specialty' => $case->service->name,
                    'status' => $case->status,
                    'phase' => $case->phase,
                    'location' => $case->room->name,
                    'startTime' => $case->scheduled_start_time->format('H:i'),
                    'expectedDuration' => $case->expected_duration,
                    'provider' => $case->provider->name,
                    'resourceStatus' => $case->resource_status,
                    'journey' => $case->progress_percentage,
                    'staff' => $this->getStaffForCase($case),
                    'resources' => $this->getResourcesForCase($case),
                    'notes' => $case->notes,
                    'alerts' => $case->alerts,
                ];
            });

        // Get specialty statistics
        $specialties = Service::withCount(['cases as count', 'on_time_cases as onTime'])
            ->get()
            ->mapWithKeys(function ($service) {
                return [$service->name => [
                    'color' => $service->color_code,
                    'count' => $service->count,
                    'onTime' => $service->onTime,
                    'delayed' => $service->count - $service->onTime,
                ]];
            });

        // Get location statistics
        $locations = Room::withCount(['active_cases as inUse'])
            ->get()
            ->mapWithKeys(function ($room) {
                return [$room->name => [
                    'total' => $room->capacity,
                    'inUse' => $room->inUse,
                ]];
            });

        // Get overall statistics
        $stats = [
            'totalPatients' => ORCase::whereDate('scheduled_date', today())->count(),
            'inProgress' => ORCase::where('status', 'in_progress')->count(),
            'delayed' => ORCase::where('status', 'delayed')->count(),
            'completed' => ORCase::where('status', 'completed')->whereDate('scheduled_date', today())->count(),
            'preOp' => ORCase::where('status', 'scheduled')->where('phase', 'pre_op')->count(),
        ];

        // Get analytics data
        $analyticsData = $this->getAnalyticsData();

        return Inertia::render('Operations/CaseManagement', [
            'procedures' => $cases,
            'specialties' => $specialties,
            'locations' => $locations,
            'stats' => $stats,
            'analyticsData' => $analyticsData,
        ]);
    }

    private function getStaffForCase($case)
    {
        // This would be replaced with actual staff assignments in production
        return [
            ['name' => $case->provider->name, 'role' => 'Surgeon'],
            ['name' => 'Dr. Anesthesiologist', 'role' => 'Anesthesiologist'],
            ['name' => 'Nurse Staff', 'role' => 'Scrub Nurse'],
        ];
    }

    private function getResourcesForCase($case)
    {
        // This would be replaced with actual resource tracking in production
        return [
            ['name' => $case->room->name, 'status' => $case->resource_status],
            ['name' => 'Equipment Set', 'status' => 'onTime'],
            ['name' => 'Anesthesia Machine', 'status' => 'onTime'],
        ];
    }

    private function getAnalyticsData()
    {
        // This would be replaced with actual analytics calculations in production
        $months = collect(['Jan', 'Mar', 'May', 'Jul', 'Sep', 'Nov']);
        $years = ['23', '24'];
        
        return $months->flatMap(function ($month) use ($years) {
            return collect($years)->map(function ($year) use ($month) {
                return [
                    'month' => "{$month} {$year}",
                    'cases' => rand(370, 470),
                    'avgDuration' => rand(90, 105),
                    'totalTime' => rand(35000, 45000),
                ];
            });
        })->values();
    }
}
