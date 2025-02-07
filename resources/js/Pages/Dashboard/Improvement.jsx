import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

const Improvement = ({ auth }) => {
  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Improvement Dashboard" />
      
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6">
              <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                Improvement Dashboard
              </h1>
              <p className="mt-4 text-gray-600 dark:text-gray-400">
                Welcome to the Improvement Dashboard. This area is dedicated to tracking and managing improvement initiatives across the organization.
              </p>
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
};

export default Improvement;
