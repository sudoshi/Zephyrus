import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import CaseTracker from '@/Components/Operations/CaseManagement/CaseTracker';
import CaseAnalytics from '@/Components/Operations/CaseManagement/CaseAnalytics';

// Sample data - Replace with API calls in production
const specialties = {
  "General Surgery": { color: "info", count: 8, onTime: 7, delayed: 1 },
  "Orthopedics": { color: "success", count: 6, onTime: 5, delayed: 1 },
  "OBGYN": { color: "warning", count: 5, onTime: 4, delayed: 1 },
  "Cardiac": { color: "error", count: 4, onTime: 3, delayed: 1 },
  "Cath Lab": { color: "primary", count: 5, onTime: 4, delayed: 1 },
};

const locations = {
  "Main OR": { total: 8, inUse: 6 },
  "Cath Lab": { total: 3, inUse: 2 },
  "L&D": { total: 2, inUse: 2 },
  "Pre-Op": { total: 6, inUse: 4 },
};

const stats = {
  totalPatients: 28,
  inProgress: 12,
  delayed: 4,
  completed: 8,
  preOp: 4,
};

const analyticsData = [
  { month: 'Jan 23', cases: 391, avgDuration: 93, totalTime: 38000 },
  { month: 'Mar 23', cases: 374, avgDuration: 101, totalTime: 44000 },
  { month: 'May 23', cases: 463, avgDuration: 94, totalTime: 39000 },
  { month: 'Jul 23', cases: 413, avgDuration: 94, totalTime: 40000 },
  { month: 'Sep 23', cases: 406, avgDuration: 93, totalTime: 39000 },
  { month: 'Nov 23', cases: 406, avgDuration: 93, totalTime: 40000 },
  { month: 'Jan 24', cases: 427, avgDuration: 95, totalTime: 43000 },
  { month: 'Mar 24', cases: 427, avgDuration: 95, totalTime: 35000 },
  { month: 'May 24', cases: 408, avgDuration: 93, totalTime: 38000 }
];

const procedures = [
  {
    id: 1,
    patient: "Johnson, M",
    type: "Laparoscopic Cholecystectomy",
    specialty: "General Surgery",
    status: "In Progress",
    phase: "Procedure",
    location: "OR 3",
    startTime: "07:30",
    expectedDuration: 90,
    provider: "Dr. Smith",
    resourceStatus: "On Time",
    journey: 60,
    staff: [
      { name: "Dr. Smith", role: "Surgeon" },
      { name: "Dr. Jones", role: "Anesthesiologist" },
      { name: "Nurse Johnson", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 3", status: "onTime" },
      { name: "Anesthesia Machine", status: "onTime" },
      { name: "Laparoscopic Tower", status: "onTime" }
    ]
  },
  // Add more procedures here...
];

export default function CaseManagement() {
  const [view, setView] = useState('tracker'); // 'tracker' or 'analytics'

  return (
    <AuthenticatedLayout>
      <Head title="Case Management" />

      <PageContentLayout>
        <div className="mb-6 flex items-center justify-between">
          <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Case Management
          </h1>
          <div className="flex items-center space-x-4">
            <button
              onClick={() => setView('tracker')}
              className={`px-4 py-2 rounded-lg flex items-center space-x-2 ${
                view === 'tracker'
                  ? 'bg-healthcare-primary text-white dark:bg-healthcare-primary-dark'
                  : 'bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
              }`}
            >
              <Icon icon="heroicons:list-bullet" className="w-5 h-5" />
              <span>Tracker</span>
            </button>
            <button
              onClick={() => setView('analytics')}
              className={`px-4 py-2 rounded-lg flex items-center space-x-2 ${
                view === 'analytics'
                  ? 'bg-healthcare-primary text-white dark:bg-healthcare-primary-dark'
                  : 'bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
              }`}
            >
              <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
              <span>Analytics</span>
            </button>
          </div>
        </div>

        {view === 'tracker' ? (
          <CaseTracker
            procedures={procedures}
            specialties={specialties}
            locations={locations}
            stats={stats}
          />
        ) : (
          <CaseAnalytics data={analyticsData} />
        )}
      </PageContentLayout>
    </AuthenticatedLayout>
  );
}
