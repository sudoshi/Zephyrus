import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

const Improvement = ({ auth }) => {
  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Improvement Dashboard" />
      
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
<<<<<<< HEAD
          <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6">
              <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                Improvement Dashboard
              </h1>
              <p className="mt-4 text-gray-600 dark:text-gray-400">
                Welcome to the Improvement Dashboard. This area is dedicated to tracking and managing improvement initiatives across the organization.
              </p>
=======
          <div className="mb-6 flex justify-between items-center">
            <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
              Improvement Dashboard
            </h1>
            <Link href="/improvement/pdsa">
              <Button className="flex items-center gap-2">
                <Plus className="h-4 w-4" />
                New PDSA Cycle
              </Button>
            </Link>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            <ImprovementCard
              title="PDSA Management"
              description="Track and manage Plan-Do-Study-Act improvement cycles"
              icon={BarChart2}
              href="/improvement/pdsa"
              count={stats.activePDSA}
              countLabel="Active Cycles"
            />

            <ImprovementCard
              title="Opportunities"
              description="Review and prioritize improvement opportunities"
              icon={ClipboardList}
              href="/improvement/opportunities"
              count={stats.opportunities}
              countLabel="Open Items"
            />

            <ImprovementCard
              title="Library"
              description="Access improvement resources and templates"
              icon={BookOpen}
              href="/improvement/library"
              count={stats.libraryItems}
              countLabel="Resources"
            />
          </div>

          {/* Recent PDSA Activity */}
          <div className="mt-8 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6">
              <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                Recent PDSA Activity
              </h2>
              <div className="space-y-4">
                {cycles.slice(0, 3).map((cycle, index) => (
                  <div key={index} className="flex items-center justify-between border-b pb-4 last:border-0">
                    <div>
                      <h3 className="font-medium">{cycle.title}</h3>
                      <p className="text-sm text-gray-600">{cycle.plan.objective}</p>
                    </div>
                    <Link href={`/improvement/pdsa/${cycle.id}`}>
                      <Button variant="outline" size="sm">
                        View Details
                      </Button>
                    </Link>
                  </div>
                ))}
              </div>
>>>>>>> 8162700 (Routes and Cache Issue Fixed)
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
};

export default Improvement;
