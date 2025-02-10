import React from 'react';
import { Head } from '@inertiajs/react';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/button';
import { Progress } from '@/Components/ui/progress';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/tabs';
import { Edit3, ArrowLeft, ChevronRight } from 'lucide-react';

import { currentCycles } from '@/mock-data/pdsa';

export default function PDSACycleManagementPage({ id }) {
  // Find the cycle data based on the ID
  const cycleData = currentCycles.find(
    (cycle) => cycle.id === parseInt(id || '1', 10)
  );

  if (!cycleData) {
    return (
      <div className="p-6">
        <Head title="PDSA Cycle Not Found" />
        <Button variant="ghost" href="/improvement/pdsa" className="flex items-center gap-2">
          <ArrowLeft className="w-4 h-4" />
          <span>Back</span>
        </Button>
        <div className="mt-4">
          <h1 className="text-xl font-semibold">PDSA Cycle Not Found</h1>
          <p className="text-gray-600">The requested PDSA cycle does not exist.</p>
        </div>
      </div>
    );
  }

  const handleSave = () => {
    console.log('Saving PDSA Cycle Data...', cycleData);
    // TODO: Implement save functionality using Inertia
  };

  const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  };

  return (
    <div className="p-6 space-y-4">
      <Head title={`PDSA Cycle - ${cycleData.title}`} />
      
      {/* Breadcrumb / Header */}
      <div className="flex items-center justify-between">
        <Button 
          variant="ghost" 
          href="/improvement/pdsa" 
          className="flex items-center gap-2"
        >
          <ArrowLeft className="w-4 h-4" />
          <span>Back to PDSA Cycles</span>
        </Button>
      </div>

      <Card className="w-full mt-4">
        <CardHeader className="p-4 border-b">
          <div className="flex justify-between items-center">
            <CardTitle className="text-xl font-semibold">
              {cycleData.title}
            </CardTitle>
          </div>
          <p className="text-gray-600 mt-2">
            Objective: {cycleData.plan.objective}
          </p>
          <div className="flex items-center gap-4 mt-3">
            <span className="text-sm text-gray-500">
              Status: <strong>{cycleData.status}</strong>
            </span>
            <span className="text-sm text-gray-500">
              Due: <strong>{formatDate(cycleData.dueDate)}</strong>
            </span>
          </div>
          <div className="mt-2">
            <Progress value={cycleData.progress} />
            <p className="text-sm text-gray-500 mt-1">
              {cycleData.progress}% Complete
            </p>
          </div>
        </CardHeader>

        <CardContent className="p-6">
          <Tabs defaultValue="overview">
            <TabsList className="mb-4">
              <TabsTrigger value="overview">Overview</TabsTrigger>
              <TabsTrigger value="plan">Plan</TabsTrigger>
              <TabsTrigger value="do">Do</TabsTrigger>
              <TabsTrigger value="study">Study</TabsTrigger>
              <TabsTrigger value="act">Act</TabsTrigger>
              <TabsTrigger value="metrics">Metrics</TabsTrigger>
            </TabsList>

            {/* Overview Tab */}
            <TabsContent value="overview" className="space-y-4">
              <div>
                <h2 className="text-lg font-semibold mb-2">Objective</h2>
                <p className="text-gray-700">
                  {cycleData.plan.objective}
                </p>
              </div>
            </TabsContent>

            {/* Plan Tab */}
            <TabsContent value="plan" className="space-y-4">
              <div>
                <h2 className="text-lg font-semibold mb-2">Plan Details</h2>
                <p className="text-gray-700 mb-4">{cycleData.plan.details}</p>
              </div>
              <div className="bg-gray-50 p-4 rounded-lg border">
                <h3 className="text-md font-medium mb-2">Actions</h3>
                <ul className="list-disc ml-5 space-y-1">
                  {cycleData.do?.actions?.map((action, idx) => (
                    <li key={idx} className="text-gray-700">{action}</li>
                  ))}
                </ul>
              </div>
            </TabsContent>

            {/* Do Tab */}
            <TabsContent value="do" className="space-y-4">
              <div>
                <h2 className="text-lg font-semibold mb-2">Implementation (Do)</h2>
                <div className="space-y-4">
                  <div className="bg-gray-50 p-4 rounded-lg border">
                    <h3 className="text-md font-medium mb-2">Timeline</h3>
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <p className="text-sm text-gray-600">Start Date</p>
                        <p className="font-medium">{formatDate(cycleData.do?.startDate)}</p>
                      </div>
                      <div>
                        <p className="text-sm text-gray-600">Completion Date</p>
                        <p className="font-medium">
                          {cycleData.do?.completionDate ? formatDate(cycleData.do.completionDate) : 'In Progress'}
                        </p>
                      </div>
                    </div>
                  </div>
                  <div className="bg-gray-50 p-4 rounded-lg border">
                    <h3 className="text-md font-medium mb-2">Actions Taken</h3>
                    <ul className="list-disc ml-5 space-y-1">
                      {cycleData.do?.actions?.map((action, idx) => (
                        <li key={idx} className="text-gray-700">{action}</li>
                      ))}
                    </ul>
                  </div>
                </div>
              </div>
            </TabsContent>

            {/* Study Tab */}
            <TabsContent value="study" className="space-y-4">
              <div>
                <h2 className="text-lg font-semibold mb-2">Study Phase</h2>
                <div className="space-y-4">
                  <div className="bg-gray-50 p-4 rounded-lg border">
                    <h3 className="text-md font-medium mb-2">Data Collection Period</h3>
                    <p className="text-gray-700">{cycleData.study?.dataCollectionPeriod}</p>
                  </div>
                  <div className="bg-gray-50 p-4 rounded-lg border">
                    <h3 className="text-md font-medium mb-2">Key Findings</h3>
                    <p className="text-gray-700">{cycleData.study?.findings}</p>
                  </div>
                  <div className="bg-gray-50 p-4 rounded-lg border">
                    <h3 className="text-md font-medium mb-2">Metrics Tracked</h3>
                    <ul className="list-disc ml-5 space-y-1">
                      {cycleData.study?.metrics?.map((metric, idx) => (
                        <li key={idx} className="text-gray-700">{metric}</li>
                      ))}
                    </ul>
                  </div>
                </div>
              </div>
            </TabsContent>

            {/* Act Tab */}
            <TabsContent value="act" className="space-y-4">
              <div>
                <h2 className="text-lg font-semibold mb-2">Act Phase</h2>
                <div className="space-y-4">
                  <div className="bg-gray-50 p-4 rounded-lg border">
                    <h3 className="text-md font-medium mb-2">Identified Improvements</h3>
                    <ul className="list-disc ml-5 space-y-1">
                      {cycleData.act?.improvements?.map((improvement, idx) => (
                        <li key={idx} className="text-gray-700">{improvement}</li>
                      ))}
                    </ul>
                  </div>
                  <div className="bg-gray-50 p-4 rounded-lg border">
                    <h3 className="text-md font-medium mb-2">Next Steps</h3>
                    <p className="text-gray-700">{cycleData.act?.nextSteps}</p>
                  </div>
                </div>
              </div>
            </TabsContent>

            {/* Metrics Tab */}
            <TabsContent value="metrics" className="space-y-6">
              <div>
                <h2 className="text-lg font-semibold mb-2">Key Metrics</h2>
                <ul className="list-disc ml-5 space-y-1">
                  {cycleData.study.metrics.map((metric, idx) => (
                    <li key={idx} className="text-gray-700 flex items-center gap-2">
                      <ChevronRight className="h-3 w-3 text-gray-400" />
                      {metric}
                    </li>
                  ))}
                </ul>
              </div>
              {/* Placeholder for future metric charts */}
              <div className="bg-gray-50 p-4 rounded-lg border">
                <p className="text-gray-600 text-sm">Future placeholder for metric charts</p>
              </div>
            </TabsContent>
          </Tabs>

          {/* Footer actions */}
          <div className="mt-8 flex items-center justify-between border-t pt-4">
            <Button 
            variant="outline" 
            href="/improvement/pdsa"
            >
              Cancel
            </Button>
            <Button
              onClick={handleSave}
              className="flex items-center gap-2 bg-blue-600 text-white hover:bg-blue-700"
            >
              <Edit3 className="w-4 h-4" />
              Save Changes
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
