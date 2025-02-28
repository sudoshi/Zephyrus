import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { Head, usePage } from '@inertiajs/react';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import TurnoverTimesDashboard from '@/Components/Analytics/TurnoverTimes/TurnoverTimesDashboard';
import { Tabs } from '@/Components/ui/flowbite';
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
  const handleTabChange = (tab) => {
    setActiveTab(tab);
    
    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.pushState({}, '', url);
  };
  
  // Animation variants for tab transitions
  const variants = {
    hidden: { opacity: 0 },
    visible: { opacity: 1, transition: { duration: 0.3 } }
  };
  
  // Tab configuration
  const tabs = [
    { id: 'overview', title: 'Overview' },
    { id: 'hourly', title: 'Hourly Analysis' },
    { id: 'trends', title: 'Trends' },
    { id: 'location', title: 'Location Comparison' },
    { id: 'service', title: 'Service Analysis' }
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
        <div className="mb-4">
          <Tabs style={{ base: "underline" }}>
            {tabs.map(tab => (
              <Tabs.Item
                key={tab.id}
                title={tab.title}
                active={activeTab === tab.id}
                onClick={() => handleTabChange(tab.id)}
              />
            ))}
          </Tabs>
        </div>
        
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
    }).isRequired,
  }).isRequired,
};
