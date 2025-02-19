import React, { useState } from 'react';
import { Brain, X, Share2, Clock, Activity, AlertTriangle } from 'lucide-react';
import ResourceStressAnalysis from './Intelligence/ResourceAnalysis/ResourceStressAnalysis';
import CascadeAnalysis from './Intelligence/CascadeAnalysis/CascadeAnalysis';
import WaitTimeAnalysis from './Intelligence/WaitTimeAnalysis/WaitTimeAnalysis';
import AcuityAnalysis from './Intelligence/AcuityAnalysis/AcuityAnalysis';
import BottleneckSummary from './Intelligence/Summary/BottleneckSummary';

const ProcessIntelligenceModal = ({ isOpen, onClose, metrics }) => {
  const [activeTab, setActiveTab] = useState('summary');

  if (!isOpen) return null;

  const tabs = [
    { id: 'summary', label: 'Top Bottlenecks', icon: Brain },
    { id: 'resource', label: 'Resource Stress', icon: Activity },
    { id: 'cascade', label: 'Cascade Impact', icon: Share2 },
    { id: 'wait', label: 'Wait Time', icon: Clock },
    { id: 'acuity', label: 'Acuity Mix', icon: AlertTriangle }
  ];

  const renderTabContent = () => {
    switch (activeTab) {
      case 'resource':
        return <ResourceStressAnalysis metrics={metrics} />;
      case 'cascade':
        return <CascadeAnalysis metrics={metrics} />;
      case 'wait':
        return <WaitTimeAnalysis metrics={metrics} />;
      case 'acuity':
        return <AcuityAnalysis metrics={metrics} />;
      case 'summary':
        return <BottleneckSummary metrics={metrics} />;
      default:
        return null;
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div className="bg-white dark:bg-healthcare-background-dark rounded-lg shadow-xl w-full max-w-6xl mx-4 max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="flex justify-between items-center px-6 py-4 border-b border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark">
          <h2 className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark flex items-center gap-2">
            <Brain className="h-6 w-6" />
            Process Intelligence
          </h2>
          <button
            onClick={onClose}
            className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:text-healthcare-primary dark:hover:text-healthcare-primary-dark p-2 rounded-full hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark healthcare-transition"
          >
            <X className="h-6 w-6" />
          </button>
        </div>

        {/* Tabs */}
        <div className="border-b border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark">
          <div className="px-6 flex space-x-2">
            {tabs.map(tab => (
              <button
                key={tab.id}
                className={`min-h-[44px] px-4 font-medium flex items-center gap-2 border-b-2 healthcare-transition ${
                  activeTab === tab.id
                    ? 'text-healthcare-primary dark:text-healthcare-primary-dark border-healthcare-primary dark:border-healthcare-primary-dark'
                    : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark border-transparent hover:text-healthcare-primary dark:hover:text-healthcare-primary-dark hover:border-healthcare-primary dark:hover:border-healthcare-primary-dark'
                }`}
                onClick={() => setActiveTab(tab.id)}
              >
                <tab.icon className="h-4 w-4" />
                {tab.label}
              </button>
            ))}
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6 bg-healthcare-surface dark:bg-healthcare-surface-dark">
          {renderTabContent()}
        </div>
      </div>
    </div>
  );
};

export default ProcessIntelligenceModal;
