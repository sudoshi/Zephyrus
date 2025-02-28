import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { Head, usePage } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import ORUtilizationDashboard from '@/Components/Analytics/ORUtilization/ORUtilizationDashboard';
import TabNavigation from '@/Components/ui/TabNavigation';

export default function ORUtilization({ auth }) {
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

  // Define menu groups similar to Block Utilization
  const menuGroups = [
    {
      title: 'Overview',
      items: [
        { id: 'overview', label: 'Overview', icon: 'carbon:analytics' },
        { id: 'trends', label: 'Trends', icon: 'carbon:chart-line' },
      ]
    },
    {
      title: 'Analysis',
      items: [
        { id: 'room', label: 'Room Analysis', icon: 'carbon:hospital' },
        { id: 'specialty', label: 'Specialty Analysis', icon: 'mdi:stethoscope' },
      ]
    },
    {
      title: 'Opportunities',
      items: [
        { id: 'opportunity', label: 'Opportunity Analysis', icon: 'carbon:idea' },
      ]
    }
  ];

  return (
    <AnalyticsLayout
      auth={auth}
      title="OR Utilization"
      header={null}
    >
      <Head title="OR Utilization" />
      <div className="space-y-6">
        {/* Top Navigation Tabs */}
        <TabNavigation 
          menuGroups={menuGroups}
          activeTab={activeTab}
          onTabChange={handleTabChange}
        />

        {/* Dashboard Component - Pass the activeTab as activeView prop */}
        <ORUtilizationDashboard activeView={activeTab} />
      </div>
    </AnalyticsLayout>
  );
}

ORUtilization.propTypes = {
  auth: PropTypes.shape({
    user: PropTypes.shape({
      id: PropTypes.number,
      name: PropTypes.string,
      email: PropTypes.string,
    }),
  }),
};
