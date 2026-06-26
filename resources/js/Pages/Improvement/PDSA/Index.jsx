import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Progress from '@/Components/ui/progress';
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
      return 'text-healthcare-warning bg-healthcare-warning/10 dark:text-healthcare-warning-dark dark:bg-healthcare-warning-dark/20';
    case 'do':
      return 'text-healthcare-info bg-healthcare-info/10 dark:text-healthcare-info-dark dark:bg-healthcare-info-dark/20';
    case 'study':
      return 'text-purple-600 bg-purple-100 dark:text-purple-400 dark:bg-purple-900';
    case 'act':
      return 'text-healthcare-success bg-healthcare-success/10 dark:text-healthcare-success-dark dark:bg-healthcare-success-dark/20';
    case 'in progress':
      return 'text-healthcare-info bg-healthcare-info/10 dark:text-healthcare-info-dark dark:bg-healthcare-info-dark/20';
    default:
      return 'text-healthcare-text-secondary bg-healthcare-background dark:text-healthcare-text-secondary-dark dark:bg-healthcare-background-dark';
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
      
      <div className="p-4">
        <div>
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
                  <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
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
                        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{cycle.plan.objective}</p>
                        <div className="flex items-center gap-3">
                          <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(cycle.status)}`}>
                            {cycle.status}
                          </span>
                          <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
                      <div className="flex items-center justify-between text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
                              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{barrier.description}</span>
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
