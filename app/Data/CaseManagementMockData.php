<?php

namespace App\Data;

class CaseManagementMockData
{
    public static function getData()
    {
        return [
            'mockProcedures' => self::getMockProcedures(),
            'specialties' => [
                'General Surgery' => ['color' => 'blue', 'count' => 8, 'onTime' => 7, 'delayed' => 1],
                'Orthopedics' => ['color' => 'green', 'count' => 6, 'onTime' => 5, 'delayed' => 1],
                'OBGYN' => ['color' => 'pink', 'count' => 5, 'onTime' => 4, 'delayed' => 1],
                'Cardiac' => ['color' => 'red', 'count' => 4, 'onTime' => 3, 'delayed' => 1],
                'Cath Lab' => ['color' => 'yellow', 'count' => 5, 'onTime' => 4, 'delayed' => 1],
            ],
            'locations' => [
                'Main OR' => ['total' => 8, 'inUse' => 6],
                'Cath Lab' => ['total' => 3, 'inUse' => 2],
                'L&D' => ['total' => 2, 'inUse' => 2],
                'Pre-Op' => ['total' => 6, 'inUse' => 4],
            ],
            'stats' => [
                'totalPatients' => 28,
                'inProgress' => 12,
                'delayed' => 4,
                'completed' => 8,
                'preOp' => 4,
            ],
            'analyticsData' => [
                ['month' => 'Jan 23', 'cases' => 391, 'avgDuration' => 93, 'totalTime' => 38000],
                ['month' => 'Mar 23', 'cases' => 374, 'avgDuration' => 101, 'totalTime' => 44000],
                ['month' => 'May 23', 'cases' => 463, 'avgDuration' => 94, 'totalTime' => 39000],
                ['month' => 'Jul 23', 'cases' => 413, 'avgDuration' => 94, 'totalTime' => 40000],
                ['month' => 'Sep 23', 'cases' => 406, 'avgDuration' => 93, 'totalTime' => 39000],
                ['month' => 'Nov 23', 'cases' => 406, 'avgDuration' => 93, 'totalTime' => 40000],
                ['month' => 'Jan 24', 'cases' => 427, 'avgDuration' => 95, 'totalTime' => 43000],
                ['month' => 'Mar 24', 'cases' => 427, 'avgDuration' => 95, 'totalTime' => 35000],
                ['month' => 'May 24', 'cases' => 408, 'avgDuration' => 93, 'totalTime' => 38000],
            ],
        ];
    }

    private static function getMockProcedures()
    {
        $jsContent = file_get_contents(resource_path('js/mock-data/case-management.js'));
        
        // Extract the mockProcedures array from the JS file
        if (preg_match('/export const mockProcedures = (\[.*?\]);/s', $jsContent, $matches)) {
            $jsonStr = $matches[1];
            // Convert JS object syntax to JSON
            $jsonStr = preg_replace("/([{,])\s*([a-zA-Z0-9_]+)\s*:/", '$1"$2":', $jsonStr);
            $procedures = json_decode($jsonStr, true);
            if ($procedures) {
                return $procedures;
            }
        }
        
        // Fallback to minimal set if parsing fails
        return [
            [
                'id' => 1,
                'patient' => 'Johnson, M',
                'type' => 'Laparoscopic Cholecystectomy',
                'specialty' => 'General Surgery',
                'status' => 'In Progress',
                'phase' => 'Procedure',
                'location' => 'OR 3',
                'startTime' => '07:30',
                'expectedDuration' => 90,
                'provider' => 'Dr. Smith',
                'resourceStatus' => 'On Time',
                'journey' => 60,
                'staff' => [
                    ['name' => 'Dr. Smith', 'role' => 'Surgeon'],
                    ['name' => 'Dr. Jones', 'role' => 'Anesthesiologist'],
                    ['name' => 'Nurse Johnson', 'role' => 'Scrub Nurse']
                ],
                'resources' => [
                    ['name' => 'OR 3', 'status' => 'onTime'],
                    ['name' => 'Anesthesia Machine', 'status' => 'onTime'],
                    ['name' => 'Laparoscopic Tower', 'status' => 'onTime']
                ]
            ]
        ];
    }
}
