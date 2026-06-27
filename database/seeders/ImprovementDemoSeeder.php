<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the Process Improvement opportunity portfolio + resource library.
 *
 * Idempotent: keyed on title via updateOrInsert, so re-running db:seed neither
 * duplicates rows nor errors.
 */
class ImprovementDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Schema::hasTable('prod.improvement_opportunities')) {
            $opportunities = [
                ['ED Boarding Reduction', 'Reduce admit-to-bed time for boarded ED patients via earlier bed requests and discharge-by-noon pull.', 'Emergency / RTDC', 'High', 'In Progress', 88],
                ['First-Case On-Time Starts', 'Standardize the FCOTS checklist and pre-op readiness to raise on-time starts above 85%.', 'Perioperative', 'High', 'Open', 81],
                ['OR Turnover Optimization', 'Parallel-process room turnover to cut median turnover below 28 minutes.', 'Perioperative', 'Medium', 'In Progress', 64],
                ['Discharge-Before-Noon Pull', 'Coordinate rounding + pharmacy + transport to lift the discharge-before-noon rate.', 'RTDC / Nursing', 'High', 'Open', 76],
                ['Transport SLA Compliance', 'Tighten dispatch assignment to reduce avoidable bed-hours from delayed transports.', 'Transport', 'Medium', 'Open', 58],
                ['Ancillary Wait-Time Reduction', 'Smooth imaging and PT/OT scheduling to reduce inpatient ancillary wait times.', 'Ancillary Services', 'Low', 'Open', 41],
            ];

            foreach ($opportunities as [$title, $desc, $dept, $priority, $status, $impact]) {
                DB::table('prod.improvement_opportunities')->updateOrInsert(
                    ['title' => $title],
                    [
                        'description' => $desc,
                        'department' => $dept,
                        'priority' => $priority,
                        'status' => $status,
                        'estimated_impact' => $impact,
                        'is_deleted' => false,
                        'created_by' => 'demo-seeder',
                        'modified_by' => 'demo-seeder',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        if (Schema::hasTable('prod.improvement_resources')) {
            $resources = [
                ['PDSA Cycle Template', 'Standard Plan-Do-Study-Act worksheet for structured improvement cycles.', 'Templates', 'Document', '2026-01-15'],
                ['Root Cause Analysis Guide', 'Fishbone + 5-Whys facilitation guide for RCA sessions.', 'Guides', 'Guide', '2026-02-03'],
                ['SPC Chart Primer', 'How to read and act on statistical process control charts.', 'Guides', 'Guide', '2026-02-20'],
                ['Discharge Process Map', 'Reference OCEL process map for the inpatient discharge workflow.', 'Process Maps', 'Document', '2026-03-11'],
                ['Huddle Facilitation Checklist', 'Daily bed-meeting + unit-huddle facilitation checklist.', 'Templates', 'Document', '2026-04-02'],
                ['Change Management Playbook', 'Kotter-based playbook for sustaining operational change.', 'Guides', 'Guide', '2026-05-09'],
            ];

            foreach ($resources as [$title, $desc, $category, $type, $date]) {
                DB::table('prod.improvement_resources')->updateOrInsert(
                    ['title' => $title],
                    [
                        'description' => $desc,
                        'category' => $category,
                        'type' => $type,
                        'date_added' => $date,
                        'is_deleted' => false,
                        'created_by' => 'demo-seeder',
                        'modified_by' => 'demo-seeder',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
