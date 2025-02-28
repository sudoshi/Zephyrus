import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { Head, Link, usePage } from '@inertiajs/react';
import AnalyticsLayout from '../../Layouts/AnalyticsLayout';
import PrimetimeUtilizationDashboard from '../../Components/Analytics/PrimetimeUtilization/PrimetimeUtilizationDashboard';
import TabNavigation from '@/Components/ui/TabNavigation';

export default function PrimetimeUtilization({ auth }) {
  const { url } = usePage();
  const params = new URLSearchParams(window.location.search);
  const viewParam = params.get('view') || 'overview';
  
  const [activeTab, setActiveTab] = useState(viewParam);
  const [showDevHeader, setShowDevHeader] = useState(false);

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

  const menuGroups = [
    {
      title: 'Analysis Views',
      items: [
        { id: 'overview', label: 'Overview', icon: 'carbon:dashboard' },
        { id: 'trends', label: 'Utilization Trends', icon: 'carbon:chart-line' },
        { id: 'dayOfWeek', label: 'Day of Week Analysis', icon: 'carbon:calendar-heat-map' },
      ]
    },
    {
      title: 'Detailed Analysis',
      items: [
        { id: 'location', label: 'Location Comparison', icon: 'carbon:location' },
        { id: 'provider', label: 'Provider Analysis', icon: 'carbon:user-profile' },
        { id: 'service', label: 'Service Analysis', icon: 'carbon:data-table' },
      ]
    }
  ];

  return (
    <AnalyticsLayout
      auth={auth}
      title="Primetime Utilization"
      header={null}
    >
      <Head title="Primetime Utilization" />
      <div className="space-y-6">
        {/* Hidden dev header - double click to show */}
        <div 
          className="h-4 cursor-default"
          onDoubleClick={() => setShowDevHeader(!showDevHeader)}
        >
          {showDevHeader && (
            <div className="flex items-center py-4 mb-2 border-b border-healthcare-border dark:border-healthcare-border-dark">
              <h2 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Primetime Utilization (Dev Mode)
              </h2>
            </div>
          )}
        </div>
        
        {/* Top Navigation Tabs */}
        <TabNavigation 
          menuGroups={menuGroups}
          activeTab={activeTab}
          onTabChange={handleTabChange}
        />
        
        {/* Dashboard Component */}
        <PrimetimeUtilizationDashboard activeView={activeTab} />
      </div>
    </AnalyticsLayout>
  );
}

PrimetimeUtilization.propTypes = {
  auth: PropTypes.shape({
    user: PropTypes.shape({
      id: PropTypes.number,
      name: PropTypes.string,
      email: PropTypes.string,
    }),
  }),
};
