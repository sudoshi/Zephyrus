import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import { Head } from '@inertiajs/react';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import CaseTracker from '@/Components/Operations/CaseManagement/CaseTracker';
import CaseAnalytics from '@/Components/Operations/CaseManagement/CaseAnalytics';
import { Alert, AlertDescription } from '@/Components/ui/Alert';

export default function CaseManagement({ procedures, specialties, locations, stats, analyticsData }) {
  const [view, setView] = useState('tracker');
  const delayedCount = procedures.filter(proc => proc.resourceStatus === "Delayed").length;

  return (
    <DashboardLayout>
      <Head title="Case Management - ZephyrusOR" />
      <PageContentLayout
        title="Case Management"
        subtitle="Monitor and manage surgical cases in real-time"
      >
        <div className="py-6">
          <div className="mx-auto max-w-7xl">
            <div className="mb-6 flex items-center justify-between">
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

            {delayedCount > 0 && (
              <Alert className="mb-4 bg-healthcare-error-light dark:bg-healthcare-error-dark/20 border-healthcare-error dark:border-healthcare-error-dark">
                <Icon icon="heroicons:exclamation-circle" className="h-4 w-4 text-healthcare-error dark:text-healthcare-error-dark" />
                <AlertDescription className="text-healthcare-error dark:text-healthcare-error-dark">
                  {delayedCount} procedures currently showing delays. Resource adjustment recommended.
                </AlertDescription>
              </Alert>
            )}

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
          </div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
