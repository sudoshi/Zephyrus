import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Progress } from '@/Components/ui/progress';
import { Plus, Search, ArrowRight } from 'lucide-react';
import { activePDSACycles } from '@/mock-data/improvement/index.js';

const formatDate = (dateString) => {
  return new Date(dateString).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
};

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
    case 'in progress':
      return 'text-blue-600 bg-blue-100 dark:text-blue-400 dark:bg-blue-900';
    default:
      return 'text-gray-600 bg-gray-100 dark:text-gray-400 dark:bg-gray-900';
  }
};

const Index = ({ auth }) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [sortBy, setSortBy] = useState('dueDate');

  // Filter and sort cycles
  const filteredCycles = activePDSACycles
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
        default:
          return 0;
      }
    });

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="PDSA Cycles" />
      
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div className="healthcare-card">
            <div className="border-b p-6">
              <div className="flex justify-between items-center">
                <h2 className="text-2xl font-semibold">PDSA Cycles</h2>
                <Link href="/improvement/pdsa/create">
                  <button className="healthcare-button-primary flex items-center gap-2">
                    <Plus className="h-4 w-4" />
                    New PDSA Cycle
                  </button>
                </Link>
              </div>

              <div className="mt-4 flex flex-col sm:flex-row gap-4">
                <div className="flex-1 relative">
                  <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
                  <input
                    type="text"
                    placeholder="Search cycles..."
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    className="healthcare-input pl-9 w-full"
                  />
                </div>
                <select
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                  className="healthcare-input"
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
                  className="healthcare-input"
                >
                  <option value="dueDate">Sort by Due Date</option>
                  <option value="progress">Sort by Progress</option>
                </select>
              </div>
            </div>

            <div className="p-6">
              <div className="space-y-4">
                {filteredCycles.map((cycle) => (
                  <div
                    key={cycle.id}
                    className="healthcare-card hover:shadow-md transition-shadow"
                  >
                    <div className="flex justify-between items-start">
                      <div className="space-y-2">
                        <h3 className="text-lg font-medium">{cycle.title}</h3>
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
                      <Link href={`/improvement/pdsa/${cycle.id}`}>
                        <button className="healthcare-button-secondary text-sm flex items-center gap-2">
                          View Details
                          <ArrowRight className="h-4 w-4" />
                        </button>
                      </Link>
                    </div>

                    <div className="mt-4">
                      <div className="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>Progress</span>
                        <span>{cycle.progress}%</span>
                      </div>
                      <Progress value={cycle.progress} className="mt-2" />
                    </div>

                    {cycle.barriers && cycle.barriers.length > 0 && (
                      <div className="mt-4 pt-4 border-t">
                        <h4 className="text-sm font-medium mb-2">Active Barriers</h4>
                        <div className="space-y-2">
                          {cycle.barriers.map((barrier) => (
                            <div key={barrier.id} className="flex items-center justify-between text-sm">
                              <span className="text-gray-600">{barrier.description}</span>
                              <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(barrier.status)}`}>
                                {barrier.priority}
                              </span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
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
