import React, { useState, useCallback } from 'react';
import { Share2, AlertTriangle } from 'lucide-react';
import ScoreCard from '../Common/ScoreCard';
import AlertPanel from '../Common/AlertPanel';
import PredictiveAnalysis from './PredictiveAnalysis';
import ProcessTimeline from './ProcessTimeline';

const CascadeAnalysis = ({ metrics }) => {
  const [activeTab, setActiveTab] = useState('timeline');
  const [selectedScenario, setSelectedScenario] = useState(null);

  const cascadeData = metrics?.cascade || {
    primaryProcess: '',
    affectedProcesses: []
  };

  const calculateCascadeScore = useCallback(() => {
    const processes = cascadeData.affectedProcesses;
    if (processes.length === 0) return { score: 0, impacts: {} };

    const impacts = {
      critical: processes.filter(p => p.severity > 0.8).length,
      high: processes.filter(p => p.severity > 0.6 && p.severity <= 0.8).length,
      medium: processes.filter(p => p.severity > 0.4 && p.severity <= 0.6).length,
      low: processes.filter(p => p.severity <= 0.4).length
    };

    // Weight based on severity distribution
    const score = Math.round(
      (20 - (
        (impacts.critical * 5) +
        (impacts.high * 3) +
        (impacts.medium * 2) +
        (impacts.low * 1)
      ))
    );

    return {
      score: Math.max(0, score),
      impacts
    };
  }, [cascadeData]);

  const scoreDetails = calculateCascadeScore();
  const criticalProcesses = cascadeData.affectedProcesses.filter(p => p.severity > 0.7);

  const tabs = [
    { id: 'timeline', label: 'Timeline' },
    { id: 'prediction', label: 'Impact Prediction' }
  ];

  const handleScenarioChange = useCallback((scenario) => {
    setSelectedScenario(scenario);
  }, []);

  return (
    <div className="space-y-8">
      {/* Header Cards */}
      <div className="grid grid-cols-2 gap-6">
        <ScoreCard
          title="Cascade Impact Score"
          score={scoreDetails.score}
          maxScore={20}
          icon={Share2}
          colorScheme="warning"
          details={[
            { label: 'Critical Impacts', value: `${scoreDetails.impacts.critical} processes` },
            { label: 'High Impacts', value: `${scoreDetails.impacts.high} processes` },
            { label: 'Medium Impacts', value: `${scoreDetails.impacts.medium} processes` },
            { label: 'Low Impacts', value: `${scoreDetails.impacts.low} processes` }
          ]}
        />

        <AlertPanel
          title="Critical Process Impacts"
          icon={AlertTriangle}
          type="warning"
          alerts={criticalProcesses.map(process => ({
            title: process.name,
            message: `${Math.round(process.severity * 100)}% severity impact`,
            value: `${process.timeImpact}min delay`
          }))}
        />
      </div>

      {/* Tab Navigation */}
      <div className="border-b border-healthcare-border dark:border-healthcare-border-dark">
        <div className="flex gap-6">
          {tabs.map(tab => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`px-4 py-2 font-medium text-sm border-b-2 transition-colors ${
                activeTab === tab.id
                  ? 'border-healthcare-primary text-healthcare-primary dark:border-healthcare-primary-dark dark:text-healthcare-primary-dark'
                  : 'border-transparent text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* Tab Content */}
      <div className="animate-fadeIn">
        {activeTab === 'timeline' && (
          <ProcessTimeline
            cascadeData={cascadeData}
          />
        )}

        {activeTab === 'prediction' && (
          <PredictiveAnalysis
            cascadeData={cascadeData}
            onScenarioChange={handleScenarioChange}
          />
        )}
      </div>
    </div>
  );
};

export default CascadeAnalysis;
