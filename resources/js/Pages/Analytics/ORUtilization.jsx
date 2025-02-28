import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { Head, usePage } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import ORUtilizationDashboard from '@/Components/Analytics/ORUtilization/ORUtilizationDashboard';
import { Tabs } from 'flowbite-react';
import { Icon } from '@iconify/react';

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
  const handleTabChange = (tabIndex) => {
    const tabId = menuGroups.flatMap(group => group.items)[tabIndex].id;
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
        { id: 'specialty', label: 'Specialty Analysis', icon: 'carbon:user-medical' },
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
        <div className="healthcare-card dark:bg-gray-800 mb-6">
          <Tabs 
            aria-label="OR utilization tabs"
            style={{ base: "underline" }}
            onActiveTabChange={handleTabChange}
            theme={{
              tablist: {
                base: "flex flex-wrap -mb-px",
                styles: {
                  underline: {
                    base: "flex-wrap -mb-px border-b border-gray-200 dark:border-gray-700",
                    tabitem: {
                      base: "flex items-center justify-center p-4 rounded-t-lg text-sm font-medium first:ml-0 disabled:cursor-not-allowed disabled:text-gray-400 disabled:dark:text-gray-500 focus:outline-none",
                      styles: {
                        default: {
                          base: "rounded-t-lg",
                          active: {
                            on: "bg-gray-100 dark:bg-gray-800 text-blue-600 dark:text-blue-500 rounded-t-lg border-b-2 border-blue-600 dark:border-blue-500 active",
                            off: "border-b-2 border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-300"
                          }
                        }
                      }
                    }
                  }
                }
              },
              tabpanel: "py-3"
            }}
          >
            {menuGroups.flatMap(group => 
              group.items.map(item => (
                <Tabs.Item 
                  key={item.id}
                  title={
                    <div className="flex items-center gap-2">
                      <Icon icon={item.icon} width="20" height="20" />
                      <span>{item.label}</span>
                    </div>
                  }
                  active={activeTab === item.id}
                >
                  {/* Tab content is rendered by the dashboard component */}
                </Tabs.Item>
              ))
            )}
          </Tabs>
        </div>

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
    }).isRequired,
  }).isRequired,
};
