import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Plus, ArrowRight, CheckCircle, Clock, AlertCircle } from 'lucide-react';

const Active = ({ cycles = [] }) => {
  const getStatusIcon = (status) => {
    switch (status) {
      case 'completed':
        return <CheckCircle className="h-5 w-5 text-green-500" />;
      case 'in-progress':
        return <Clock className="h-5 w-5 text-blue-500" />;
      case 'at-risk':
        return <AlertCircle className="h-5 w-5 text-red-500" />;
      default:
        return null;
    }
  };

  return (
    <DashboardLayout>
      <Head title="Active PDSA Cycles - ZephyrusOR" />
      <PageContentLayout
        title="Active PDSA Cycles"
        subtitle="Track and manage active improvement initiatives"
      >
        <div className="flex justify-end mb-6">
          <Link href="/improvement/active/new">
            <Button className="flex items-center gap-2 bg-healthcare-primary hover:bg-healthcare-primary/90 text-white dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-dark/90">
              <Plus className="h-4 w-4" />
              New PDSA Cycle
            </Button>
          </Link>
        </div>

        {/* Active Cycles Grid */}
        <div className="grid grid-cols-1 gap-6">
          {cycles.map((cycle, index) => (
            <div
              key={index}
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-sm transition-colors duration-300"
            >
              <div className="p-6">
                <div className="flex items-start justify-between mb-4">
                  <div>
                    <div className="flex items-center gap-3">
                      {getStatusIcon(cycle.status)}
                      <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {cycle.title}
                      </h3>
                    </div>
                    <p className="mt-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {cycle.objective}
                    </p>
                  </div>
                  <Link href={`/improvement/active/${cycle.id}`}>
                    <Button variant="outline" size="sm" className="flex items-center gap-2">
                      View Details
                      <ArrowRight className="h-4 w-4" />
                    </Button>
                  </Link>
                </div>

                {/* Progress and Metrics */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                  <div>
                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark block mb-1">
                      Current Phase
                    </span>
                    <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark font-medium">
                      {cycle.currentPhase}
                    </span>
                  </div>
                  <div>
                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark block mb-1">
                      Start Date
                    </span>
                    <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark font-medium">
                      {cycle.startDate}
                    </span>
                  </div>
                  <div>
                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark block mb-1">
                      Target Date
                    </span>
                    <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark font-medium">
                      {cycle.targetDate}
                    </span>
                  </div>
                  <div>
                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark block mb-1">
                      Progress
                    </span>
                    <div className="flex items-center gap-2">
                      <div className="flex-1 h-2 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full overflow-hidden">
                        <div
                          className="h-full bg-healthcare-primary dark:bg-healthcare-primary-dark transition-all duration-300"
                          style={{ width: `${cycle.progress}%` }}
                        />
                      </div>
                      <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark font-medium">
                        {cycle.progress}%
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Empty State */}
        {cycles.length === 0 && (
          <div className="text-center py-12">
            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
              No Active Cycles
            </h3>
            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-6">
              Start your first PDSA cycle to begin tracking improvements
            </p>
            <Link href="/improvement/active/new">
              <Button className="flex items-center gap-2 bg-healthcare-primary hover:bg-healthcare-primary/90 text-white dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-dark/90">
                <Plus className="h-4 w-4" />
                Start First Cycle
              </Button>
            </Link>
          </div>
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Active;
