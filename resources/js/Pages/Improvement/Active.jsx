import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';

const Active = ({ auth }) => {
  const activeInitiatives = [
    {
      title: 'OR Turnover Time Reduction',
      status: 'In Progress',
      progress: 65,
      owner: 'Dr. Sarah Chen',
      dueDate: '2025-03-15',
      priority: 'High',
    },
    {
      title: 'Patient Flow Optimization',
      status: 'Planning',
      progress: 25,
      owner: 'James Wilson',
      dueDate: '2025-04-01',
      priority: 'Medium',
    },
    {
      title: 'Resource Scheduling Enhancement',
      status: 'Review',
      progress: 90,
      owner: 'Dr. Michael Brown',
      dueDate: '2025-02-28',
      priority: 'High',
    },
  ];

  const getStatusColor = (status) => {
    switch (status.toLowerCase()) {
      case 'in progress':
        return 'text-blue-600 bg-blue-100 dark:text-blue-400 dark:bg-blue-900';
      case 'planning':
        return 'text-yellow-600 bg-yellow-100 dark:text-yellow-400 dark:bg-yellow-900';
      case 'review':
        return 'text-purple-600 bg-purple-100 dark:text-purple-400 dark:bg-purple-900';
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

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Active Improvements" />
      
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6">
              <div className="flex justify-between items-center">
                <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                  Active Improvements
                </h1>
                <button className="px-4 py-2 bg-healthcare-primary text-white rounded-md hover:bg-healthcare-primary-dark transition-colors duration-300 flex items-center">
                  <Icon icon="heroicons:plus" className="w-5 h-5 mr-2" />
                  New Initiative
                </button>
              </div>

              <div className="mt-8">
                {activeInitiatives.map((initiative, index) => (
                  <div 
                    key={index}
                    className="mb-6 bg-gray-50 dark:bg-gray-700 rounded-lg shadow p-6"
                  >
                    <div className="flex justify-between items-start">
                      <div>
                        <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                          {initiative.title}
                        </h3>
                        <div className="mt-2 flex items-center space-x-4">
                          <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(initiative.status)}`}>
                            {initiative.status}
                          </span>
                          <span className={`text-sm ${getPriorityColor(initiative.priority)}`}>
                            {initiative.priority} Priority
                          </span>
                        </div>
                      </div>
                      <button className="text-gray-400 hover:text-gray-500 dark:text-gray-500 dark:hover:text-gray-400">
                        <Icon icon="heroicons:ellipsis-vertical" className="w-5 h-5" />
                      </button>
                    </div>

                    <div className="mt-4">
                      <div className="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>Progress</span>
                        <span>{initiative.progress}%</span>
                      </div>
                      <div className="mt-2 w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2">
                        <div
                          className="bg-healthcare-primary dark:bg-healthcare-primary-dark h-2 rounded-full"
                          style={{ width: `${initiative.progress}%` }}
                        />
                      </div>
                    </div>

                    <div className="mt-4 flex items-center justify-between text-sm">
                      <div className="text-gray-600 dark:text-gray-400">
                        <Icon icon="heroicons:user" className="w-4 h-4 inline mr-1" />
                        {initiative.owner}
                      </div>
                      <div className="text-gray-600 dark:text-gray-400">
                        <Icon icon="heroicons:calendar" className="w-4 h-4 inline mr-1" />
                        Due: {initiative.dueDate}
                      </div>
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

export default Active;
