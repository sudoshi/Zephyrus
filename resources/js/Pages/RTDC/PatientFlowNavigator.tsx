import React from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PatientFlowNavigatorView from '@/Components/PatientFlowNavigator/PatientFlowNavigator';
import type { FlowLens, FlowUnitSummary } from '@/features/patientFlowNavigator/types';

interface PatientFlowNavigatorPageProps {
  flowLens?: FlowLens | null;
  flowUnits?: FlowUnitSummary[];
}

// P4b: the hand-rolled TopNavbar shell converged onto the unified DashboardLayout
// (fullBleed — the 4D navigator needs the uncapped width). The Flow Window lens +
// unit summaries (flow-window Phase 4) pass through to the navigator view.
export default function PatientFlowNavigator({ flowLens = null, flowUnits = [] }: PatientFlowNavigatorPageProps) {
  return (
    <DashboardLayout fullBleed>
      <Head title="Patient Flow 4D Navigator - RTDC" />
      <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        <PatientFlowNavigatorView lens={flowLens} units={flowUnits} />
      </div>
    </DashboardLayout>
  );
}
