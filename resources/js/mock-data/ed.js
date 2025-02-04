// Mock data for Emergency Department features

export const edMetrics = {
    currentStatus: {
        totalPatients: 35,
        capacity: 45,
        occupancy: 78,
        waitingRoom: 12,
        averageWaitTime: 42,
        criticalCases: 3,
    },
    triageCategories: {
        resuscitation: {
            count: 1,
            maxWaitTime: 0,
            targetTime: 'Immediate',
        },
        emergent: {
            count: 4,
            maxWaitTime: 15,
            targetTime: '15 minutes',
        },
        urgent: {
            count: 12,
            maxWaitTime: 30,
            targetTime: '30 minutes',
        },
        semiUrgent: {
            count: 10,
            maxWaitTime: 60,
            targetTime: '60 minutes',
        },
        nonUrgent: {
            count: 8,
            maxWaitTime: 120,
            targetTime: '120 minutes',
        },
    },
    throughput: {
        lastHour: {
            arrivals: 6,
            discharges: 4,
            admissions: 2,
            leftWithoutBeingSeen: 1,
        },
        today: {
            arrivals: 85,
            discharges: 72,
            admissions: 15,
            leftWithoutBeingSeen: 3,
        },
    },
    staffing: {
        current: {
            physicians: {
                scheduled: 4,
                present: 4,
                required: 4,
            },
            nurses: {
                scheduled: 12,
                present: 11,
                required: 12,
            },
            techs: {
                scheduled: 6,
                present: 5,
                required: 6,
            },
        },
        nextShift: {
            physicians: {
                scheduled: 3,
                required: 3,
            },
            nurses: {
                scheduled: 10,
                required: 10,
            },
            techs: {
                scheduled: 5,
                required: 5,
            },
        },
    },
    waitTimes: {
        current: {
            doorToTriage: 12,
            doorToProvider: 45,
            doorToDisposition: 180,
            doorToDeparture: 240,
        },
        targets: {
            doorToTriage: 10,
            doorToProvider: 30,
            doorToDisposition: 150,
            doorToDeparture: 180,
        },
        trends: [
            { hour: '00:00', waitTime: 35 },
            { hour: '04:00', waitTime: 25 },
            { hour: '08:00', waitTime: 42 },
            { hour: '12:00', waitTime: 55 },
            { hour: '16:00', waitTime: 48 },
            { hour: '20:00', waitTime: 38 },
        ],
    },
    resources: {
        beds: {
            total: 45,
            occupied: 35,
            cleaning: 2,
            available: 8,
            categories: {
                trauma: { total: 4, available: 1 },
                acute: { total: 25, available: 4 },
                fastTrack: { total: 8, available: 2 },
                behavioral: { total: 4, available: 1 },
                isolation: { total: 4, available: 0 },
            },
        },
        equipment: {
            ventilators: { total: 6, inUse: 2 },
            monitors: { total: 45, inUse: 35 },
            portableXray: { total: 2, inUse: 1 },
        },
    },
    predictions: {
        arrivals: [
            { hour: '21:00', predicted: 8, actual: null },
            { hour: '22:00', predicted: 6, actual: null },
            { hour: '23:00', predicted: 5, actual: null },
            { hour: '00:00', predicted: 4, actual: null },
        ],
        admissions: {
            probability: 0.35,
            predictedCount: 5,
            byService: {
                medical: 3,
                surgical: 1,
                icu: 1,
            },
        },
        bottlenecks: [
            {
                resource: 'CT Scanner',
                probability: 0.8,
                timeframe: 'Next 2 hours',
                impact: 'high',
            },
            {
                resource: 'Behavioral Health Beds',
                probability: 0.7,
                timeframe: 'Next 4 hours',
                impact: 'medium',
            },
        ],
    },
};

// Export alertsData separately
export const alertsData = {
    alerts: [
        {
            id: 1,
            type: 'critical',
            title: 'High Volume Alert',
            message: 'ED approaching capacity, activate surge protocols',
            timestamp: '2024-02-03T20:30:00',
        },
        {
            id: 2,
            type: 'warning',
            title: 'Wait Time Alert',
            message: 'Door to provider times exceeding targets',
            timestamp: '2024-02-03T20:15:00',
        },
        {
            id: 3,
            type: 'info',
            title: 'Staffing Update',
            message: 'Additional nurse coming for evening shift',
            timestamp: '2024-02-03T20:00:00',
        },
    ],
};

export const patientStatusBoard = [
    {
        id: 'P001',
        location: 'Trauma 1',
        chiefComplaint: 'Chest Pain',
        triageLevel: 2,
        waitTime: 5,
        status: 'active',
        nextAction: 'Awaiting Labs',
        provider: 'Dr. Smith',
    },
    {
        id: 'P002',
        location: 'Acute 3',
        chiefComplaint: 'Abdominal Pain',
        triageLevel: 3,
        waitTime: 45,
        status: 'active',
        nextAction: 'Pending CT',
        provider: 'Dr. Johnson',
    },
    {
        id: 'P003',
        location: 'Fast Track 2',
        chiefComplaint: 'Laceration',
        triageLevel: 4,
        waitTime: 30,
        status: 'active',
        nextAction: 'Awaiting Sutures',
        provider: 'Dr. Brown',
    },
];

export const performanceMetrics = {
    doorToProvider: {
        current: 45,
        target: 30,
        trend: 'up',
        trendValue: 5,
    },
    lengthOfStay: {
        admitted: {
            current: 320,
            target: 240,
            trend: 'up',
            trendValue: 15,
        },
        discharged: {
            current: 180,
            target: 160,
            trend: 'down',
            trendValue: 10,
        },
    },
    leftWithoutBeingSeen: {
        current: 2.5,
        target: 2.0,
        trend: 'up',
        trendValue: 0.3,
    },
    patientSatisfaction: {
        current: 85,
        target: 90,
        trend: 'down',
        trendValue: 2,
    },
};
