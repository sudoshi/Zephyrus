// Updated mock data for Real-Time Demand and Capacity features

export const censusData = {
    total: {
        currentCensus: 485,
        totalBeds: 550,
        occupancy: 88,
        trend: 'up',
        trendValue: 3,
        availableBeds: 65,
        predictedCensus: 495,
        predictedTrend: 'up',
        bedTypes: {
            icu: { total: 50, occupied: 48, pending: 4 },
            medSurg: { total: 200, occupied: 185, pending: 10 },
            telemetry: { total: 75, occupied: 55, pending: 3 },
            pediatric: { total: 40, occupied: 35, pending: 2 },
            maternity: { total: 30, occupied: 25, pending: 1 }
        }
    },
    weeklyTrend: [
        { date: '2025-01-28', value: 82, predicted: 83 },
        { date: '2025-01-29', value: 84, predicted: 84 },
        { date: '2025-01-30', value: 85, predicted: 85 },
        { date: '2025-01-31', value: 87, predicted: 86 },
        { date: '2025-02-01', value: 86, predicted: 87 },
        { date: '2025-02-02', value: 88, predicted: 88 },
        { date: '2025-02-03', value: 89, predicted: 89 },
    ],
    hourlyTrend: [
        { hour: '07:00', admissions: 3, discharges: 1, census: 480 },
        { hour: '08:00', admissions: 4, discharges: 2, census: 482 },
        { hour: '09:00', admissions: 5, discharges: 3, census: 484 },
        { hour: '10:00', admissions: 3, discharges: 2, census: 485 },
        { hour: '11:00', admissions: 4, discharges: 4, census: 485 },
        { hour: '12:00', admissions: 6, discharges: 5, census: 486 },
        { hour: '13:00', admissions: 4, discharges: 3, census: 487 },
        { hour: '14:00', admissions: 3, discharges: 4, census: 486 },
    ]
};

export const departmentData = {
    emergency: {
        name: 'Emergency',
        occupancy: 95,
        status: 'critical',
        boardingPatients: 6,
        staffingLevel: 92,
        pendingAdmissions: 8,
        pendingDischarges: 5,
        waitTime: 45,
        acuity: {
            level1: 2,
            level2: 5,
            level3: 12,
            level4: 8,
            level5: 4
        },
        staffing: {
            required: 30,
            current: 28,
            incoming: 4,
            outgoing: 2
        }
    },
    icu: {
        name: 'ICU',
        occupancy: 96,
        status: 'warning',
        occupiedBeds: 48,
        totalBeds: 50,
        staffingLevel: 90,
        pendingAdmissions: 4,
        pendingDischarges: 3,
        acuity: {
            high: 20,
            medium: 18,
            low: 10
        },
        staffing: {
            required: 45,
            current: 42,
            incoming: 5,
            outgoing: 3
        }
    },
    medical: {
        name: 'Medical',
        occupancy: 92,
        status: 'normal',
        occupiedBeds: 185,
        totalBeds: 200,
        staffingLevel: 96,
        pendingAdmissions: 10,
        pendingDischarges: 8,
        acuity: {
            high: 45,
            medium: 95,
            low: 45
        },
        staffing: {
            required: 80,
            current: 76,
            incoming: 8,
            outgoing: 6
        }
    },
    surgical: {
        name: 'Surgical',
        occupancy: 88,
        status: 'normal',
        occupiedBeds: 132,
        totalBeds: 150,
        staffingLevel: 94,
        pendingAdmissions: 6,
        pendingDischarges: 7,
        acuity: {
            high: 35,
            medium: 67,
            low: 30
        },
        staffing: {
            required: 65,
            current: 62,
            incoming: 6,
            outgoing: 4
        }
    },
    telemetry: {
        name: 'Telemetry',
        occupancy: 73,
        status: 'normal',
        occupiedBeds: 55,
        totalBeds: 75,
        staffingLevel: 98,
        pendingAdmissions: 3,
        pendingDischarges: 5,
        acuity: {
            high: 15,
            medium: 25,
            low: 15
        },
        staffing: {
            required: 35,
            current: 34,
            incoming: 3,
            outgoing: 2
        }
    },
    maternity: {
        name: 'Maternity',
        occupancy: 83,
        status: 'normal',
        occupiedBeds: 25,
        totalBeds: 30,
        staffingLevel: 95,
        pendingAdmissions: 2,
        pendingDischarges: 3,
        acuity: {
            high: 5,
            medium: 15,
            low: 5
        },
        staffing: {
            required: 25,
            current: 24,
            incoming: 2,
            outgoing: 1
        }
    }
};

