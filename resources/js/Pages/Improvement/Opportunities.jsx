import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Plus } from 'lucide-react';

const Opportunities = ({ opportunities = [] }) => {
  return (
    <DashboardLayout>
      <Head title="Improvement Opportunities - ZephyrusOR" />
      <PageContentLayout
        title="Improvement Opportunities"
        subtitle="Review and prioritize improvement opportunities"
      >
        <div className="flex justify-end mb-6">
          <Button className="flex items-center gap-2 bg-healthcare-primary hover:bg-healthcare-primary/90 text-white dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-dark/90">
            <Plus className="h-4 w-4" />
            New Opportunity
          </Button>
        </div>

        {/* Opportunities Grid */}
        <div className="grid grid-cols-1 gap-6">
          {opportunities.map((opportunity, index) => (
            <div
              key={index}
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg p-6 shadow-sm transition-colors duration-300"
            >
              <div className="flex items-start justify-between">
                <div>
                  <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2 transition-colors duration-300">
                    {opportunity.title}
                  </h3>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4 transition-colors duration-300">
                    {opportunity.description}
                  </p>
                  <div className="flex gap-4 text-sm">
                    <div>
                      <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Department:
                      </span>{' '}
                      <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {opportunity.department}
                      </span>
                    </div>
                    <div>
                      <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Priority:
                      </span>{' '}
                      <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {opportunity.priority}
                      </span>
                    </div>
                    <div>
                      <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Status:
                      </span>{' '}
                      <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {opportunity.status}
                      </span>
                    </div>
                  </div>
                </div>
                <div className="flex gap-2">
                  <Button variant="outline" size="sm">
                    Edit
                  </Button>
                  <Button variant="outline" size="sm">
                    Start PDSA
                  </Button>
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Empty State */}
        {opportunities.length === 0 && (
          <div className="text-center py-12">
            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
              No Opportunities Yet
            </h3>
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-6">
              Create your first improvement opportunity to get started
            </p>
            <Button className="flex items-center gap-2 bg-healthcare-primary hover:bg-healthcare-primary/90 text-white dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-dark/90">
              <Plus className="h-4 w-4" />
              Create First Opportunity
            </Button>
          </div>
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Opportunities;
