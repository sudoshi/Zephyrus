// Constants for generating realistic data.
// Unit display strings are sourced from the Summit Regional roster
// (config/hospital/hospital-1.php → resources/js/constants/summitHospital.js).
// The huddle generator derives a room prefix via `unit.split(' ')[0]`, so each
// string keeps a leading floor/number token (e.g. '5 West …' → '5').
export const UNITS = [
    '3 West — Medical ICU (MICU)', '3 North — Cardiovascular ICU (CVICU)',
    '4 West — Medical/Surgical', '4 East — Medical/Surgical',
    '5 West — Medical/Surgical', '5 East — Medical/Surgical',
    '6 West — Medical/Surgical', '6 East — Medical/Surgical',
    '7 East — Telemetry & Cardiology', '7 West — Telemetry & Stepdown',
    '10 — Oncology / Hematology', '11 — Acute Inpatient Rehabilitation',
];

export const SERVICES = [
    'Cardiology', 'Nephrology', 'Neurology', 'Oncology',
    'Internal Medicine', 'Orthopedics', 'Pulmonology', 'General Surgery',
];

export const PRIMARY_TEAMS = [
    'CHF Team', 'Stroke Team', 'Medical Team A', 'Medical Team B',
    'Medical Team C', 'Surgical Team A', 'Surgical Team B', 'Oncology Team',
];

export const CONSULTING_SERVICES = [
    'Infectious Disease', 'Pain Management', 'Psychiatry', 'Physical Therapy',
    'Occupational Therapy', 'Wound Care', 'Nutrition', 'Social Work',
];

export const NURSES = [
    'Sarah Chen, RN', 'Michael Rodriguez, RN', 'Emily Johnson, RN',
    'David Kim, RN', 'Jessica Taylor, RN', 'James Wilson, RN',
    'Maria Garcia, RN', 'Robert Smith, RN', 'Lisa Brown, RN',
    'John Davis, RN', 'Amanda White, RN', 'Kevin Anderson, RN',
];

export const DIETS = [
    'Regular Diet', 'Cardiac Diet, 2g Na', 'Renal Diet', 'Clear Liquids',
    'Full Liquids', 'Diabetic Diet', 'Low Fiber Diet', 'NPO',
];

export const ACTIVITIES = [
    'Bed Rest', 'Up with assistance', 'Up ad lib',
    'Weight bearing as tolerated', 'Non-weight bearing right leg',
    'Transfer to chair TID', 'Ambulate TID', 'Activity as tolerated',
];

export const TASK_TEMPLATES = [
    'Lab draw at TIME', 'Echo scheduled for TIME', 'PT evaluation', 'OT evaluation',
    'Social work consult', 'Nutrition consult', 'Wound care', 'Pain management consult',
    'IV antibiotics due at TIME', 'CT scan at TIME', 'MRI scheduled for TIME',
    'Discharge planning meeting',
];
