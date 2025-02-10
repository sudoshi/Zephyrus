import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/button';
import { Progress } from '@/Components/ui/progress';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/tabs';
import { Input } from '@/Components/ui/input';
import { Icon } from '@iconify/react';
import { cycles } from '@/mock-data/pdsa/cycles';
import CareIssuesModal from './PDSA/CareIssuesModal';

const PDSADashboard = ({ auth }) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [sortBy, setSortBy] = useState('dueDate');
  const [showCareIssues, setShowCareIssues] = useState(false);

  // Filter and sort cycles
  const filteredCycles = cycles
    .filter(cycle => {
      const matchesSearch = cycle.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
        cycle.plan.objective.toLowerCase().includes(searchTerm.toLowerCase());
      const matchesStatus = statusFilter === 'all' || cycle.status.toLowerCase() === statusFilter.toLowerCase();
      return matchesSearch && matchesStatus;
    })
    .sort((a, b) => {
      switch (sortBy) {
        case 'dueDate':
          return new Date(a.dueDate) - new Date(b.dueDate);
        case 'progress':
          return b.progress - a.progress;
        case 'priority':
          return b.priority.localeCompare(a.priority);
        default:
          return 0;
      }
    });

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

  const getPriorityColor = (priority) => {
    switch (priority.toLowerCase()) {
      case 'high':
        return 'text-red-600';
      case 'medium':
        return 'text-yellow-600';
      case 'low':
        return 'text-green-600';
      default:
        return 'text-gray-600';
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
      <Head title="PDSA Management" />
      
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <Card>
          <CardHeader className="border-b">
            <div className="flex justify-between items-center">
              <CardTitle className="text-2xl font-semibold">PDSA Management</CardTitle>
              <div className="flex gap-2">
                <Button 
                  variant="outline"
                  onClick={() => setShowCareIssues(true)}
                  className="flex items-center gap-2"
                >
                  <Icon icon="heroicons:exclamation-triangle" className="w-5 h-5" />
                  Care Issues
                </Button>
                <Button className="bg-healthcare-primary hover:bg-healthcare-primary-dark">
                  <Icon icon="heroicons:plus" className="w-5 h-5 mr-2" />
                  New PDSA Cycle
                </Button>
              </div>
            </div>

            {/* Filters and Search */}
            <div className="mt-4 flex flex-col sm:flex-row gap-4">
              <div className="flex-1">
                <Input
                  type="text"
                  placeholder="Search cycles..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full"
                />
              </div>
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="px-3 py-2 bg-white dark:bg-gray-800 border rounded-md"
              >
                <option value="all">All Statuses</option>
                <option value="plan">Plan</option>
                <option value="do">Do</option>
                <option value="study">Study</option>
                <option value="act">Act</option>
              </select>
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value)}
                className="px-3 py-2 bg-white dark:bg-gray-800 border rounded-md"
              >
                <option value="dueDate">Sort by Due Date</option>
                <option value="progress">Sort by Progress</option>
                <option value="priority">Sort by Priority</option>
              </select>
            </div>
          </CardHeader>

          <CardContent className="p-6">
            {/* Quick Stats */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
              <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">Total Cycles</h3>
                <p className="text-2xl font-semibold mt-1">{cycles.length}</p>
              </div>
              <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">In Progress</h3>
                <p className="text-2xl font-semibold mt-1">
                  {cycles.filter(c => c.status !== 'completed').length}
                </p>
              </div>
              <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">Due This Week</h3>
                <p className="text-2xl font-semibold mt-1">
                  {cycles.filter(c => {
                    const dueDate = new Date(c.dueDate);
                    const today = new Date();
                    const weekFromNow = new Date();
                    weekFromNow.setDate(weekFromNow.getDate() + 7);
                    return dueDate >= today && dueDate <= weekFromNow;
                  }).length}
                </p>
              </div>
              <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">Completed</h3>
                <p className="text-2xl font-semibold mt-1">
                  {cycles.filter(c => c.status === 'completed').length}
                </p>
              </div>
            </div>

            {/* Cycles List */}
            <div className="space-y-4">
              {filteredCycles.map((cycle, index) => (
                <div
                  key={index}
                  className="bg-white dark:bg-gray-800 rounded-lg shadow-sm border p-6 hover:shadow-md transition-shadow"
                >
                  <div className="flex justify-between items-start">
                    <div className="space-y-2">
                      <h3 className="text-lg font-medium">{cycle.title}</h3>
                      <p className="text-gray-600 dark:text-gray-400">{cycle.plan.objective}</p>
                      <div className="flex items-center gap-3">
                        <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(cycle.status)}`}>
                          {cycle.status}
                        </span>
                        <span className={`text-sm ${getPriorityColor(cycle.priority)}`}>
                          {cycle.priority} Priority
                        </span>
                        <span className="text-sm text-gray-500">
                          Due: {formatDate(cycle.dueDate)}
                        </span>
                      </div>
                    </div>
                    <Button variant="ghost" className="text-gray-400 hover:text-gray-500">
                      <Icon icon="heroicons:ellipsis-vertical" className="w-5 h-5" />
                    </Button>
                  </div>

                  <div className="mt-4">
                    <div className="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                      <span>Progress</span>
                      <span>{cycle.progress}%</span>
                    </div>
                    <Progress value={cycle.progress} className="mt-2" />
                  </div>

                  <div className="mt-4 flex items-center justify-between">
                    <div className="flex items-center gap-4">
                      <span className="text-sm text-gray-500">
                        <Icon icon="heroicons:user" className="w-4 h-4 inline mr-1" />
                        {cycle.owner}
                      </span>
                      <span className="text-sm text-gray-500">
                        <Icon icon="heroicons:users" className="w-4 h-4 inline mr-1" />
                        {cycle.team?.length || 0} team members
                      </span>
                    </div>
                    <Link href={`/improvement/pdsa/${cycle.id}`}>
                      <Button
                        variant="outline"
                        className="text-sm"
                      >
                        View Details
                        <Icon icon="heroicons:arrow-right" className="w-4 h-4 ml-2" />
                      </Button>
                    </Link>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
        </div>
      </div>

      <CareIssuesModal 
        open={showCareIssues} 
        onClose={() => setShowCareIssues(false)} 
      />
    </AuthenticatedLayout>
  );
};

export default PDSADashboard;
