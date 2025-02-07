export const capacityData = {
    summary: {
        totalBeds: 180,
        occupied: 149,
        available: 31,
        occupancyRate: 83,
        pendingTotal: 22,
        expectedDischarges: {
            today: 8,
            by2PM: 5,
            delayed: 2
        }
    },
    bedTypes: {
        'Medical-Surgical': {
            id: 'med-surg',
            total: 50,
            occupied: 35,
            available: 15,
            occupancyRate: 70,
            pending: {
                routine: 3,
                urgent: 0,
                critical: 0
            },
            expectedDischarges: 3,
            trend: 'stable',
            status: 'normal',
            details: {
                lastUpdated: '13:45',
                nextDischarge: '14:30',
                notes: 'Regular turnover rate'
            }
        },
        'ICU': {
            id: 'icu',
            total: 40,
            occupied: 37,
            available: 3,
            occupancyRate: 93,
            pending: {
                routine: 0,
                urgent: 2,
                critical: 3
            },
            expectedDischarges: 2,
            trend: 'increasing',
            status: 'critical',
            details: {
                lastUpdated: '13:50',
                nextDischarge: '15:00',
                notes: 'High acuity patients, limited capacity'
            }
        },
        'Emergency': {
            id: 'ed',
            total: 30,
            occupied: 28,
            available: 2,
            occupancyRate: 93,
            pending: {
                routine: 0,
                urgent: 4,
                critical: 6
            },
            expectedDischarges: 0,
            trend: 'increasing',
            status: 'critical',
            details: {
                lastUpdated: '13:55',
                boardingTime: '6.5 hours',
                notes: 'High boarding times, multiple critical patients'
            }
        },
        'Telemetry': {
            id: 'tele',
            total: 25,
            occupied: 22,
            available: 3,
            occupancyRate: 88,
            pending: {
                routine: 1,
                urgent: 1,
                critical: 0
            },
            expectedDischarges: 2,
            trend: 'stable',
            status: 'warning',
            details: {
                lastUpdated: '13:40',
                nextDischarge: '16:00',
                notes: 'Monitoring bed availability'
            }
        },
        'Pediatrics': {
            id: 'peds',
            total: 20,
            occupied: 15,
            available: 5,
            occupancyRate: 75,
            pending: {
                routine: 1,
                urgent: 0,
                critical: 0
            },
            expectedDischarges: 1,
            trend: 'decreasing',
            status: 'normal',
            details: {
                lastUpdated: '13:30',
                nextDischarge: '14:00',
                notes: 'Adequate capacity'
            }
        },
        'Obstetrics': {
            id: 'ob',
            total: 15,
            occupied: 12,
            available: 3,
            occupancyRate: 80,
            pending: {
                routine: 1,
                urgent: 0,
                critical: 0
            },
            expectedDischarges: 2,
            trend: 'stable',
            status: 'warning',
            details: {
                lastUpdated: '13:35',
                nextDischarge: '15:30',
                notes: 'Monitoring for incoming deliveries'
            }
        }
    },
    pendingRequests: {
        critical: 5,
        edHolds: 10,
        routine: 3
    },
    trends: {
        hourly: [
            { time: '08:00', occupancy: 78 },
            { time: '09:00', occupancy: 80 },
            { time: '10:00', occupancy: 82 },
            { time: '11:00', occupancy: 85 },
            { time: '12:00', occupancy: 83 },
            { time: '13:00', occupancy: 83 }
        ],
        discharges: {
            expected: [
                { time: '14:00', count: 3 },
                { time: '15:00', count: 2 },
                { time: '16:00', count: 3 }
            ],
            completed: [
                { time: '08:00', count: 1 },
                { time: '09:00', count: 2 },
                { time: '10:00', count: 1 },
                { time: '11:00', count: 2 },
                { time: '12:00', count: 2 },
                { time: '13:00', count: 0 }
            ]
        }
    },
    bedRequests: [
        {
            id: 'req-1',
            patient: 'Thompson, Emma',
            age: 82,
            service: 'Medicine',
            currentLocation: 'ED',
            requestTime: '06:30',
            priority: 'P2',
            status: '> 8 hours',
            details: 'Requiring 2L O2'
        },
        {
            id: 'req-2',
            patient: 'Brown, Michael',
            age: 55,
            service: 'Cardiology',
            currentLocation: 'ED',
            requestTime: '07:45',
            priority: 'P2',
            status: '4-8 hours',
            details: 'Serial troponins pending'
        },
        {
            id: 'req-3',
            patient: 'Davis, Patricia',
            age: 91,
            service: 'Medicine',
            currentLocation: 'ED',
            requestTime: '08:15',
            priority: 'P3',
            status: '4-8 hours',
            details: 'Fall risk, requires sitter'
        },
        {
            id: 'req-4',
            patient: 'Garcia, Maria',
            age: 72,
            service: 'Medicine',
            currentLocation: 'ED',
            requestTime: '08:30',
            priority: 'P2',
            status: 'Pending',
            details: 'Home O2, requires cardiac monitoring'
        },
        {
            id: 'req-5',
            patient: 'White, Thomas',
            age: 67,
            service: 'Critical Care',
            currentLocation: 'ED',
            requestTime: '09:00',
            priority: 'P1',
            status: '> 8 hours',
            details: 'On BiPAP'
        }
    ]
};
