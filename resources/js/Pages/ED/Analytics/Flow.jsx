import React from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PatientFlowNavigator from '@/Components/PatientFlowNavigator/PatientFlowNavigator';

// P4b: the hand-rolled TopNavbar shell converged onto the unified
// DashboardLayout (fullBleed — the 4D navigator needs the uncapped width).
export default function Flow() {
    return (
        <DashboardLayout fullBleed>
            <Head title="Patient Flow - Emergency" />
            <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                <PatientFlowNavigator initialFloor="1" />
            </div>
        </DashboardLayout>
    );
}
