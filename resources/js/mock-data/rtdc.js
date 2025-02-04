// Updated mock data for Real-Time Demand and Capacity features

export const censusData = {
    total: {
        currentCensus: 485,
        totalBeds: 550,
        occupancy: 88,
        trend: 'up',
        trendValue: 3,
        availableBeds: 65,
    },
    weeklyTrend: [
        { date: '2025-01-28', value: 82 },
        { date: '2025-01-29', value: 84 },
        { date: '2025-01-30', value: 85 },
        { date: '2025-01-31', value: 87 },
        { date: '2025-02-01', value: 86 },
        { date: '2025-02-02', value: 88 },
        { date: '2025-02-03', value: 89 },
    ],
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
    },
};

export const staffingData = {
    currentShift: {
        present: 208,
        required: 235,
        coverage: 88,
    },
    nextShift: {
        scheduled: 200,
        required: 220,
    },
};

export const alertsData = {
    active: [
        {
            title: 'ICU approaching capacity',
            priority: 'high',
            description: 'The ICU is nearing full capacity with 96% occupancy.',
            time: '10 min ago',
        },
        {
            title: 'ED boarding patients',
            priority: 'medium',
            description: 'There are 6 patients boarding in the ED awaiting beds.',
            time: '15 min ago',
        },
        {
            title: 'High volume in Radiology',
            priority: 'medium',
            description: 'Radiology department experiencing high demand.',
            time: '20 min ago',
        },
        {
            title: 'Extra staff arriving',
            priority: 'info',
            description: 'Additional staff scheduled for the evening shift.',
            time: '30 min ago',
        },
    ],
};
