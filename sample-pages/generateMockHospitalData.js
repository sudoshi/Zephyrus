// File: resources/js/Pages/GlobalHuddle/generateMockHospitalData.js

// Mock data generator for hospital units
const generateMockHospitalData = () => {
    // Constants for generating realistic data
    const UNITS = [
      '5 East', '5 West', '6 East', '6 West', '7 East', '7 West',
      '8 East', '8 West', '9 East', '9 West', 'ICU', 'CCU',
    ];
    const SERVICES = [
      'Cardiology', 'Nephrology', 'Neurology', 'Oncology',
      'Internal Medicine', 'Orthopedics', 'Pulmonology', 'General Surgery',
    ];
    const ANCILLARY_SERVICES = [
      'Physical Therapy', 'Occupational Therapy', 'Speech Therapy',
      'Case Management', 'Social Work', 'Respiratory Therapy',
      'Nutrition Services', 'Pharmacy', 'Transportation',
      'Home Health', 'DME', 'Wound Care',
    ];
    const INSURANCE_TYPES = [
      'Medicare', 'Medicaid', 'Commercial', 'Self-Pay',
      'Workers Comp', 'Medicare Advantage', 'Managed Care',
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
    const ISOLATION_TYPES = [
      'None', 'Contact', 'Droplet', 'Airborne',
      'Contact + Droplet', 'Enhanced Contact',
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
    const randomBP = () => `${randomInt(90, 180)}/${randomInt(60, 100)}`;
    const randomTemp = () => (Math.random() * (39.0 - 36.0) + 36.0).toFixed(1);
    const generateMRN = () => `MRN${String(randomInt(100000, 999999)).padStart(6, '0')}`;
  
    const generateVitalSigns = () => ({
      bp: randomBP(),
      hr: randomInt(60, 120).toString(),
      rr: randomInt(12, 24).toString(),
      temp: randomTemp(),
      o2sat: `${randomInt(88, 100)}% ${Math.random() > 0.7 ? '2L NC' : 'RA'}`,
    });
  
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
  
      // Generate some initials from fake name
      const initials = `${String.fromCharCode(65 + Math.floor(Math.random() * 26))}${String.fromCharCode(65 + Math.floor(Math.random() * 26))}`;
  
      // Discharge plan
      const dischargePlan = {
        responsiblePerson: randomFromArray(NURSES),
        targetDate: new Date(new Date().setDate(new Date().getDate() + randomInt(0, 5))),
        targetTime: `${randomInt(8, 16).toString().padStart(2, '0')}:00`,
        predictedBy2PM: Math.random() > 0.5 ? 'Yes' : 'No',
        actuallyDischargedBy2PM: null,
        dischargeBarriers: Array(randomInt(1, 4))
          .fill(null)
          .map(() => ({
            id: randomInt(1000, 9999),
            description: randomFromArray([
              'Pending medical clearance',
              'Awaiting PT/OT clearance',
              'Insurance authorization pending',
              'Transportation not arranged',
            ]),
            severity: randomFromArray(['low', 'medium', 'high']),
            category: randomFromArray([
              'clinical',
              'administrative',
              'social',
              'financial',
              'placement',
            ]),
            tags: Array(randomInt(1, 3))
              .fill(null)
              .map(() =>
                randomFromArray([
                  'medication',
                  'equipment',
                  'insurance',
                  'family',
                  'transport',
                  'placement',
                  'therapy',
                  'documentation',
                ])
              ),
            timestamp: new Date(
              Date.now() - randomInt(0, 7) * 24 * 60 * 60 * 1000
            ).toISOString(),
            status: randomFromArray(['active', 'inProgress', 'resolved']),
            assignedTeam: randomFromArray([
              'Case Management',
              'Social Work',
              'Nursing',
              'Physical Therapy',
              'Pharmacy',
            ]),
            escalationLevel: randomInt(0, 2),
            lastEscalated: null,
            resolutionNotes: '',
            resolutionDate: null,
            updatesHistory: Array(randomInt(0, 3))
              .fill(null)
              .map(() => ({
                timestamp: new Date(
                  Date.now() - randomInt(0, 3) * 24 * 60 * 60 * 1000
                ).toISOString(),
                status: randomFromArray(['active', 'inProgress']),
                notes: 'Update note',
                updatedBy: randomFromArray(NURSES),
              })),
          })),
        requiredServices: {
          primary: randomFromArray(ANCILLARY_SERVICES),
          additional1: Math.random() > 0.5 ? randomFromArray(ANCILLARY_SERVICES) : null,
          additional2: Math.random() > 0.3 ? randomFromArray(ANCILLARY_SERVICES) : null,
        },
        specificTasks: [
          {
            task: 'Complete discharge paperwork',
            assignedTo: randomFromArray(NURSES),
            targetTime: `${randomInt(8, 16).toString().padStart(2, '0')}:00`,
          },
        ],
      };
  
      // Adjust the admit date
      admitDate.setDate(admitDate.getDate() - randomInt(0, 14));
  
      const los = Math.floor((Date.now() - admitDate) / (1000 * 60 * 60 * 24));
  
      const expectedDC = new Date();
      expectedDC.setDate(expectedDC.getDate() + randomInt(1, 7));
  
      // Random status
      const status = Math.random() > 0.7 ? 'Critical' : Math.random() > 0.5 ? 'Guarded' : 'Stable';
  
      return {
        id,
        room: `${randomInt(5, 9)}${randomInt(0, 2)}${randomInt(1, 9)}-${randomFromArray(['A', 'B'])}`,
        name: `Patient ${id}`,
        mrn: generateMRN(),
        age: randomInt(18, 95),
        admitDate: admitDate.toISOString(),
        service: randomFromArray(SERVICES),
        primaryTeam: randomFromArray(PRIMARY_TEAMS),
        consultingServices: Array(randomInt(0, 3))
          .fill(null)
          .map(() => randomFromArray(CONSULTING_SERVICES))
          .filter((v, i, a) => a.indexOf(v) === i),
        unit: randomFromArray(UNITS),
        status,
        los,
        expectedDC: expectedDC.toISOString(),
        dcDestination: Math.random() > 0.7 ? 'Home with Services' : 'Home',
        code: Math.random() > 0.2 ? 'Full Code' : 'DNR/DNI',
        dietaryRestrictions: randomFromArray(DIETS),
        activity: randomFromArray(ACTIVITIES),
        vitalSigns: generateVitalSigns(),
        isolation: randomFromArray(ISOLATION_TYPES),
        notes: `Patient note ${id}`,
        tasks: generateTasks(randomInt(1, 5)),
        nursingRatio: status === 'Critical' ? '2:1' : '4:1',
        assignedNurse: randomFromArray(NURSES),
        lastUpdate: new Date().toISOString(),
        initials,
        insurance: randomFromArray(INSURANCE_TYPES),
        dischargePlan,
      };
    };
  
    // Generate 406 patients
    const patients = Array(406)
      .fill(null)
      .map((_, index) => generatePatient(index + 1));
  
    // Generate unit statistics
    const unitStats = UNITS.map((unit) => {
      const unitPatients = patients.filter((p) => p.unit === unit);
      return {
        unit,
        totalBeds: 20,
        occupiedBeds: unitPatients.length,
        avgLOS:
          Math.round(
            (unitPatients.reduce((acc, p) => acc + p.los, 0) / unitPatients.length) * 10
          ) / 10,
        isolationRooms: unitPatients.filter((p) => p.isolation !== 'None').length,
        expectedDischarges: unitPatients.filter(
          (p) => new Date(p.expectedDC).toDateString() === new Date().toDateString()
        ).length,
        nursingStaff: randomInt(8, 12),
        telemetryPatients: unitPatients.filter((p) => p.status !== 'Stable').length,
      };
    });
  
    return {
      patients,
      unitStats,
      lastUpdated: new Date().toISOString(),
    };
  };
  
  export default generateMockHospitalData;
