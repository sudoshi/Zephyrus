import React, { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import RoomRunningDashboard from '@/Components/Analytics/RoomRunning/RoomRunningDashboard';
import { Tabs } from '@/Components/ui/flowbite';
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
  const handleTabChange = (tab) => {
    setActiveView(tab);
    
    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('view', tab);
    window.history.pushState({}, '', url);
  };
  
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
        <div className="mb-6">
          <Tabs style={{ base: "underline" }} onActiveTabChange={handleTabChange}>
            <Tabs.Item title="Overview" active={activeView === 'overview'} tabName="overview">
              {/* Content will be rendered by RoomRunningDashboard */}
            </Tabs.Item>
            <Tabs.Item title="Hourly Analysis" active={activeView === 'hourly'} tabName="hourly">
              {/* Content will be rendered by RoomRunningDashboard */}
            </Tabs.Item>
            <Tabs.Item title="Trends" active={activeView === 'trends'} tabName="trends">
              {/* Content will be rendered by RoomRunningDashboard */}
            </Tabs.Item>
            <Tabs.Item title="Location Comparison" active={activeView === 'location'} tabName="location">
              {/* Content will be rendered by RoomRunningDashboard */}
            </Tabs.Item>
            <Tabs.Item title="Service Analysis" active={activeView === 'service'} tabName="service">
              {/* Content will be rendered by RoomRunningDashboard */}
            </Tabs.Item>
          </Tabs>
        </div>
        
        {/* Dashboard */}
        <RoomRunningDashboard activeView={activeView} />
      </motion.div>
    </AnalyticsLayout>
  );
}
