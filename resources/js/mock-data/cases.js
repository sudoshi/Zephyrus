export const mockCases = [
  {
    case_id: 1,
    patient_name: 'John Smith',
    mrn: '123456789',
    procedure_name: 'Total Knee Arthroplasty',
    service_id: 2,
    service_name: 'Orthopedics',
    room_id: 1,
    room_name: 'OR-1',
    primary_surgeon_id: 1,
    surgeon_name: 'Dr. Sarah Johnson',
    surgery_date: '2025-01-31',
    scheduled_start_time: '2025-01-31T07:30:00',
    estimated_duration: 180, // in minutes
    actual_start_time: '2025-01-31T07:35:00',
    actual_end_time: null,
    case_class: 'Elective',
    asa_rating: 'ASA II',
    case_type: 'Primary',
    patient_class: 'Inpatient',
    status: 'In Progress',
    notes: 'Patient has history of hypertension'
  },
  {
    case_id: 2,
    patient_name: 'Mary Johnson',
    mrn: '987654321',
    procedure_name: 'Laparoscopic Cholecystectomy',
    service_id: 1,
    service_name: 'General Surgery',
    room_id: 2,
    room_name: 'OR-2',
    primary_surgeon_id: 2,
    surgeon_name: 'Dr. Michael Smith',
    surgery_date: '2025-01-31',
    scheduled_start_time: '2025-01-31T08:00:00',
    estimated_duration: 120,
    actual_start_time: '2025-01-31T08:10:00',
    actual_end_time: null,
    case_class: 'Elective',
    asa_rating: 'ASA I',
    case_type: 'Primary',
    patient_class: 'Outpatient',
    status: 'In Progress',
    notes: 'No significant medical history'
  },
  {
    case_id: 3,
    patient_name: 'Robert Wilson',
    mrn: '456789123',
    procedure_name: 'Spinal Fusion',
    service_id: 4,
    service_name: 'Neurosurgery',
    room_id: 3,
    room_name: 'OR-3',
    primary_surgeon_id: 3,
    surgeon_name: 'Dr. James Wilson',
    surgery_date: '2025-01-31',
    scheduled_start_time: '2025-01-31T10:30:00',
    estimated_duration: 240,
    actual_start_time: null,
    actual_end_time: null,
    case_class: 'Elective',
    asa_rating: 'ASA III',
    case_type: 'Primary',
    patient_class: 'Inpatient',
    status: 'Scheduled',
    notes: 'Patient has diabetes'
  },
  {
    case_id: 4,
    patient_name: 'Patricia Brown',
    mrn: '789123456',
    procedure_name: 'Coronary Artery Bypass',
    service_id: 3,
    service_name: 'Cardiology',
    room_id: 4,
    room_name: 'OR-4',
    primary_surgeon_id: 4,
    surgeon_name: 'Dr. Emily Brown',
    surgery_date: '2025-01-31',
    scheduled_start_time: '2025-01-31T11:00:00',
    estimated_duration: 300,
    actual_start_time: null,
    actual_end_time: null,
    case_class: 'Urgent',
    asa_rating: 'ASA IV',
    case_type: 'Primary',
    patient_class: 'Inpatient',
    status: 'Scheduled',
    notes: 'High-risk patient'
  }
];

export const mockReferenceData = {
  caseClasses: [
    { value: 'Elective', label: 'Elective' },
    { value: 'Urgent', label: 'Urgent' },
    { value: 'Emergency', label: 'Emergency' }
  ],
  asaRatings: [
    { value: 'ASA I', label: 'ASA I - Healthy' },
    { value: 'ASA II', label: 'ASA II - Mild Systemic Disease' },
    { value: 'ASA III', label: 'ASA III - Severe Systemic Disease' },
    { value: 'ASA IV', label: 'ASA IV - Life-threatening Disease' },
    { value: 'ASA V', label: 'ASA V - Moribund' }
  ],
  caseTypes: [
    { value: 'Primary', label: 'Primary' },
    { value: 'Revision', label: 'Revision' },
    { value: 'Staged', label: 'Staged' }
  ],
  patientClasses: [
    { value: 'Inpatient', label: 'Inpatient' },
    { value: 'Outpatient', label: 'Outpatient' },
    { value: 'Same Day', label: 'Same Day' }
  ],
  statuses: [
    { value: 'Scheduled', label: 'Scheduled' },
    { value: 'In Progress', label: 'In Progress' },
    { value: 'Completed', label: 'Completed' },
    { value: 'Delayed', label: 'Delayed' },
    { value: 'Cancelled', label: 'Cancelled' }
  ]
};
