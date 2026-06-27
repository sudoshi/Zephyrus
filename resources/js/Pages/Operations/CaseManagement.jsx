import React from 'react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import { Head } from '@inertiajs/react';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import CaseTracker from '@/Components/Operations/CaseManagement/CaseTracker';
import { Panel, EmptyState } from '@/Components/system';
import {
  mockProcedures as fallbackProcedures,
  specialties as fallbackSpecialties,
  locations as fallbackLocations,
  stats as fallbackStats,
} from '@/mock-data/case-management';

// Case Management rebuilt on the gold-standard design system: the delay banner
// and the no-cases empty state use the shared Panel / EmptyState primitives.
// The CaseTracker instrument (KPI cards, service/resource status, active
// procedures table, care-journey modal) keeps all its interactive state and
// live-data props (procedures, specialties, locations, stats).

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
        <div className="flex flex-col gap-5">
          {delayedCount > 0 && (
            <Panel className="p-3">
              <div className="flex items-center space-x-2 text-healthcare-error dark:text-healthcare-error-dark">
                <Icon icon="heroicons:exclamation-circle" className="h-4 w-4" />
                <span>
                  {delayedCount} procedures currently showing delays. Resource adjustment recommended.
                </span>
              </div>
            </Panel>
          )}

          {hasProcedures ? (
            <CaseTracker
              procedures={procedureList}
              specialties={specialties}
              locations={locations}
              stats={stats}
            />
          ) : (
            <Panel className="p-4">
              <EmptyState
                message="No surgical cases scheduled for the current operating day."
                icon="heroicons:calendar-days"
              />
            </Panel>
          )}
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
