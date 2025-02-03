import React from 'react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import { Head } from '@inertiajs/react';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import CaseTracker from '@/Components/Operations/CaseManagement/CaseTracker';
import Card from '@/Components/Dashboard/Card';

export default function CaseManagement({ procedures, specialties, locations, stats }) {
  const delayedCount = procedures.filter(proc => proc.resourceStatus === "Delayed").length;

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

          <CaseTracker
            procedures={procedures}
            specialties={specialties}
            locations={locations}
            stats={stats}
          />
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
