import React from 'react';
import { Head } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import BlockUtilizationDashboard from '@/Components/Analytics/BlockUtilization/BlockUtilizationDashboard';
import { Button } from '@/Components/ui/flowbite';

export default function BlockUtilization({ auth }) {
  // Define the buttons to be passed to the header
  const headerButtons = (
    <>
      <Button variant="outline" size="sm" className="text-[14px]">By Service</Button>
      <Button variant="outline" size="sm" className="text-[14px]">Comparative Trend</Button>
      <Button variant="outline" size="sm" className="text-[14px]">Day of Week</Button>
      <Button variant="outline" size="sm" className="text-[14px]">By Location/Group</Button>
      <Button variant="outline" size="sm" className="text-[14px]">By Block Group</Button>
      <Button variant="outline" size="sm" className="text-[14px]">Details</Button>
      <Button variant="outline" size="sm" className="text-[14px]">Non-Primetime Usage</Button>
    </>
  );

  return (
    <AnalyticsLayout
      auth={auth}
      title="Block Utilization"
      headerButtons={headerButtons}
    >
      <BlockUtilizationDashboard />
    </AnalyticsLayout>
  );
}
