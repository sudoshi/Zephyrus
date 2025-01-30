<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function servicePerformance(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());

        // Service utilization metrics
        $utilization = DB::table('prod.case_metrics as cm')
            ->join('prod.or_cases as c', 'cm.case_id', '=', 'c.case_id')
            ->join('prod.services as s', 'c.case_service_id', '=', 's.service_id')
            ->whereBetween('c.surgery_date', [$startDate, $endDate])
            ->where('c.is_deleted', false)
            ->groupBy('s.service_id', 's.name')
            ->select(
                's.service_id',
                's.name as service_name',
                DB::raw('COUNT(DISTINCT c.case_id) as case_count'),
                DB::raw('AVG(cm.utilization_percentage) as avg_utilization'),
                DB::raw('AVG(cm.turnover_time) as avg_turnover'),
                DB::raw('AVG(CASE WHEN c.actual_start_time <= c.scheduled_start_time THEN 1 ELSE 0 END) * 100 as on_time_start_percentage'),
                DB::raw('AVG(EXTRACT(EPOCH FROM (c.actual_end_time - c.actual_start_time))/60) as avg_duration')
            )
            ->get();

        // Service trends over time
        $trends = DB::table('prod.case_metrics as cm')
            ->join('prod.or_cases as c', 'cm.case_id', '=', 'c.case_id')
            ->join('prod.services as s', 'c.case_service_id', '=', 's.service_id')
            ->whereBetween('c.surgery_date', [$startDate, $endDate])
            ->where('c.is_deleted', false)
            ->groupBy('s.service_id', 's.name', 'c.surgery_date')
            ->select(
                's.service_id',
                's.name as service_name',
                'c.surgery_date',
                DB::raw('COUNT(DISTINCT c.case_id) as case_count'),
                DB::raw('AVG(cm.utilization_percentage) as utilization'),
                DB::raw('AVG(cm.turnover_time) as turnover_time')
            )
            ->orderBy('c.surgery_date')
            ->get();

        // Block utilization by service
        $blockUtilization = DB::table('prod.block_utilization as bu')
            ->join('prod.services as s', 'bu.service_id', '=', 's.service_id')
            ->whereBetween('bu.date', [$startDate, $endDate])
            ->groupBy('s.service_id', 's.name')
            ->select(
                's.service_id',
                's.name as service_name',
                DB::raw('AVG(bu.utilization_percentage) as avg_block_utilization'),
                DB::raw('AVG(bu.prime_time_percentage) as avg_prime_time_utilization'),
                DB::raw('COUNT(DISTINCT bu.block_id) as block_count')
            )
            ->get();

        // Case volume distribution by day of week
        $dayDistribution = DB::table('prod.or_cases as c')
            ->join('prod.services as s', 'c.case_service_id', '=', 's.service_id')
            ->whereBetween('c.surgery_date', [$startDate, $endDate])
            ->where('c.is_deleted', false)
            ->groupBy('s.service_id', 's.name', 'day_of_week')
            ->select(
                's.service_id',
                's.name as service_name',
                DB::raw('EXTRACT(DOW FROM c.surgery_date) as day_of_week'),
                DB::raw('COUNT(*) as case_count')
            )
            ->get();

        return response()->json([
            'utilization' => $utilization,
            'trends' => $trends,
            'blockUtilization' => $blockUtilization,
            'dayDistribution' => $dayDistribution,
            'dateRange' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ]);
    }

    public function providerPerformance(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());

        // Provider metrics
        $metrics = DB::table('prod.case_metrics as cm')
            ->join('prod.or_cases as c', 'cm.case_id', '=', 'c.case_id')
            ->join('prod.providers as p', 'c.primary_surgeon_id', '=', 'p.provider_id')
            ->whereBetween('c.surgery_date', [$startDate, $endDate])
            ->where('c.is_deleted', false)
            ->groupBy('p.provider_id', 'p.name')
            ->select(
                'p.provider_id',
                'p.name as provider_name',
                DB::raw('COUNT(DISTINCT c.case_id) as case_count'),
                DB::raw('AVG(cm.utilization_percentage) as avg_utilization'),
                DB::raw('AVG(cm.turnover_time) as avg_turnover'),
                DB::raw('AVG(CASE WHEN c.actual_start_time <= c.scheduled_start_time THEN 1 ELSE 0 END) * 100 as on_time_start_percentage'),
                DB::raw('AVG(EXTRACT(EPOCH FROM (c.actual_end_time - c.actual_start_time))/60) as avg_duration'),
                DB::raw('AVG(CASE WHEN c.actual_end_time > c.scheduled_end_time THEN 1 ELSE 0 END) * 100 as overtime_percentage')
            )
            ->get();

        // Provider trends
        $trends = DB::table('prod.case_metrics as cm')
            ->join('prod.or_cases as c', 'cm.case_id', '=', 'c.case_id')
            ->join('prod.providers as p', 'c.primary_surgeon_id', '=', 'p.provider_id')
            ->whereBetween('c.surgery_date', [$startDate, $endDate])
            ->where('c.is_deleted', false)
            ->groupBy('p.provider_id', 'p.name', 'c.surgery_date')
            ->select(
                'p.provider_id',
                'p.name as provider_name',
                'c.surgery_date',
                DB::raw('COUNT(DISTINCT c.case_id) as case_count'),
                DB::raw('AVG(cm.utilization_percentage) as utilization'),
                DB::raw('AVG(cm.turnover_time) as turnover_time')
            )
            ->orderBy('c.surgery_date')
            ->get();

        return response()->json([
            'metrics' => $metrics,
            'trends' => $trends,
            'dateRange' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ]);
    }

    public function historicalTrends(Request $request)
    {
        $startDate = $request->input('start_date', now()->subYear()->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $groupBy = $request->input('group_by', 'month'); // month, quarter, year

        $grouping = match($groupBy) {
            'month' => "DATE_TRUNC('month', c.surgery_date)",
            'quarter' => "DATE_TRUNC('quarter', c.surgery_date)",
            'year' => "DATE_TRUNC('year', c.surgery_date)",
            default => "DATE_TRUNC('month', c.surgery_date)"
        };

        // Overall trends
        $trends = DB::table('prod.case_metrics as cm')
            ->join('prod.or_cases as c', 'cm.case_id', '=', 'c.case_id')
            ->whereBetween('c.surgery_date', [$startDate, $endDate])
            ->where('c.is_deleted', false)
            ->groupBy(DB::raw($grouping))
            ->select(
                DB::raw("$grouping as period"),
                DB::raw('COUNT(DISTINCT c.case_id) as case_count'),
                DB::raw('AVG(cm.utilization_percentage) as avg_utilization'),
                DB::raw('AVG(cm.turnover_time) as avg_turnover'),
                DB::raw('AVG(CASE WHEN c.actual_start_time <= c.scheduled_start_time THEN 1 ELSE 0 END) * 100 as on_time_start_percentage')
            )
            ->orderBy('period')
            ->get();

        // Service growth
        $serviceGrowth = DB::table('prod.or_cases as c')
            ->join('prod.services as s', 'c.case_service_id', '=', 's.service_id')
            ->whereBetween('c.surgery_date', [$startDate, $endDate])
            ->where('c.is_deleted', false)
            ->groupBy('s.service_id', 's.name', DB::raw($grouping))
            ->select(
                's.service_id',
                's.name as service_name',
                DB::raw("$grouping as period"),
                DB::raw('COUNT(*) as case_count')
            )
            ->orderBy('period')
            ->get();

        return response()->json([
            'trends' => $trends,
            'serviceGrowth' => $serviceGrowth,
            'dateRange' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ]);
    }
}
