import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head, Link } from '@inertiajs/react';
import { Plus, LayoutDashboard, Target, Library, RefreshCcw } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import ImprovementCard from '@/Components/Dashboard/ImprovementCard';

const Improvement = ({ auth, stats = {}, cycles = [] }) => {
  return (
    <DashboardLayout>
      <Head title="Improvement Dashboard - ZephyrusOR" />
      <PageContentLayout
        title="Improvement"
        subtitle="Track and manage improvement initiatives across the organization"
      >
        <div className="flex justify-end mb-6">
          <Link href="/improvement/active/new">
            <Button className="flex items-center gap-2 bg-healthcare-primary hover:bg-healthcare-primary/90 text-white dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-dark/90">
              <Plus className="h-4 w-4" />
              New PDSA Cycle
            </Button>
          </Link>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
          <ImprovementCard
            title="Overview"
            description="Overview of improvement initiatives"
            icon={LayoutDashboard}
            href="/improvement/overview"
            count={stats.total}
            countLabel="Total Initiatives"
          />

          <ImprovementCard
            title="Opportunities"
            description="Review and prioritize improvement opportunities"
            icon={Target}
            href="/improvement/opportunities"
            count={stats.opportunities}
            countLabel="Open Items"
          />

          <ImprovementCard
            title="Library"
            description="Access improvement resources and templates"
            icon={Library}
            href="/improvement/library"
            count={stats.libraryItems}
            countLabel="Resources"
          />

          <ImprovementCard
            title="Active Cycles"
            description="Track active improvement initiatives"
            icon={RefreshCcw}
            href="/improvement/active"
            count={stats.activePDSA}
            countLabel="Active Cycles"
          />
        </div>

        {/* Recent PDSA Activity */}
        <div className="mt-8 bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm rounded-lg overflow-hidden transition-colors duration-300">
          <div className="p-6">
            <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4 transition-colors duration-300">
              Recent PDSA Activity
            </h2>
            <div className="space-y-4">
              {cycles.slice(0, 3).map((cycle, index) => (
                <div key={index} className="flex items-center justify-between border-b border-healthcare-border dark:border-healthcare-border-dark pb-4 last:border-0 transition-colors duration-300">
                  <div>
                    <h3 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                      {cycle.title}
                    </h3>
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                      {cycle.plan.objective}
                    </p>
                  </div>
                  <Link href={`/improvement/active/${cycle.id}`}>
                    <Button variant="outline" size="sm">
                      View Details
                    </Button>
                  </Link>
                </div>
              ))}
            </div>
          </div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Improvement;
