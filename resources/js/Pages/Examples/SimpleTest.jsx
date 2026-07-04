import React from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { FlowbiteThemeProvider } from '@/Components/ui';
import { Card } from '@/Components/ui/flowbite';

export default function SimpleTest() {
  return (
    <DashboardLayout>
      <Head title="Simple Test" />

      <PageContentLayout title="Simple Test">
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark overflow-hidden shadow-sm rounded-lg">
          <div className="p-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            <FlowbiteThemeProvider>
              <Card title="Test Card">
                <p>This is a test card to see if the Flowbite components are working correctly.</p>
              </Card>
            </FlowbiteThemeProvider>
          </div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
