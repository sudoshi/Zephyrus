// Mock data for Real-Time Demand and Capacity features

export const rtdcMetrics = {
    bedStatus: {
        total: 550,
        occupied: 485,
        available: 65,
        cleaning: 15,
        pending: {
            admissions: 28,
            discharges: 35,
            transfers: 12
        },
        byUnit: {
            'Medical': { total: 200, occupied: 185, available: 15 },
            'Surgical': { total: 150, occupied: 132, available: 18 },
            'ICU': { total: 50, occupied: 48, available: 2 },
            'Step-down': { total: 75, occupied: 65, available: 10 },
            'Telemetry': { total: 75, occupied: 55, available: 20 }
        }
    },
    ancillaryServices: {
        radiology: {
            pending: 25,
            inProgress: 8,
            completed: 142,
            avgTurnaround: 45,
            modalities: {
                'CT': { waiting: 5, inProgress: 2 },
                'MRI': { waiting: 8, inProgress: 1 },
                'X-Ray': { waiting: 7, inProgress: 3 },
                'Ultrasound': { waiting: 5, inProgress: 2 }
            }
        },
        laboratory: {
            pending: 56,
            inProgress: 34,
            completed: 378,
            avgTurnaround: 120,
            categories: {
                'STAT': { count: 15, avgTime: 45 },
                'Routine': { count: 41, avgTime: 180 },
                'Critical': { count: 8, avgTime: 30 }
            }
        },
        therapy: {
            pending: 18,
            inProgress: 12,
            completed: 89,
            avgTurnaround: 30,
            types: {
                'PT': { scheduled: 25, completed: 18 },
                'OT': { scheduled: 20, completed: 15 },
                'Speech': { scheduled: 10, completed: 8 }
            }
        }
    },
    discharges: {
        today: {
            predicted: 52,
            completed: 28,
            inProgress: 24,
            barriers: [
                { type: 'Transportation', count: 8 },
                { type: 'Placement', count: 5 },
                { type: 'Social Work', count: 3 },
                { type: 'Pharmacy', count: 1 }
            ]
        },
        byUnit: {
            'Medical': { predicted: 20, completed: 12 },
            'Surgical': { predicted: 15, completed: 10 },
            'ICU': { predicted: 5, completed: 3 },
            'Step-down': { predicted: 7, completed: 2 },
            'Telemetry': { predicted: 5, completed: 1 }
        },
        timeline: [
            { hour: '08:00', predicted: 5, actual: 3 },
            { hour: '10:00', predicted: 8, actual: 5 },
            { hour: '12:00', predicted: 12, actual: 8 },
            { hour: '14:00', predicted: 15, actual: 7 },
            { hour: '16:00', predicted: 8, actual: 5 },
            { hour: '18:00', predicted: 4, actual: null }
        ]
    },
    staffing: {
        current: {
            nurses: { scheduled: 120, present: 115, required: 125 },
            techs: { scheduled: 60, present: 55, required: 65 },
            support: { scheduled: 40, present: 38, required: 45 }
        },
        nextShift: {
            nurses: { scheduled: 110, required: 115 },
            techs: { scheduled: 55, required: 60 },
            support: { scheduled: 35, required: 40 }
        },
        byUnit: {
            'Medical': { nurses: 45, techs: 20, support: 15 },
            'Surgical': { nurses: 35, techs: 15, support: 10 },
            'ICU': { nurses: 20, techs: 10, support: 5 },
            'Step-down': { nurses: 15, techs: 8, support: 5 },
            'Telemetry': { nurses: 15, techs: 7, support: 5 }
        }
    },
    alerts: [
        { id: 1, type: 'critical', message: 'ICU approaching capacity', unit: 'ICU', time: '10 min ago' },
        { id: 2, type: 'warning', message: 'ED boarding 6 patients', unit: 'ED', time: '15 min ago' },
        { id: 3, type: 'info', message: 'Extra staff arriving for evening', unit: 'Staffing', time: '20 min ago' },
        { id: 4, type: 'warning', message: 'High volume in Radiology', unit: 'Radiology', time: '25 min ago' }
    ],
    unitGoals: [
        { id: 1, unit: 'Medical', status: 'on-track', text: 'Complete morning assessments by 10am', progress: 85 },
        { id: 2, unit: 'Surgical', status: 'at-risk', text: 'Update care plans for high acuity patients', progress: 60 },
        { id: 3, unit: 'ICU', status: 'completed', text: 'Medication reconciliation for new admits', progress: 100 },
        { id: 4, unit: 'Step-down', status: 'behind', text: 'Discharge planning documentation', progress: 45 }
    ],
    serviceMetrics: {
        overview: {
            activeConsults: 45,
            pendingOrders: 28,
            completedToday: 156,
            avgResponseTime: 32
        },
        services: [
            {
                name: 'Cardiology',
                stats: {
                    activePatients: 24,
                    newConsults: 8,
                    pendingTests: 12,
                    avgWaitTime: 45
                }
            },
            {
                name: 'Neurology',
                stats: {
                    activePatients: 18,
                    newConsults: 5,
                    pendingTests: 7,
                    avgWaitTime: 60
                }
            },
            {
                name: 'Pulmonology',
                stats: {
                    activePatients: 15,
                    newConsults: 4,
                    pendingTests: 6,
                    avgWaitTime: 30
                }
            }
        ],
        priorities: [
            { id: 1, service: 'Cardiology', patient: 'Room 412', task: 'STAT Echo', priority: 'high', waitTime: 15 },
            { id: 2, service: 'Neurology', patient: 'Room 308', task: 'Stroke Assessment', priority: 'high', waitTime: 10 },
            { id: 3, service: 'Pulmonology', patient: 'Room 215', task: 'Vent Settings', priority: 'medium', waitTime: 25 },
            { id: 4, service: 'Cardiology', patient: 'ED Bay 3', task: 'Consult', priority: 'medium', waitTime: 30 }
        ]
    }
};
