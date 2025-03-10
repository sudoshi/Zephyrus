import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { Head, usePage } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import TabNavigation from '@/Components/ui/TabNavigation';
import PatientFlowDashboard from '@/Components/Analytics/PatientFlow/PatientFlowDashboard';

export default function PatientFlow({ auth }) {
  const { url } = usePage();
  const params = new URLSearchParams(window.location.search);
  const viewParam = params.get('view') || 'overview';
  
  const [activeTab, setActiveTab] = useState(viewParam);

  // Update the activeTab when URL changes
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const viewParam = params.get('view') || 'overview';
    setActiveTab(viewParam);
  }, [url]);

  // Handle tab change
  const handleTabChange = (tabId) => {
    setActiveTab(tabId);
    
    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('view', tabId);
    window.history.pushState({}, '', url);
  };

  // Define menu groups for the tabs
  const menuGroups = [
    {
      title: 'Overview',
      items: [
        { id: 'overview', label: 'Overview', icon: 'carbon:analytics' },
        { id: 'statistics', label: 'Statistics', icon: 'carbon:chart-line' },
      ]
    },
    {
      title: 'Analysis',
      items: [
        { id: 'process-map', label: 'Process Map', icon: 'carbon:flow' },
        { id: 'bottlenecks', label: 'Bottlenecks', icon: 'carbon:warning-alt' },
        { id: 'variants', label: 'Process Variants', icon: 'carbon:tree-view' },
      ]
    },
    {
      title: 'Insights',
      items: [
        { id: 'performance', label: 'Performance', icon: 'carbon:chart-evaluation' },
        { id: 'optimization', label: 'Optimization', icon: 'carbon:optimize' },
      ]
    }
  ];

  return (
    <AnalyticsLayout
      auth={auth}
      title="Patient Flow"
      header={null}
    >
      <Head title="Patient Flow" />
      <div className="space-y-6">
        {/* Top Navigation Tabs */}
        <TabNavigation 
          menuGroups={menuGroups}
          activeTab={activeTab}
          onTabChange={handleTabChange}
        />

        {/* Dashboard Component - Pass the activeTab as activeView prop */}
        <PatientFlowDashboard activeView={activeTab} />
      </div>
    </AnalyticsLayout>
  );
}

PatientFlow.propTypes = {
  auth: PropTypes.shape({
    user: PropTypes.shape({
      id: PropTypes.number,
      name: PropTypes.string,
      email: PropTypes.string,
    }),
  }),
};
