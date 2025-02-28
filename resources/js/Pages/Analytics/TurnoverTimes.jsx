import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { Head, usePage } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import TurnoverTimesDashboard from '@/Components/Analytics/TurnoverTimes/TurnoverTimesDashboard';
import TabNavigation from '@/Components/ui/TabNavigation';
import { motion } from 'framer-motion';

export default function TurnoverTimes({ auth }) {
  // Get the current URL from Inertia
  const { url } = usePage();
  
  // Parse the 'tab' parameter from the URL or default to 'overview'
  const getInitialTab = () => {
    const params = new URLSearchParams(window.location.search);
    return params.get('tab') || 'overview';
  };
  
  // State for active tab
  const [activeTab, setActiveTab] = useState(getInitialTab);
  
  // Update activeTab when URL changes
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const tabParam = params.get('tab') || 'overview';
    setActiveTab(tabParam);
  }, [url]);
  
  // Handle tab change
  const handleTabChange = (tabId) => {
    setActiveTab(tabId);
    
    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.pushState({}, '', url);
  };
  
  // Animation variants for tab transitions
  const variants = {
    hidden: { opacity: 0 },
    visible: { opacity: 1, transition: { duration: 0.3 } }
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

  return (
    <AnalyticsLayout
      auth={auth}
      title="Turnover Times"
    >
      <Head title="Turnover Times" />
      
      <motion.div
        initial="hidden"
        animate="visible"
        variants={variants}
        className="space-y-6"
      >
        {/* Top Navigation Tabs */}
        <TabNavigation 
          menuGroups={menuGroups}
          activeTab={activeTab}
          onTabChange={handleTabChange}
        />
        
        <TurnoverTimesDashboard activeView={activeTab} />
      </motion.div>
    </AnalyticsLayout>
  );
}

TurnoverTimes.propTypes = {
  auth: PropTypes.shape({
    user: PropTypes.shape({
      id: PropTypes.number,
      name: PropTypes.string,
      email: PropTypes.string,
    }),
  }),
};
