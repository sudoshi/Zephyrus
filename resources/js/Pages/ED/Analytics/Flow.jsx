import React from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PatientFlowNavigator from '@/Components/PatientFlowNavigator/PatientFlowNavigator';

// P4b: the hand-rolled TopNavbar shell converged onto the unified DashboardLayout
// (fullBleed — the 4D navigator needs the uncapped width). The Flow Window lens +
// unit summaries (flow-window Phase 4) pass through to the navigator.
export default function Flow({ flowLens = null, flowUnits = [] }) {
    return (
        <DashboardLayout fullBleed>
            <Head title="Patient Flow - Emergency" />
            <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                <PatientFlowNavigator initialFloor="1" lens={flowLens} units={flowUnits} />
            </div>
        </DashboardLayout>
    );
}
