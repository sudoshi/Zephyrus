import React, { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import RoomRunningDashboard from '@/Components/Analytics/RoomRunning/RoomRunningDashboard';
import TabNavigation from '@/Components/ui/TabNavigation';
import { motion } from 'framer-motion';

export default function RoomRunning({ auth }) {
  // Get the current URL from Inertia
  const { url } = usePage();
  
  // Parse the 'view' parameter from the URL or default to 'overview'
  const getInitialView = () => {
    const params = new URLSearchParams(window.location.search);
    return params.get('view') || 'overview';
  };
  
  // State for active tab
  const [activeView, setActiveView] = useState(getInitialView);
  
  // Update activeView when URL changes
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const viewParam = params.get('view') || 'overview';
    setActiveView(viewParam);
  }, [url]);
  
  // Handle tab change
  const handleTabChange = (tabId) => {
    setActiveView(tabId);
    
    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('view', tabId);
    window.history.pushState({}, '', url);
  };
  
  // Define menu groups
  const menuGroups = [
    {
      title: 'Analysis Views',
      items: [
        { id: 'overview', label: 'Overview', icon: 'carbon:dashboard' },
        { id: 'hourly', label: 'Hourly Analysis', icon: 'carbon:time' },
        { id: 'trends', label: 'Trends', icon: 'carbon:chart-line' },
      ]
    },
    {
      title: 'Detailed Analysis',
      items: [
        { id: 'location', label: 'Location Comparison', icon: 'carbon:location' },
        { id: 'service', label: 'Service Analysis', icon: 'carbon:data-table' },
      ]
    }
  ];
  
  // Animation variants for tab transitions
  const variants = {
    hidden: { opacity: 0 },
    visible: { opacity: 1, transition: { duration: 0.3 } }
  };
  
  return (
    <AnalyticsLayout
      auth={auth}
      title="Room Running"
    >
      <motion.div
        initial="hidden"
        animate="visible"
        variants={variants}
      >
        {/* Tabs */}
        <TabNavigation 
          menuGroups={menuGroups}
          activeTab={activeView}
          onTabChange={handleTabChange}
        />
        
        {/* Dashboard */}
        <RoomRunningDashboard activeView={activeView} />
      </motion.div>
    </AnalyticsLayout>
  );
}
