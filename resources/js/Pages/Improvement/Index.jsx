import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Progress } from '@/Components/ui/progress';
import { CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';
import { 
  ClipboardList, 
  BookOpen, 
  BarChart2,
  Plus,
  ArrowRight
} from 'lucide-react';
import { 
  activePDSACycles, 
  improvementStats as stats 
} from '@/mock-data/improvement/index.js';

const formatDate = (dateString) => {
  return new Date(dateString).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
};

const ImprovementCard = ({ title, description, icon: Icon, href, count, countLabel }) => (
  <div className="healthcare-card hover:border-blue-500 transition-colors">
    <CardHeader className="flex flex-row items-center justify-between pb-2">
      <CardTitle className="text-lg font-medium">{title}</CardTitle>
      <Icon className="h-5 w-5 text-gray-500" />
    </CardHeader>
    <CardContent>
      <p className="text-sm text-gray-600 mb-4">{description}</p>
      {count !== undefined && (
        <div className="mb-4">
          <span className="text-2xl font-bold">{count}</span>
          <span className="text-sm text-gray-500 ml-2">{countLabel}</span>
        </div>
      )}
      <Link href={href}>
        <button className="healthcare-button-secondary w-full flex items-center justify-center gap-2">
          View Details
          <ArrowRight className="h-4 w-4" />
        </button>
      </Link>
    </CardContent>
  </div>
);

const Index = ({ auth }) => {
  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Improvement Dashboard" />
      
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div className="mb-6 flex justify-between items-center">
            <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
              Improvement Dashboard
            </h1>
            <Link href="/improvement/pdsa/create">
              <button className="healthcare-button-primary flex items-center gap-2">
                <Plus className="h-4 w-4" />
                New PDSA Cycle
              </button>
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

          {/* Stats Overview */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
            <div className="healthcare-card p-6">
              <h3 className="text-lg font-medium mb-2">Success Rate</h3>
              <div className="flex items-center gap-2">
                <span className="text-3xl font-bold">{stats.successRate}%</span>
                <span className="text-sm text-gray-500">of cycles</span>
              </div>
            </div>
            <div className="healthcare-card p-6">
              <h3 className="text-lg font-medium mb-2">Completed Cycles</h3>
              <div className="flex items-center gap-2">
                <span className="text-3xl font-bold">{stats.completedCycles}</span>
                <span className="text-sm text-gray-500">total</span>
              </div>
            </div>
            <div className="healthcare-card p-6">
              <h3 className="text-lg font-medium mb-2">Average Duration</h3>
              <div className="flex items-center gap-2">
                <span className="text-3xl font-bold">{stats.avgCycleDuration}</span>
                <span className="text-sm text-gray-500">days</span>
              </div>
            </div>
          </div>

          {/* Recent PDSA Activity */}
          <div className="mt-8 healthcare-card">
            <div className="p-6">
              <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                Recent PDSA Activity
              </h2>
              <div className="space-y-4">
                {activePDSACycles.map((cycle) => (
                  <div key={cycle.id} className="flex flex-col border-b pb-4 last:border-0">
                    <div className="flex items-center justify-between mb-2">
                      <div>
                        <h3 className="font-medium">{cycle.title}</h3>
                        <p className="text-sm text-gray-600">{cycle.plan.objective}</p>
                      </div>
                      <Link href={`/improvement/pdsa/${cycle.id}`}>
                        <button className="healthcare-button-secondary">
                          View Details
                        </button>
                      </Link>
                    </div>
                    <div className="flex items-center gap-4">
                      <div className="flex-1">
                        <div className="flex items-center justify-between text-sm text-gray-600 mb-1">
                          <span>Progress</span>
                          <span>{cycle.progress}%</span>
                        </div>
                        <Progress value={cycle.progress} />
                      </div>
                      <span className="text-sm text-gray-500">
                        Due: {formatDate(cycle.dueDate)}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
};

export default Index;
