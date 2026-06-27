import React from 'react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import { Head } from '@inertiajs/react';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import CaseTracker from '@/Components/Operations/CaseManagement/CaseTracker';
import Card from '@/Components/Dashboard/Card';
import {
  mockProcedures as fallbackProcedures,
  specialties as fallbackSpecialties,
  locations as fallbackLocations,
  stats as fallbackStats,
} from '@/mock-data/case-management';

export default function CaseManagement({
  procedures = fallbackProcedures,
  specialties = fallbackSpecialties,
  locations = fallbackLocations,
  stats = fallbackStats,
}) {
  const procedureList = Array.isArray(procedures) ? procedures : [];
  const hasProcedures = procedureList.length > 0;
  const delayedCount = procedureList.filter(proc => proc.resourceStatus === "Delayed").length;

  return (
    <DashboardLayout>
      <Head title="Case Management - ZephyrusOR" />
      <PageContentLayout
        title="Case Management"
        subtitle="Monitor and manage surgical cases in real-time"
      >
        <div className="space-y-6">
          {delayedCount > 0 && (
            <Card>
              <Card.Content>
                <div className="flex items-center space-x-2 text-healthcare-error dark:text-healthcare-error-dark">
                  <Icon icon="heroicons:exclamation-circle" className="h-4 w-4" />
                  <span>
                    {delayedCount} procedures currently showing delays. Resource adjustment recommended.
                  </span>
                </div>
              </Card.Content>
            </Card>
          )}

          {hasProcedures ? (
            <CaseTracker
              procedures={procedureList}
              specialties={specialties}
              locations={locations}
              stats={stats}
            />
          ) : (
            <Card>
              <Card.Content>
                <div className="flex flex-col items-center justify-center py-12 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  <Icon icon="heroicons:calendar-days" className="h-8 w-8 mb-2" />
                  <span>No surgical cases scheduled for the current operating day.</span>
                </div>
              </Card.Content>
            </Card>
          )}
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
