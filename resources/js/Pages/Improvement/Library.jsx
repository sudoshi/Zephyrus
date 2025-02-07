import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';

const Library = ({ auth }) => {
  const resources = [
    {
      title: 'Process Improvement Templates',
      description: 'Standardized templates for documenting and tracking improvement initiatives.',
      icon: 'heroicons:document-duplicate',
    },
    {
      title: 'Best Practices Guide',
      description: 'Collection of proven methodologies and best practices for healthcare improvement.',
      icon: 'heroicons:book-open',
    },
    {
      title: 'Measurement Tools',
      description: 'Tools and frameworks for measuring improvement outcomes.',
      icon: 'heroicons:chart-bar',
    },
    {
      title: 'Training Materials',
      description: 'Educational resources for staff training and development.',
      icon: 'heroicons:academic-cap',
    },
  ];

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Improvement Library" />
      
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6">
              <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                Improvement Library
              </h1>
              <p className="mt-4 text-gray-600 dark:text-gray-400">
                Access improvement resources, templates, and best practices.
              </p>
              
              <div className="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2">
                {resources.map((resource, index) => (
                  <div 
                    key={index}
                    className="flex items-start p-6 bg-gray-50 dark:bg-gray-700 rounded-lg shadow hover:shadow-md transition-shadow duration-300"
                  >
                    <div className="flex-shrink-0">
                      <Icon 
                        icon={resource.icon}
                        className="w-8 h-8 text-healthcare-primary dark:text-healthcare-primary-dark"
                      />
                    </div>
                    <div className="ml-4">
                      <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {resource.title}
                      </h3>
                      <p className="mt-2 text-gray-600 dark:text-gray-400">
                        {resource.description}
                      </p>
                      <button className="mt-4 text-healthcare-primary dark:text-healthcare-primary-dark hover:text-healthcare-primary-dark dark:hover:text-healthcare-primary flex items-center">
                        Access Resource
                        <Icon icon="heroicons:arrow-right" className="w-4 h-4 ml-2" />
                      </button>
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

export default Library;
