export const alertsData = {
    active: [
        {
            id: 1,
            type: 'critical',
            message: 'Medical-Surgical critical staffing shortage (-28 staff)',
            unit: 'Medical-Surgical',
            time: '10 min ago',
            details: {
                impact: 'Immediate impact on patient care',
                requiredStaff: 228,
                currentStaff: 200,
                nextShiftCoverage: '85%',
                actions: [
                    'Activate float pool',
                    'Review non-urgent procedures',
                    'Contact agency staffing'
                ]
            }
        },
        {
            id: 2,
            type: 'critical',
            message: 'ICU at 94% capacity - 3 beds remaining',
            unit: 'ICU',
            time: '15 min ago',
            details: {
                impact: 'Limited critical care capacity',
                pendingTransfers: 5,
                source: 'ED',
                expectedDischarges: 2,
                actions: [
                    'Expedite pending discharges',
                    'Review transfer criteria',
                    'Activate surge protocol'
                ]
            }
        },
        {
            id: 3,
            type: 'critical',
            message: 'ED boarding critical - 8 patients pending beds',
            unit: 'Emergency',
            time: '20 min ago',
            details: {
                impact: 'Extended patient wait times',
                averageWait: '4.5 hours',
                boardingTime: '6+ hours',
                affectedAreas: ['ICU', 'Medical-Surgical'],
                actions: [
                    'Initiate bed huddle',
                    'Accelerate discharges',
                    'Review admission criteria'
                ]
            }
        },
        {
            id: 4,
            type: 'warning',
            message: 'Obstetrics staffing gap for upcoming shift (-5 staff)',
            unit: 'Obstetrics',
            time: '25 min ago',
            details: {
                impact: 'Potential care delays',
                expectedDeliveries: 6,
                currentCensus: 28,
                staffingLevel: '82%',
                actions: [
                    'Contact on-call staff',
                    'Review patient assignments',
                    'Adjust elective procedures'
                ]
            }
        },
        {
            id: 5,
            type: 'warning',
            message: 'Radiology experiencing 45-minute average delay',
            unit: 'Radiology',
            time: '30 min ago',
            details: {
                impact: 'ED throughput affected',
                pendingScans: 12,
                staffingStatus: 'Limited',
                peakTime: true,
                actions: [
                    'Prioritize ED/ICU cases',
                    'Call in additional tech',
                    'Notify affected units'
                ]
            }
        },
        {
            id: 6,
            type: 'warning',
            message: 'Equipment maintenance required - 2 ventilators',
            unit: 'Respiratory',
            time: '45 min ago',
            details: {
                impact: 'Reduced equipment availability',
                maintenanceWindow: '2 hours',
                backupStatus: 'Available',
                priority: 'Scheduled',
                actions: [
                    'Schedule maintenance window',
                    'Verify backup equipment',
                    'Notify affected units'
                ]
            }
        },
        {
            id: 7,
            type: 'info',
            message: 'Float pool staff arriving - 6 nurses at 1500',
            unit: 'Staffing',
            time: '1 hour ago',
            details: {
                impact: 'Increased coverage',
                assignedAreas: ['Medical-Surgical', 'ICU'],
                shiftDuration: '12 hours',
                status: 'Confirmed',
                actions: [
                    'Update unit assignments',
                    'Brief incoming staff',
                    'Adjust care plans'
                ]
            }
        },
        {
            id: 8,
            type: 'info',
            message: 'Bed turnover time improved by 15%',
            unit: 'Environmental',
            time: '1.5 hours ago',
            details: {
                impact: 'Improved patient flow',
                currentAverage: '42 minutes',
                previousAverage: '49 minutes',
                methodology: 'New process implementation',
                actions: [
                    'Monitor sustainability',
                    'Share best practices',
                    'Recognize team performance'
                ]
            }
        }
    ],
    statistics: {
        byPriority: {
            high: 3,
            medium: 3,
            low: 2
        },
        byUnit: {
            'Medical-Surgical': 1,
            'ICU': 1,
            'Emergency': 1,
            'Obstetrics': 1,
            'Radiology': 1,
            'Respiratory': 1,
            'Staffing': 1,
            'Environmental': 1
        },
        trend: {
            lastHour: 8,
            previousHour: 5,
            change: 'up'
        }
    }
};
