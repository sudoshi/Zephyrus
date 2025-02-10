import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Progress } from '@/Components/ui/progress';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/tabs';
import { ArrowLeft, Pencil } from 'lucide-react';

const Show = ({ auth, cycle }) => {
  const getStatusColor = (status) => {
    switch (status.toLowerCase()) {
      case 'plan':
        return 'text-yellow-600 bg-yellow-100 dark:text-yellow-400 dark:bg-yellow-900';
      case 'do':
        return 'text-blue-600 bg-blue-100 dark:text-blue-400 dark:bg-blue-900';
      case 'study':
        return 'text-purple-600 bg-purple-100 dark:text-purple-400 dark:bg-purple-900';
      case 'act':
        return 'text-green-600 bg-green-100 dark:text-green-400 dark:bg-green-900';
      default:
        return 'text-gray-600 bg-gray-100 dark:text-gray-400 dark:bg-gray-900';
    }
  };

  const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  };

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title={`PDSA Cycle - ${cycle.title}`} />
      
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div className="mb-6 flex items-center justify-between">
            <Link href="/improvement/pdsa">
              <button className="healthcare-button-secondary flex items-center gap-2">
                <ArrowLeft className="h-4 w-4" />
                Back to PDSA Cycles
              </button>
            </Link>
            <button className="healthcare-button-primary flex items-center gap-2">
              <Pencil className="h-4 w-4" />
              Edit Cycle
            </button>
          </div>

          <div className="healthcare-card">
            <div className="border-b p-6">
              <div className="space-y-2">
                <h2 className="text-2xl font-semibold">{cycle.title}</h2>
                <p className="text-gray-600 dark:text-gray-400">{cycle.plan.objective}</p>
                <div className="flex items-center gap-3">
                  <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(cycle.status)}`}>
                    {cycle.status}
                  </span>
                  <span className="text-sm text-gray-500">
                    Due: {formatDate(cycle.dueDate)}
                  </span>
                </div>
              </div>

              <div className="mt-4">
                <div className="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                  <span>Progress</span>
                  <span>{cycle.progress}%</span>
                </div>
                <Progress value={cycle.progress} className="mt-2" />
              </div>
            </div>

            <div className="p-6">
              <Tabs defaultValue="plan" className="w-full">
                <TabsList className="w-full justify-start">
                  <TabsTrigger value="plan">Plan</TabsTrigger>
                  <TabsTrigger value="do">Do</TabsTrigger>
                  <TabsTrigger value="study">Study</TabsTrigger>
                  <TabsTrigger value="act">Act</TabsTrigger>
                  <TabsTrigger value="barriers">Barriers</TabsTrigger>
                  <TabsTrigger value="failures">Discharge Failures</TabsTrigger>
                </TabsList>

                <TabsContent value="plan" className="mt-6">
                  <div className="space-y-6">
                    <div>
                      <h3 className="text-lg font-semibold mb-2">Objective</h3>
                      <p className="text-gray-700 dark:text-gray-300">{cycle.plan.objective}</p>
                    </div>
                    <div>
                      <h3 className="text-lg font-semibold mb-2">Details</h3>
                      <p className="text-gray-700 dark:text-gray-300">{cycle.plan.details}</p>
                    </div>
                  </div>
                </TabsContent>

                <TabsContent value="barriers" className="mt-6">
                  <div className="space-y-4">
                    {cycle.barriers.map((barrier) => (
                      <div key={barrier.id} className="healthcare-card">
                        <div className="flex justify-between items-start">
                          <div>
                            <h4 className="font-medium">{barrier.description}</h4>
                            <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                              {barrier.mitigation}
                            </p>
                          </div>
                          <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(barrier.status)}`}>
                            {barrier.status}
                          </span>
                        </div>
                      </div>
                    ))}
                  </div>
                </TabsContent>

                <TabsContent value="failures" className="mt-6">
                  <div className="space-y-4">
                    {cycle.dischargeFailures.map((failure) => (
                      <div key={failure.id} className="healthcare-card">
                        <div className="flex justify-between items-start">
                          <div>
                            <div className="flex items-center gap-2">
                              <h4 className="font-medium">{failure.type}</h4>
                              <span className="text-sm text-gray-500">{formatDate(failure.date)}</span>
                            </div>
                            <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                              {failure.description}
                            </p>
                            <div className="mt-2">
                              <p className="text-sm"><span className="font-medium">Root Cause:</span> {failure.rootCause}</p>
                              <p className="text-sm"><span className="font-medium">Action Taken:</span> {failure.actionTaken}</p>
                            </div>
                          </div>
                          <span className={`px-2 py-1 rounded-full text-xs font-medium bg-${failure.impact}-100 text-${failure.impact}-600`}>
                            {failure.impact} impact
                          </span>
                        </div>
                      </div>
                    ))}
                  </div>
                </TabsContent>

                <TabsContent value="study" className="mt-6">
                  <div className="space-y-6">
                    <div>
                      <h3 className="text-lg font-semibold mb-2">Key Metrics</h3>
                      <ul className="list-disc ml-5 space-y-1">
                        {cycle.study.metrics.map((metric, idx) => (
                          <li key={idx} className="text-gray-700 dark:text-gray-300">{metric}</li>
                        ))}
                      </ul>
                    </div>
                  </div>
                </TabsContent>
              </Tabs>
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
};

export default Show;
