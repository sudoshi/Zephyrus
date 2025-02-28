import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { Head, Link, usePage } from '@inertiajs/react';
import AnalyticsLayout from '../../Layouts/AnalyticsLayout';
import PrimetimeUtilizationDashboard from '../../Components/Analytics/PrimetimeUtilization/PrimetimeUtilizationDashboard';
import { Icon } from '@iconify/react';

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

  // Custom TabButton component
  const TabButton = ({ id, label, icon }) => (
    <button
      className={`flex items-center gap-2 px-4 py-2 rounded-t-lg ${
        activeTab === id
          ? 'bg-blue-600 text-white'
          : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'
      }`}
      onClick={() => handleTabChange(id)}
    >
      <Icon icon={icon} className="w-5 h-5" />
      {label}
    </button>
  );

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
        <div className="healthcare-card dark:bg-gray-800 mb-6">
          <div className="flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-700 pb-2">
            {menuGroups.flatMap(group => 
              group.items.map(item => (
                <TabButton 
                  key={item.id}
                  id={item.id}
                  label={item.label}
                  icon={item.icon}
                />
              ))
            )}
          </div>
        </div>

        {/* Main Dashboard Area */}
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
    }).isRequired,
  }).isRequired,
};