export const staffingData = {
    currentShift: {
        present: 208,
        required: 235,
        coverage: 88,
        skillMix: {
            rn: { required: 150, present: 135 },
            lpn: { required: 45, present: 40 },
            cna: { required: 40, present: 33 }
        },
        byDepartment: {
            emergency: { required: 30, present: 28 },
            icu: { required: 45, present: 42 },
            medical: { required: 80, present: 76 },
            surgical: { required: 65, present: 62 },
            telemetry: { required: 35, present: 34 }
        }
    },
    nextShift: {
        scheduled: 220,
        required: 235,
        predicted: 225,
        skillMix: {
            rn: { scheduled: 140, required: 150 },
            lpn: { scheduled: 42, required: 45 },
            cna: { scheduled: 38, required: 40 }
        }
    },
    trends: {
        daily: [
            { date: '2025-02-01', coverage: 92 },
            { date: '2025-02-02', coverage: 90 },
            { date: '2025-02-03', coverage: 88 },
            { date: '2025-02-04', coverage: 91 },
            { date: '2025-02-05', coverage: 88 }
        ]
    }
};

export const alertsData = {
    active: [
        {
            id: 1,
            title: 'ICU approaching capacity',
            priority: 'high',
            category: 'capacity',
            description: 'The ICU is nearing full capacity with 96% occupancy.',
            time: '10 min ago',
            department: 'ICU',
            status: 'unacknowledged',
            actions: ['View ICU Status', 'Contact Charge RN', 'Review Transfers']
        },
        {
            id: 2,
            title: 'ED boarding patients',
            priority: 'medium',
            category: 'flow',
            description: 'There are 6 patients boarding in the ED awaiting beds.',
            time: '15 min ago',
            department: 'Emergency',
            status: 'in_progress',
            actions: ['View ED Status', 'Review Bed Availability', 'Contact Bed Management']
        },
        {
            id: 3,
            title: 'High volume in Radiology',
            priority: 'medium',
            category: 'service',
            description: 'Radiology department experiencing high demand.',
            time: '20 min ago',
            department: 'Radiology',
            status: 'acknowledged',
            actions: ['View Radiology Queue', 'Check Staffing', 'Review Schedule']
        },
        {
            id: 4,
            title: 'Extra staff arriving',
            priority: 'info',
            category: 'staffing',
            description: 'Additional staff scheduled for the evening shift.',
            time: '30 min ago',
            department: 'Hospital-wide',
            status: 'resolved',
            actions: ['View Staffing Schedule', 'Update Assignments']
        }
    ],
    statistics: {
        total: 12,
        byPriority: {
            high: 2,
            medium: 6,
            low: 4
        },
        byCategory: {
            capacity: 3,
            flow: 4,
            staffing: 2,
            service: 3
        },
        byStatus: {
            unacknowledged: 2,
            in_progress: 5,
            acknowledged: 3,
            resolved: 2
        }
    },
    trends: {
        hourly: [
            { hour: '07:00', count: 8 },
            { hour: '08:00', count: 10 },
            { hour: '09:00', count: 12 },
            { hour: '10:00', count: 11 },
            { hour: '11:00', count: 9 },
            { hour: '12:00', count: 12 },
            { hour: '13:00', count: 10 },
            { hour: '14:00', count: 12 }
        ]
    }
};
