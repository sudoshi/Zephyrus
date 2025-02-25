import React from 'react';
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { FlowbiteThemeProvider } from '@/Components/ui';
import { Card } from '@/Components/ui/flowbite';

export default function SimpleTest({ auth }) {
  return (
    <AuthenticatedLayout
      user={auth.user}
      header={<h2 className="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Simple Test</h2>}
    >
      <Head title="Simple Test" />

      <div className="py-6">
        <div className="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
          <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6 text-gray-900 dark:text-gray-100">
              <FlowbiteThemeProvider>
                <Card title="Test Card">
                  <p>This is a test card to see if the Flowbite components are working correctly.</p>
                </Card>
              </FlowbiteThemeProvider>
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
