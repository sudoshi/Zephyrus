import React from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { Filter, AlertCircle, Clock, UserCog, Building2, Activity } from 'lucide-react';
import PatientCard from './PatientCard';

const CareStageDistribution = () => {
  const [selectedFilters, setSelectedFilters] = React.useState({
    unit: 'all',
    complexity: 'all',
    careTeam: 'all',
    diagnosis: 'all',
    barrier: 'all',
    destination: 'all'
  });

  // Enhanced mock data with patient details
  const patientData = {
    '24h': [
      {
        name: 'John Smith',
        mrn: 'MRN123456',
        age: 65,
        gender: 'M',
        room: '412A',
        status: 'critical',
        diagnosis: 'COPD Exacerbation',
        expectedDischarge: 'Today 4:00 PM',
        careTeam: ['Dr. Johnson', 'RN Williams'],
        actions: [
          { type: 'orders', description: 'Pending discharge orders', status: 'pending', owner: 'Dr. Johnson' },
          { type: 'consult', description: 'Pulmonology consult', status: 'completed', owner: 'Dr. Chen' },
          { type: 'transport', description: 'Schedule ambulance', status: 'pending', owner: 'Care Coord' }
        ],
        barriers: ['Awaiting insurance authorization', 'Home O2 delivery pending']
      },
      {
        name: 'Mary Johnson',
        mrn: 'MRN789012',
        age: 72,
        gender: 'F',
        room: '415B',
        status: 'warning',
        diagnosis: 'CHF',
        expectedDischarge: 'Today 2:00 PM',
        careTeam: ['Dr. Smith', 'RN Brown'],
        actions: [
          { type: 'orders', description: 'Medication reconciliation', status: 'pending', owner: 'Pharmacy' },
          { type: 'education', description: 'Patient education', status: 'pending', owner: 'RN Brown' }
        ],
        barriers: ['Caregiver training needed']
      }
    ],
    '36h': [
      {
        name: 'Robert Davis',
        mrn: 'MRN345678',
        age: 58,
        gender: 'M',
        room: '420A',
        status: 'warning',
        diagnosis: 'Post-op CABG',
        expectedDischarge: 'Tomorrow 11:00 AM',
        careTeam: ['Dr. Wilson', 'RN Garcia'],
        actions: [
          { type: 'consult', description: 'Cardiac rehab evaluation', status: 'pending', owner: 'PT/OT' },
          { type: 'homecare', description: 'Home care setup', status: 'pending', owner: 'Care Coord' }
        ],
        barriers: ['Awaiting PT clearance']
      }
    ],
    '48h': [
      {
        name: 'Patricia Brown',
        mrn: 'MRN901234',
        age: 81,
        gender: 'F',
        room: '425B',
        status: 'success',
        diagnosis: 'Hip Replacement',
        expectedDischarge: 'In 2 days',
        careTeam: ['Dr. Anderson', 'RN Martinez'],
        actions: [
          { type: 'consult', description: 'PT/OT evaluation', status: 'completed', owner: 'PT/OT' },
          { type: 'homecare', description: 'SNF placement', status: 'pending', owner: 'Care Coord' }
        ],
        barriers: []
      }
    ],
    'discharge': [
      {
        name: 'James Wilson',
        mrn: 'MRN567890',
        age: 45,
        gender: 'M',
        room: '430A',
        status: 'success',
        diagnosis: 'Pneumonia',
        expectedDischarge: 'Today 3:00 PM',
        careTeam: ['Dr. Taylor', 'RN Thompson'],
        actions: [
          { type: 'orders', description: 'Discharge orders signed', status: 'completed', owner: 'Dr. Taylor' },
          { type: 'education', description: 'Medication teaching', status: 'completed', owner: 'RN Thompson' },
          { type: 'transport', description: 'Family pickup arranged', status: 'completed', owner: 'Care Coord' }
        ],
        barriers: []
      }
    ],
    'home': [
      {
        name: 'Susan Miller',
        mrn: 'MRN234567',
        age: 68,
        gender: 'F',
        room: 'H@H',
        status: 'warning',
        diagnosis: 'COVID-19 Recovery',
        expectedDischarge: 'Monitoring',
        careTeam: ['Dr. Lee', 'RN White'],
        actions: [
          { type: 'consult', description: 'Telehealth check', status: 'pending', owner: 'Dr. Lee' },
          { type: 'homecare', description: 'Vital monitoring', status: 'completed', owner: 'RN White' }
        ],
        barriers: ['SpO2 trending down']
      }
    ]
  };

  const filterOptions = {
    unit: [
      { value: 'all', label: 'All Units' },
      { value: 'medical', label: 'Medical' },
      { value: 'surgical', label: 'Surgical' },
      { value: 'icu', label: 'ICU' }
    ],
    complexity: [
      { value: 'all', label: 'All Complexity' },
      { value: 'low', label: 'Low' },
      { value: 'medium', label: 'Medium' },
      { value: 'high', label: 'High' }
    ],
    careTeam: [
      { value: 'all', label: 'All Teams' },
      { value: 'team1', label: 'Team A' },
      { value: 'team2', label: 'Team B' },
      { value: 'team3', label: 'Team C' }
    ],
    diagnosis: [
      { value: 'all', label: 'All Diagnoses' },
      { value: 'cardiac', label: 'Cardiac' },
      { value: 'respiratory', label: 'Respiratory' },
      { value: 'orthopedic', label: 'Orthopedic' }
    ],
    barrier: [
      { value: 'all', label: 'All Barriers' },
      { value: 'insurance', label: 'Insurance' },
      { value: 'placement', label: 'Placement' },
      { value: 'clinical', label: 'Clinical' }
    ],
    destination: [
      { value: 'all', label: 'All Destinations' },
      { value: 'home', label: 'Home' },
      { value: 'snf', label: 'SNF' },
      { value: 'rehab', label: 'Rehab' }
    ]
  };

  // Transform data for the chart
  const chartData = [
    { stage: 'Inpatient (24h)', count: patientData['24h'].length },
    { stage: 'Inpatient (36h)', count: patientData['36h'].length },
    { stage: 'Inpatient (48h)', count: patientData['48h'].length },
    { stage: 'Care after Discharge', count: patientData['discharge'].length },
    { stage: 'Hospital@Home', count: patientData['home'].length }
  ];

  const CustomTooltip = ({ active, payload, label }) => {
    if (!active || !payload || !payload.length) return null;

    return (
      <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-lg shadow-lg p-3">
        <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
          {label}
        </p>
        <div className="flex items-center gap-2">
          <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Patients:
          </span>
          <span className="text-sm font-medium">
            {payload[0].value}
          </span>
        </div>
      </div>
    );
  };

  return (
    <div className="space-y-6">
      {/* Filters */}
      <div className="flex flex-wrap gap-4 items-center">
        <div className="flex items-center gap-2">
          <Filter className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
          <span className="text-sm font-medium">Filters:</span>
        </div>
        {Object.entries(filterOptions).map(([key, options]) => (
          <select
            key={key}
            value={selectedFilters[key]}
            onChange={(e) => setSelectedFilters(prev => ({ ...prev, [key]: e.target.value }))}
            className="rounded-md border border-healthcare-border bg-healthcare-surface text-healthcare-text-primary px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-healthcare-primary"
          >
            {options.map(option => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        ))}
      </div>

      {/* Summary Stats */}
      <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
          <div className="flex items-center gap-2 mb-2">
            <AlertCircle className="h-5 w-5 text-healthcare-critical" />
            <h3 className="text-sm font-medium">Critical Actions</h3>
          </div>
          <p className="text-2xl font-semibold">12</p>
        </div>
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
          <div className="flex items-center gap-2 mb-2">
            <Clock className="h-5 w-5 text-healthcare-warning" />
            <h3 className="text-sm font-medium">Pending Discharges</h3>
          </div>
          <p className="text-2xl font-semibold">8</p>
        </div>
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
          <div className="flex items-center gap-2 mb-2">
            <Building2 className="h-5 w-5 text-healthcare-primary" />
            <h3 className="text-sm font-medium">SNF Placements</h3>
          </div>
          <p className="text-2xl font-semibold">5</p>
        </div>
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
          <div className="flex items-center gap-2 mb-2">
            <Activity className="h-5 w-5 text-healthcare-success" />
            <h3 className="text-sm font-medium">Ready for Discharge</h3>
          </div>
          <p className="text-2xl font-semibold">3</p>
        </div>
      </div>

      {/* Chart */}
      <div className="h-[300px]">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={chartData} barSize={40}>
            <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.1)" />
            <XAxis 
              dataKey="stage" 
              stroke="currentColor"
              tickLine={false}
              interval={0}
              tick={{ fontSize: 12 }}
              height={60}
              angle={-45}
              textAnchor="end"
            />
            <YAxis
              stroke="currentColor"
              tickLine={false}
              label={{ value: 'Patient Count', angle: -90, position: 'insideLeft' }}
            />
            <Tooltip content={<CustomTooltip />} />
            <Bar
              dataKey="count"
              fill="var(--healthcare-primary)"
              radius={[4, 4, 0, 0]}
            />
          </BarChart>
        </ResponsiveContainer>
      </div>

      {/* Patient Lists */}
      <div className="space-y-6">
        {Object.entries(patientData).map(([stage, patients]) => (
          <div key={stage} className="space-y-4">
            <h3 className="text-lg font-semibold flex items-center gap-2">
              {stage === '24h' && 'Inpatient (24h)'}
              {stage === '36h' && 'Inpatient (36h)'}
              {stage === '48h' && 'Inpatient (48h)'}
              {stage === 'discharge' && 'Care after Discharge'}
              {stage === 'home' && 'Hospital@Home'}
              <span className="text-sm font-normal text-healthcare-text-secondary">
                ({patients.length} patients)
              </span>
            </h3>
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
              {patients.map((patient, index) => (
                <PatientCard key={index} patient={patient} />
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

export default CareStageDistribution;
