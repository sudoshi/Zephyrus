import React from 'react';
import { Head } from '@inertiajs/react';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';
import PatientFlowNavigatorView from '@/Components/PatientFlowNavigator/PatientFlowNavigator';
import { useDarkMode } from '@/hooks/useDarkMode';

export default function PatientFlowNavigator() {
  const [isDarkMode, setIsDarkMode] = useDarkMode();

  return (
    <div className="min-h-screen bg-healthcare-background text-healthcare-text-primary dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark">
      <Head title="Patient Flow 4D Navigator - RTDC" />
      <TopNavbar isDarkMode={isDarkMode} setIsDarkMode={setIsDarkMode} />
      <PatientFlowNavigatorView />
    </div>
  );
}
