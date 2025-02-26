import React, { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import BlockUtilizationDashboard from '@/Components/Analytics/BlockUtilization/BlockUtilizationDashboard';
import { Tabs } from 'flowbite-react';
import { Icon } from '@iconify/react';

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
  const handleTabChange = (tabIndex) => {
    const tabId = menuGroups.flatMap(group => group.items)[tabIndex].id;
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
        { id: 'details', label: 'Details', icon: 'carbon:table' },
        { id: 'nonprime', label: 'Non-Primetime Usage', icon: 'carbon:time' },
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
        <div className="healthcare-card dark:bg-gray-800 mb-6">
          <Tabs 
            aria-label="Block utilization tabs"
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
                      {item.icon && <Icon icon={item.icon} className="w-5 h-5" />}
                      <span>{item.label}</span>
                    </div>
                  }
                >
                  {/* Content will be rendered by the dashboard below */}
                </Tabs.Item>
              ))
            )}
          </Tabs>
        </div>

        {/* Main Dashboard Area */}
        <BlockUtilizationDashboard activeView={activeTab} />
      </div>
    </AnalyticsLayout>
  );
}
