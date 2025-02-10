import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

const Overview = ({ stats = {} }) => {
  return (
    <DashboardLayout>
      <Head title="Improvement Overview - ZephyrusOR" />
      <PageContentLayout
        title="Improvement Overview"
        subtitle="Overview of all improvement initiatives and their progress"
      >
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {/* Summary Statistics */}
          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg p-6 shadow-sm transition-colors duration-300">
            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4 transition-colors duration-300">
              Summary Statistics
            </h3>
            <div className="space-y-4">
              <div className="flex justify-between items-center">
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Total Initiatives
                </span>
                <span className="text-xl font-semibold text-healthcare-primary dark:text-healthcare-primary-dark">
                  {stats.total || 0}
                </span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Active Cycles
                </span>
                <span className="text-xl font-semibold text-healthcare-primary dark:text-healthcare-primary-dark">
                  {stats.activePDSA || 0}
                </span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Completed Cycles
                </span>
                <span className="text-xl font-semibold text-healthcare-primary dark:text-healthcare-primary-dark">
                  {stats.completedPDSA || 0}
                </span>
              </div>
            </div>
          </div>

          {/* Recent Activity */}
          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg p-6 shadow-sm transition-colors duration-300">
            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4 transition-colors duration-300">
              Recent Activity
            </h3>
            {/* Add activity feed here */}
          </div>

          {/* Performance Metrics */}
          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg p-6 shadow-sm transition-colors duration-300">
            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4 transition-colors duration-300">
              Performance Metrics
            </h3>
            {/* Add metrics visualization here */}
          </div>
        </div>

        {/* Timeline */}
        <div className="mt-8 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg p-6 shadow-sm transition-colors duration-300">
          <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4 transition-colors duration-300">
            Initiative Timeline
          </h3>
          {/* Add timeline visualization here */}
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Overview;
