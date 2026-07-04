import React from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PatientFlowNavigatorView from '@/Components/PatientFlowNavigator/PatientFlowNavigator';

// P4b: the hand-rolled TopNavbar shell converged onto the unified
// DashboardLayout (fullBleed — the 4D navigator needs the uncapped width).
export default function PatientFlowNavigator() {
  return (
    <DashboardLayout fullBleed>
      <Head title="Patient Flow 4D Navigator - RTDC" />
      <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        <PatientFlowNavigatorView />
      </div>
    </DashboardLayout>
  );
}
