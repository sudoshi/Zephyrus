// Mock data generator for service huddle
const generateServiceHuddleData = () => {
    // Constants for generating realistic data
    const UNITS = [
        '5 East', '5 West', '6 East', '6 West', '7 East', '7 West',
        '8 East', '8 West', '9 East', '9 West', 'ICU', 'CCU',
    ];
    const SERVICES = [
        'Cardiology', 'Nephrology', 'Neurology', 'Oncology',
        'Internal Medicine', 'Orthopedics', 'Pulmonology', 'General Surgery',
    ];
    const PRIMARY_TEAMS = [
        'CHF Team', 'Stroke Team', 'Medical Team A', 'Medical Team B',
        'Medical Team C', 'Surgical Team A', 'Surgical Team B', 'Oncology Team',
    ];
    const CONSULTING_SERVICES = [
        'Infectious Disease', 'Pain Management', 'Psychiatry', 'Physical Therapy',
        'Occupational Therapy', 'Wound Care', 'Nutrition', 'Social Work',
    ];
    const NURSES = [
        'Sarah Chen, RN', 'Michael Rodriguez, RN', 'Emily Johnson, RN',
        'David Kim, RN', 'Jessica Taylor, RN', 'James Wilson, RN',
        'Maria Garcia, RN', 'Robert Smith, RN', 'Lisa Brown, RN',
        'John Davis, RN', 'Amanda White, RN', 'Kevin Anderson, RN',
    ];
    const DIETS = [
        'Regular Diet', 'Cardiac Diet, 2g Na', 'Renal Diet', 'Clear Liquids',
        'Full Liquids', 'Diabetic Diet', 'Low Fiber Diet', 'NPO',
    ];
    const ACTIVITIES = [
        'Bed Rest', 'Up with assistance', 'Up ad lib',
        'Weight bearing as tolerated', 'Non-weight bearing right leg',
        'Transfer to chair TID', 'Ambulate TID', 'Activity as tolerated',
    ];
    const TASK_TEMPLATES = [
        'Lab draw at TIME', 'Echo scheduled for TIME', 'PT evaluation', 'OT evaluation',
        'Social work consult', 'Nutrition consult', 'Wound care', 'Pain management consult',
        'IV antibiotics due at TIME', 'CT scan at TIME', 'MRI scheduled for TIME',
        'Discharge planning meeting',
    ];

    // Helper functions
    const randomFromArray = (arr) => arr[Math.floor(Math.random() * arr.length)];
    const randomInt = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;
    const generateMRN = () => `MRN${String(randomInt(100000, 999999)).padStart(6, '0')}`;
    const randomBP = () => `${randomInt(90, 180)}/${randomInt(60, 100)}`;

    const generateTasks = (count) => {
        return Array(count)
            .fill(null)
            .map((_, index) => ({
                id: index + 1,
                text: randomFromArray(TASK_TEMPLATES).replace(
                    'TIME',
                    `${randomInt(0, 23).toString().padStart(2, '0')}:${randomInt(0, 59)
                        .toString()
                        .padStart(2, '0')}`
                ),
                completed: Math.random() > 0.7,
            }));
    };

    const generatePatient = (id) => {
        const admitDate = new Date();
        admitDate.setDate(admitDate.getDate() - randomInt(0, 14));

        const status = Math.random() > 0.7 ? 'Critical' : Math.random() > 0.5 ? 'Guarded' : 'Stable';
        const unit = randomFromArray(UNITS);
        const service = randomFromArray(SERVICES);

        return {
            id,
            room: `${unit.split(' ')[0]}-${randomFromArray(['A', 'B'])}`,
            name: `Patient ${id}`,
            mrn: generateMRN(),
            age: randomInt(18, 95),
            admitDate: admitDate.toISOString(),
            unit,
            service,
            status,
            vitalSigns: {
                bp: randomBP(),
                o2sat: `${randomInt(88, 100)}% ${Math.random() > 0.7 ? '2L NC' : 'RA'}`
            },
            primaryTeam: randomFromArray(PRIMARY_TEAMS),
            consultingServices: Array(randomInt(1, 3))
                .fill(null)
                .map(() => randomFromArray(CONSULTING_SERVICES))
                .filter((v, i, a) => a.indexOf(v) === i),
            assignedNurse: randomFromArray(NURSES),
            code: Math.random() > 0.2 ? 'Full Code' : 'DNR/DNI',
            activity: randomFromArray(ACTIVITIES),
            dietaryRestrictions: randomFromArray(DIETS),
            tasks: generateTasks(randomInt(2, 5)),
            dischargePlan: {
                responsiblePerson: randomFromArray(NURSES),
                targetTime: `${randomInt(8, 16).toString().padStart(2, '0')}:00`,
                predictedBy2PM: Math.random() > 0.5 ? 'Yes' : 'No',
                dischargeBarriers: []
            }
        };
    };

    // Generate patients (at least 3 per unit)
    const patients = [];
    UNITS.forEach(unit => {
        const unitPatientCount = randomInt(3, 8); // 3-8 patients per unit
        for (let i = 0; i < unitPatientCount; i++) {
            patients.push(generatePatient(patients.length + 1));
        }
    });

    // Generate metrics
    const metrics = {
        unitMetrics: {
            occupancy: Math.round((patients.length / (UNITS.length * 10)) * 100),
            availableBeds: UNITS.length * 10 - patients.length,
            pendingAdmissions: randomInt(3, 8),
            expectedDischarges: randomInt(5, 12)
        },
        careRequirements: {
            criticalCare: patients.filter(p => p.status === 'Critical').length,
            telemetry: patients.filter(p => p.status === 'Guarded').length,
            isolation: randomInt(3, 8),
            specialEquipment: randomInt(5, 12)
        },
        acuityStatus: {
            critical: patients.filter(p => p.status === 'Critical').length,
            guarded: patients.filter(p => p.status === 'Guarded').length,
            stable: patients.filter(p => p.status === 'Stable').length,
            total: patients.length
        }
    };

    return {
        metrics,
        patients
    };
};

export default generateServiceHuddleData;
