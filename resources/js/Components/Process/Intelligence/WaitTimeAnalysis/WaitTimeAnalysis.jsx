import React, { useState, useCallback } from 'react';
import { Clock, AlertTriangle } from 'lucide-react';
import ScoreCard from '../Common/ScoreCard';
import AlertPanel from '../Common/AlertPanel';
import TimelineControls from './TimelineControls';
import WeeklyHeatmap from './WeeklyHeatmap';
import LiveMonitor from './LiveMonitor';
import InsightsPanel from './InsightsPanel';
import ComparisonTools from './ComparisonTools';

const WaitTimeAnalysis = ({ metrics }) => {
  const [selectedDate, setSelectedDate] = useState(new Date());
  const [zoomLevel, setZoomLevel] = useState(1);

  // Match the data structure from ProcessAnalysisController
  const waitTimeData = {
    current: metrics?.waitTime?.current || {},
    benchmark: metrics?.waitTime?.benchmark || {},
    peakMultipliers: metrics?.waitTime?.peakMultipliers || {},
    thresholds: {
      registration: { warning: 15, critical: 20 },
      triage: { warning: 20, critical: 30 },
      bedAssignment: { warning: 30, critical: 45 },
      physicianInitial: { warning: 35, critical: 45 },
      nurseAssessment: { warning: 25, critical: 35 }
    },
    previous: metrics?.waitTime?.current || {}, // Use current as previous if no previous data
    trends: {},
    staffing: metrics?.staffing || { 
      utilization: 0,
      nurses: {
        assigned: 0,
        required: 0
      },
      physicians: {
        assigned: 0,
        required: 0
      }
    },
    historical: [],
    targets: metrics?.waitTime?.benchmark || {} // Use benchmark as targets if no targets specified
  };

  // Calculate trends based on current and previous values
  Object.entries(waitTimeData.current).forEach(([step, value]) => {
    const prevValue = waitTimeData.previous[step] || value;
    const change = ((value - prevValue) / prevValue) * 100;
    waitTimeData.trends[step] = {
      direction: change > 0 ? 'increasing' : change < 0 ? 'decreasing' : 'steady',
      percentage: Math.abs(Math.round(change))
    };
  });

  const predictions = metrics?.predictions || {
    patternAnalysis: {
      peakHours: [],
      quietHours: [],
      weeklyPatterns: {}
    }
  };

  const calculateWaitScore = useCallback(() => {
    const steps = Object.keys(waitTimeData.current);
    if (steps.length === 0) return { score: 0, deviations: {} };

    const deviations = {};
    let totalDeviation = 0;

    steps.forEach(step => {
      const current = waitTimeData.current[step];
      const benchmark = waitTimeData.benchmark[step];
      if (current && benchmark) {
        const deviation = ((current - benchmark) / benchmark);
        deviations[step] = deviation;
        totalDeviation += deviation;
      }
    });

    const avgDeviation = totalDeviation / Object.keys(deviations).length;
    const score = Math.round((1 - avgDeviation) * 25);

    return {
      score: Math.max(0, Math.min(score, 25)),
      deviations
    };
  }, [waitTimeData]);

  const getWaitTimeData = useCallback(() => {
    return Object.entries(waitTimeData.current).map(([step, time]) => ({
      step,
      current: time,
      benchmark: waitTimeData.benchmark[step] || 0,
      deviation: waitTimeData.benchmark[step] 
        ? Math.round(((time - waitTimeData.benchmark[step]) / waitTimeData.benchmark[step]) * 100)
        : 0
    }));
  }, [waitTimeData]);

  const handleDateChange = useCallback((range) => {
    setSelectedDate(range.start);
  }, []);

  const handleZoomChange = useCallback((level) => {
    setZoomLevel(level);
  }, []);

  const handleAction = useCallback((action, params) => {
    console.log('Action:', action, params);
    // Implement action handling
  }, []);

  const handleAlert = useCallback((alerts) => {
    console.log('New Alerts:', alerts);
    // Implement alert handling
  }, []);

  const scoreDetails = calculateWaitScore();
  const waitData = getWaitTimeData();
  const criticalDelays = waitData.filter(d => d.deviation > 50);

  return (
    <div className="space-y-8">
      {/* Header Cards */}
      <div className="grid grid-cols-2 gap-6">
        <ScoreCard
          title="Wait Time Score"
          score={scoreDetails.score}
          maxScore={25}
          icon={Clock}
          colorScheme="info"
          details={Object.entries(scoreDetails.deviations).map(([step, deviation]) => ({
            label: step.replace(/([A-Z])/g, ' $1').toLowerCase(),
            value: `${Math.round(deviation * 100)}% deviation`
          }))}
        />

        <AlertPanel
          title="Critical Delays"
          icon={AlertTriangle}
          type="warning"
          alerts={criticalDelays.map(delay => ({
            title: delay.step.replace(/([A-Z])/g, ' $1').toLowerCase(),
            message: `${delay.current} min vs ${delay.benchmark} min benchmark`,
            value: `+${delay.deviation}%`
          }))}
        />
      </div>

      {/* Timeline Controls */}
      <TimelineControls
        onZoomChange={handleZoomChange}
        onRangeChange={handleDateChange}
        minDate={new Date(Date.now() - 30 * 24 * 60 * 60 * 1000)}
        maxDate={new Date()}
        currentRange={{ start: selectedDate, end: selectedDate }}
      />

      {/* Live Monitor */}
      <LiveMonitor
        data={{
          current: waitTimeData.current,
          previous: waitTimeData.previous,
          trends: waitTimeData.trends
        }}
        thresholds={waitTimeData.thresholds}
        onAlert={handleAlert}
      />

      {/* Weekly Heatmap */}
      <WeeklyHeatmap
        data={predictions.patternAnalysis.weeklyPatterns}
        minValue={0}
        maxValue={1}
      />

      {/* Insights Panel */}
      <InsightsPanel
        data={{
          current: waitTimeData.current,
          trends: waitTimeData.trends,
          staffing: waitTimeData.staffing
        }}
        thresholds={waitTimeData.thresholds}
        onAction={handleAction}
      />

      {/* Comparison Tools */}
      <ComparisonTools
        data={waitTimeData}
        benchmarks={waitTimeData.benchmark}
        historicalData={waitTimeData.historical}
        targets={waitTimeData.targets}
      />
    </div>
  );
};

export default WaitTimeAnalysis;
