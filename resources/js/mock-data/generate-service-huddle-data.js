// Mock data generator for service huddle
import {
    UNITS,
    SERVICES,
    PRIMARY_TEAMS,
    CONSULTING_SERVICES,
    NURSES,
    DIETS,
    ACTIVITIES,
    TASK_TEMPLATES
} from './rtdc-service-huddle-constants';

const generateServiceHuddleData = () => {
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
            tasks: generateTasks(randomInt(2, 5)).map(task => ({
                ...task,
                category: randomFromArray(['Clinical', 'Ancillary', 'Administrative']),
                priority: randomFromArray(['High', 'Medium', 'Low']),
                assignedTo: randomFromArray(NURSES),
                dueDate: (() => {
                    const date = new Date();
                    date.setHours(date.getHours() + randomInt(1, 24));
                    return date.toISOString();
                })()
            })),
            careJourney: {
                mobility: randomFromArray(['Ambulatory', 'With Assistance', 'Bed Rest']),
                careLevel: randomFromArray(['Routine', 'Intermediate', 'Critical']),
                phase: randomFromArray(['Assessment', 'Treatment', 'Recovery'])
            },
            clinicalStatus: {
                vitalTrend: randomFromArray(['Improving', 'Stable', 'Worsening']),
                painLevel: randomInt(0, 10),
                lastAssessment: new Date().toISOString(),
                vitalsHistory: Array(24).fill(null).map((_, i) => {
                    const time = new Date();
                    time.setHours(time.getHours() - i);
                    return {
                        time: time.toISOString(),
                        bp: randomBP(),
                        hr: randomInt(60, 100),
                        rr: randomInt(12, 20),
                        o2sat: randomInt(92, 100),
                        temp: (36 + Math.random()).toFixed(1)
                    };
                }),
                isolationStatus: Math.random() > 0.8 ? randomFromArray(['Contact', 'Droplet', 'Airborne']) : null,
                medicationAdministration: Array(5).fill(null).map(() => ({
                    medication: randomFromArray(['Acetaminophen', 'Morphine', 'Ceftriaxone', 'Ondansetron', 'Insulin']),
                    dose: `${randomInt(1, 10)}${randomFromArray(['mg', 'g', 'mcg', 'units'])}`,
                    route: randomFromArray(['PO', 'IV', 'IM', 'SC']),
                    time: new Date(Date.now() - randomInt(0, 24) * 60 * 60 * 1000).toISOString()
                }))
            },
            dischargeRequirements: {
                clinicalCriteria: {
                    vitalsSatisfied: Math.random() > 0.5,
                    medicationReconciled: Math.random() > 0.5,
                    followUpScheduled: Math.random() > 0.5
                },
                transportation: {
                    arranged: Math.random() > 0.5,
                    type: randomFromArray(['Family', 'Medical Transport', 'Ambulance', 'Pending']),
                    notes: ''
                },
                instructions: {
                    medicationReviewed: Math.random() > 0.5,
                    dietaryReviewed: Math.random() > 0.5,
                    followUpReviewed: Math.random() > 0.5
                }
            },
            carePlan: {
                goals: Array(3).fill(null).map(() => ({
                    id: Date.now() + randomInt(1, 1000),
                    description: randomFromArray([
                        'Ambulate 3x daily',
                        'Independent ADLs',
                        'Pain controlled with oral meds',
                        'Wound healing without signs of infection',
                        'Blood glucose within target range'
                    ]),
                    status: randomFromArray(['Not Started', 'In Progress', 'Achieved', 'Modified']),
                    target: new Date(Date.now() + randomInt(1, 5) * 24 * 60 * 60 * 1000).toISOString()
                })),
                interventions: Array(2).fill(null).map(() => ({
                    type: randomFromArray(['Procedure', 'Therapy', 'Consultation']),
                    name: randomFromArray([
                        'Physical Therapy Evaluation',
                        'Central Line Placement',
                        'Wound Care',
                        'Nutrition Consultation',
                        'Respiratory Treatment'
                    ]),
                    status: randomFromArray(['Scheduled', 'Completed', 'Pending']),
                    scheduledTime: new Date(Date.now() + randomInt(-12, 36) * 60 * 60 * 1000).toISOString()
                })),
                preferences: {
                    diet: randomFromArray(['Regular', 'Cardiac', 'Diabetic', 'Renal', 'NPO']),
                    mobility: randomFromArray(['Independent', 'With Assistance', 'Bedrest']),
                    communication: randomFromArray(['English', 'Spanish', 'Requires Interpreter'])
                }
            },
            teamCommunication: Array(3).fill(null).map(() => ({
                id: Date.now() + randomInt(1, 1000),
                author: randomFromArray(NURSES),
                message: randomFromArray([
                    'Patient reports improved pain control',
                    'Family meeting scheduled for tomorrow',
                    'New medication started',
                    'Physical therapy evaluation completed',
                    'Discharge planning in progress'
                ]),
                timestamp: new Date(Date.now() - randomInt(0, 48) * 60 * 60 * 1000).toISOString(),
                category: randomFromArray(['Clinical', 'Care Coordination', 'Family Communication'])
            })),
            dischargePlan: {
                responsiblePerson: randomFromArray(NURSES),
                targetTime: `${randomInt(8, 16).toString().padStart(2, '0')}:00`,
                predictedBy2PM: Math.random() > 0.5 ? 'Yes' : 'No',
                dischargeBarriers: [],
                estimatedDischargeDate: (() => {
                    const discharge = new Date(admitDate);
                    discharge.setDate(discharge.getDate() + randomInt(3, 21));
                    return discharge.toISOString();
                })(),
                journeyMilestones: (() => {
                    const targetTime = `${randomInt(8, 16).toString().padStart(2, '0')}:00`;
                    const discharge = new Date(admitDate);
                    discharge.setDate(discharge.getDate() + randomInt(3, 21));
                    return [
                    {
                        id: 1,
                        type: 'admission',
                        title: 'Initial Admission',
                        time: new Date(admitDate).toLocaleTimeString(),
                        date: new Date(admitDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
                        description: randomFromArray([
                            'Admitted via ED with SOB',
                            'Direct admission from clinic',
                            'Transfer from outside facility',
                            'Elective admission'
                        ]),
                        isAlert: Math.random() > 0.8
                    },
                    ...Array(randomInt(2, 4)).fill(null).map((_, idx) => {
                        const milestoneDate = new Date(admitDate);
                        milestoneDate.setDate(milestoneDate.getDate() + idx + 1);
                        return {
                            id: idx + 2,
                            type: 'milestone',
                            title: randomFromArray([
                                'Initial Treatment',
                                'Care Planning',
                                'Physical Therapy',
                                'Diagnostic Imaging',
                                'Specialist Consult',
                                'Family Meeting'
                            ]),
                            time: `${randomInt(8, 17)}:${randomInt(0, 59).toString().padStart(2, '0')}`,
                            date: milestoneDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
                            description: randomFromArray([
                                'Treatment initiated',
                                'Care plan established',
                                'Evaluation completed',
                                'Results reviewed',
                                'Meeting conducted'
                            ])
                        };
                    }),
                    {
                        id: 99,
                        type: 'discharge',
                        title: 'Anticipated Discharge',
                        time: targetTime,
                        date: discharge.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
                        description: randomFromArray([
                            'Expected discharge to home',
                            'Transfer to rehabilitation',
                            'Discharge to skilled nursing',
                            'Home health arranged'
                        ]),
                        isAnticipated: true
                    }
                    ];
                })(),
                postDischargeNeeds: {
                    followUp: Array(2).fill(null).map(() => ({
                        specialty: randomFromArray(SERVICES),
                        timeframe: randomFromArray(['24 hours', '3 days', '1 week', '2 weeks']),
                        scheduled: Math.random() > 0.5
                    })),
                    equipment: Array(Math.random() > 0.7 ? randomInt(1, 3) : 0).fill(null).map(() => 
                        randomFromArray(['Wheelchair', 'Walker', 'Oxygen', 'Hospital Bed', 'CPAP'])
                    ),
                    services: Array(Math.random() > 0.6 ? randomInt(1, 3) : 0).fill(null).map(() =>
                        randomFromArray(['Home Health', 'Physical Therapy', 'Occupational Therapy', 'Wound Care'])
                    )
                },
                medicationReconciliation: {
                    status: randomFromArray(['Not Started', 'In Progress', 'Completed']),
                    completedBy: null,
                    completedAt: null
                },
                alternativePathways: {
                    hospitalAtHome: {
                        isEligible: Math.random() > 0.7,
                        hasConsented: Math.random() > 0.5,
                        eligibilityNotes: '',
                        assessedBy: randomFromArray(NURSES),
                        assessedAt: new Date(Date.now() - randomInt(0, 48) * 60 * 60 * 1000).toISOString()
                    },
                    cadArena: {
                        isEligible: Math.random() > 0.6,
                        hasConsented: Math.random() > 0.5,
                        eligibilityNotes: '',
                        assessedBy: randomFromArray(NURSES),
                        assessedAt: new Date(Date.now() - randomInt(0, 48) * 60 * 60 * 1000).toISOString(),
                        preferredUnit: Math.random() > 0.5 ? randomFromArray(UNITS) : null
                    }
                },
                caregiverInfo: Math.random() > 0.3 ? {
                    name: `Family Member ${randomInt(1, 100)}`,
                    relationship: randomFromArray(['Spouse', 'Child', 'Sibling', 'Parent']),
                    phone: `(${randomInt(100, 999)}) ${randomInt(100, 999)}-${randomInt(1000, 9999)}`,
                    educated: Math.random() > 0.5
                } : null
            },
            statusUpdates: []
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
