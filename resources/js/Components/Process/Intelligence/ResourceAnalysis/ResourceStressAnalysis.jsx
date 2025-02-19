import React, { useState, useEffect, useRef } from 'react';
import { Users, DoorOpen, Brain, AlertCircle } from 'lucide-react';
import ResourceOverview from './ResourceOverview';
import StaffAllocation from './StaffAllocation';
import SpaceUtilization from './SpaceUtilization';
import LoadingSpinner from '../Summary/LoadingSpinner';

const ResourceStressAnalysis = ({ metrics }) => {
  const [activeTab, setActiveTab] = useState('overview');
  const [isLoading, setIsLoading] = useState(true);
  const [criticalAlerts, setCriticalAlerts] = useState([]);
  const tabsRef = useRef(null);
  const previousTab = useRef(activeTab);

  useEffect(() => {
    // Simulate data loading
    setIsLoading(true);
    const timer = setTimeout(() => setIsLoading(false), 1000);
    return () => clearTimeout(timer);
  }, [activeTab]);

  useEffect(() => {
    // Handle keyboard navigation
    const handleKeyDown = (e) => {
      if (!tabsRef.current) return;

      const tabs = Array.from(tabsRef.current.querySelectorAll('button[role="tab"]'));
      const currentIndex = tabs.findIndex(tab => tab === document.activeElement);

      switch (e.key) {
        case 'ArrowRight':
          e.preventDefault();
          tabs[(currentIndex + 1) % tabs.length]?.focus();
          break;
        case 'ArrowLeft':
          e.preventDefault();
          tabs[currentIndex - 1 >= 0 ? currentIndex - 1 : tabs.length - 1]?.focus();
          break;
        case 'Enter':
        case ' ':
          e.preventDefault();
          if (document.activeElement?.getAttribute('role') === 'tab') {
            setActiveTab(document.activeElement.id.replace('-tab', ''));
          }
          break;
      }
    };

    tabsRef.current?.addEventListener('keydown', handleKeyDown);
    return () => tabsRef.current?.removeEventListener('keydown', handleKeyDown);
  }, []);

  useEffect(() => {
    // Announce tab changes to screen readers
    if (previousTab.current !== activeTab) {
      const message = `${tabs.find(t => t.id === activeTab)?.label} tab activated`;
      announceToScreenReader(message);
      previousTab.current = activeTab;
    }
  }, [activeTab]);

  useEffect(() => {
    // Process critical alerts
    const processAlerts = () => {
      const alerts = [];
      
      // Check staffing alerts
      Object.entries(resourceData.staffing.current).forEach(([role, data]) => {
        const utilization = data.assigned / data.required;
        if (utilization >= resourceData.staffing.thresholds.critical) {
          alerts.push({
            type: 'staffing',
            message: `Critical ${role} shortage`,
            severity: 'high',
            value: `${Math.round(utilization * 100)}%`
          });
        }
      });

      // Check space alerts
      Object.entries(resourceData.space.current).forEach(([area, data]) => {
        const utilization = data.occupied / data.capacity;
        if (utilization >= resourceData.space.thresholds.critical) {
          alerts.push({
            type: 'space',
            message: `Critical ${area} capacity`,
            severity: 'high',
            value: `${Math.round(utilization * 100)}%`
          });
        }
      });

      setCriticalAlerts(alerts);
    };

    processAlerts();
  }, [metrics]);

  const resourceData = {
    staffing: {
      current: {
        nurses: { assigned: metrics?.staffing?.nurses?.assigned || 0, required: metrics?.staffing?.nurses?.required || 0 },
        physicians: { assigned: metrics?.staffing?.physicians?.assigned || 0, required: metrics?.staffing?.physicians?.required || 0 }
      },
      weight: 0.4,
      thresholds: {
        critical: 0.9,
        high: 0.8,
        medium: 0.7
      }
    },
    space: {
      current: {
        rooms: { occupied: metrics?.space?.rooms?.occupied || 0, capacity: metrics?.space?.rooms?.capacity || 0 }
      },
      weight: 0.3,
      thresholds: {
        critical: 0.95,
        high: 0.85,
        medium: 0.75
      }
    }
  };

  const predictions = metrics?.predictions || {
    resourceUtilization: {
      nextHour: {},
      nextShift: {},
      nextDay: {}
    },
    patternAnalysis: {
      peakHours: [],
      weeklyPatterns: {}
    }
  };

  const tabs = [
    { id: 'overview', label: 'Overview', icon: Brain },
    { id: 'staff', label: 'Staff Management', icon: Users },
    { id: 'space', label: 'Space Utilization', icon: DoorOpen }
  ];

  const announceToScreenReader = (message) => {
    const announcement = document.createElement('div');
    announcement.setAttribute('aria-live', 'polite');
    announcement.setAttribute('class', 'sr-only');
    announcement.textContent = message;
    document.body.appendChild(announcement);
    setTimeout(() => document.body.removeChild(announcement), 1000);
  };

  return (
    <div className="space-y-6">
      {/* Critical Alerts */}
      {criticalAlerts.length > 0 && (
        <div className="bg-healthcare-critical/10 border border-healthcare-critical/20 rounded-lg p-4 mb-6" role="alert">
          <div className="flex items-center gap-3 mb-3">
            <AlertCircle className="h-5 w-5 text-healthcare-critical" />
            <h2 className="font-bold text-healthcare-critical">
              Critical Resource Alerts
            </h2>
          </div>
          <div className="space-y-2">
            {criticalAlerts.map((alert, index) => (
              <div 
                key={index}
                className="flex items-center justify-between bg-white dark:bg-healthcare-background-dark p-3 rounded-md"
              >
                <div className="flex items-center gap-3">
                  {alert.type === 'staffing' ? (
                    <Users className="h-5 w-5 text-healthcare-critical" />
                  ) : (
                    <DoorOpen className="h-5 w-5 text-healthcare-critical" />
                  )}
                  <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {alert.message}
                  </span>
                </div>
                <span className="font-bold text-healthcare-critical">
                  {alert.value}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Tab Navigation */}
      <div 
        ref={tabsRef}
        role="tablist"
        aria-label="Resource analysis sections"
        className="flex gap-2 p-1 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg"
      >
        {tabs.map(tab => {
          const Icon = tab.icon;
          const isActive = activeTab === tab.id;
          return (
            <button
              key={tab.id}
              id={`${tab.id}-tab`}
              role="tab"
              aria-selected={isActive}
              aria-controls={`${tab.id}-panel`}
              onClick={() => setActiveTab(tab.id)}
              className={`flex-1 flex items-center justify-center gap-2 px-4 py-3 rounded-md font-medium transition-all focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:ring-offset-2 dark:focus:ring-offset-healthcare-background-dark ${
                isActive
                  ? 'bg-white dark:bg-healthcare-background-dark text-healthcare-primary dark:text-healthcare-primary-dark shadow-sm'
                  : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark'
              }`}
            >
              <Icon className="h-5 w-5" aria-hidden="true" />
              <span>{tab.label}</span>
            </button>
          );
        })}
      </div>

      {/* Tab Content */}
      <div className="relative min-h-[400px]">
        {isLoading ? (
          <div className="absolute inset-0 flex items-center justify-center bg-white dark:bg-healthcare-background-dark bg-opacity-75 dark:bg-opacity-75 backdrop-blur-sm">
            <LoadingSpinner size="lg" />
          </div>
        ) : (
          <div className="animate-fadeIn">
            {tabs.map(tab => (
              <div
                key={tab.id}
                id={`${tab.id}-panel`}
                role="tabpanel"
                aria-labelledby={`${tab.id}-tab`}
                hidden={activeTab !== tab.id}
                className="focus:outline-none"
                tabIndex={activeTab === tab.id ? 0 : -1}
              >
                {activeTab === tab.id && (
                  <>
                    {tab.id === 'overview' && (
                      <ResourceOverview
                        resourceData={resourceData}
                        predictions={predictions}
                      />
                    )}
                    {tab.id === 'staff' && (
                      <StaffAllocation
                        resourceData={resourceData}
                        predictions={predictions}
                      />
                    )}
                    {tab.id === 'space' && (
                      <SpaceUtilization
                        resourceData={resourceData}
                        predictions={predictions}
                      />
                    )}
                  </>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};

export default ResourceStressAnalysis;
