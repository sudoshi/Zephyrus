import React, { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import BlockUtilizationDashboard from '@/Components/Analytics/BlockUtilization/BlockUtilizationDashboard';
import TabNavigation from '@/Components/ui/TabNavigation';

export default function BlockUtilization({ auth }) {
  const { url } = usePage();
  const params = new URLSearchParams(window.location.search);
  const viewParam = params.get('view') || 'service';
  
  const [activeTab, setActiveTab] = useState(viewParam);
  const [showDevHeader, setShowDevHeader] = useState(false);

  // Update the activeTab when URL changes
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const viewParam = params.get('view') || 'service';
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
        { id: 'service', label: 'By Service', icon: 'carbon:analytics' },
        { id: 'trend', label: 'Comparative Trend', icon: 'carbon:chart-line' },
        { id: 'dayOfWeek', label: 'Day of Week', icon: 'carbon:calendar-heat-map' },
      ]
    },
    {
      title: 'Organization',
      items: [
        { id: 'location', label: 'By Location/Group', icon: 'carbon:location' },
        { id: 'block', label: 'By Block Group', icon: 'carbon:group' },
      ]
    },
    {
      title: 'Details',
      items: [
        { id: 'nonprime', label: 'Non-Primetime Usage', icon: 'carbon:time' },
        { id: 'details', label: 'Details', icon: 'carbon:table' },
      ]
    }
  ];

  return (
    <AnalyticsLayout
      auth={auth}
      title="Block Utilization"
      header={null}
    >
      <Head title="Block Utilization" />
      <div className="space-y-6">
        {/* Hidden dev header - double click to show */}
        <div 
          className="h-4 cursor-default"
          onDoubleClick={() => setShowDevHeader(!showDevHeader)}
        >
          {showDevHeader && (
            <div className="flex items-center py-4 mb-2 border-b border-healthcare-border dark:border-healthcare-border-dark">
              <h2 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Block Utilization (Dev Mode)
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
        <BlockUtilizationDashboard activeView={activeTab} />
      </div>
    </AnalyticsLayout>
  );
}
