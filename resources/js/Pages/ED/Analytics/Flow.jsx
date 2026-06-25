import React from 'react';
import { Head } from '@inertiajs/react';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';
import PatientFlowNavigator from '@/Components/PatientFlowNavigator/PatientFlowNavigator';
import { useDarkMode } from '@/hooks/useDarkMode';

export default function Flow() {
    const [isDarkMode, setIsDarkMode] = useDarkMode();

    return (
        <div className="min-h-screen bg-healthcare-background text-healthcare-text-primary dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark">
            <Head title="Patient Flow - Emergency" />
            <TopNavbar isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />
            <PatientFlowNavigator initialFloor="1" />
        </div>
    );
}
