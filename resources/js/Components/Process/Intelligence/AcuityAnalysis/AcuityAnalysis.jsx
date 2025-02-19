import React from 'react';
import { BarChart, Bar } from 'recharts';
import { AlertTriangle, Activity } from 'lucide-react';
import ScoreCard from '../Common/ScoreCard';
import AlertPanel from '../Common/AlertPanel';
import MetricChart, { ChartTooltip } from '../Common/MetricChart';

const AcuityAnalysis = ({ metrics }) => {
  const acuityData = metrics?.acuity || {
    patientVolume: {
      count: 0,
      totalDailyPatients: 0,
      acuityBreakdown: {
        high: 0,
        medium: 0,
        low: 0
      }
    },
    expectedAcuityMix: {
      high: 0.25,
      medium: 0.50,
      low: 0.25
    }
  };

  const acuityWeights = {
    high: 1.0,
    medium: 0.6,
    low: 0.2
  };

  const calculateAcuityScore = () => {
    const totalPatients = acuityData.patientVolume.count;
    if (totalPatients === 0) return { score: 0, percentages: { high: 0, medium: 0, low: 0 } };
    
    const acuityPercentages = {
      high: acuityData.patientVolume.acuityBreakdown.high / totalPatients,
      medium: acuityData.patientVolume.acuityBreakdown.medium / totalPatients,
      low: acuityData.patientVolume.acuityBreakdown.low / totalPatients
    };

    const weightedScore = (
      acuityPercentages.high * acuityWeights.high +
      acuityPercentages.medium * acuityWeights.medium +
      acuityPercentages.low * acuityWeights.low
    );

    return {
      score: Math.round(weightedScore * 15),
      percentages: acuityPercentages
    };
  };

  const getAcuityDistributionData = () => {
    const { percentages } = calculateAcuityScore();

    return [
      {
        category: 'High Acuity',
        actual: Math.round(percentages.high * 100),
        expected: Math.round(acuityData.expectedAcuityMix.high * 100),
        weight: acuityWeights.high
      },
      {
        category: 'Medium Acuity',
        actual: Math.round(percentages.medium * 100),
        expected: Math.round(acuityData.expectedAcuityMix.medium * 100),
        weight: acuityWeights.medium
      },
      {
        category: 'Low Acuity',
        actual: Math.round(percentages.low * 100),
        expected: Math.round(acuityData.expectedAcuityMix.low * 100),
        weight: acuityWeights.low
      }
    ];
  };

  const getDeviations = () => {
    const { percentages } = calculateAcuityScore();
    const deviations = [];

    Object.entries(percentages).forEach(([level, actual]) => {
      const expected = acuityData.expectedAcuityMix[level];
      const diff = (actual - expected) * 100;
      if (Math.abs(diff) > 10) {
        deviations.push({
          level,
          diff
        });
      }
    });

    return deviations;
  };

  const acuityScoreDetails = calculateAcuityScore();
  const distData = getAcuityDistributionData();
  const deviations = getDeviations();

  const distributionTooltip = ({ active, payload, label }) => {
    if (!active || !payload || !payload.length) return null;
    const data = payload[0].payload;
    return (
      <div className="healthcare-panel border border-healthcare-border dark:border-healthcare-border-dark">
        <p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{label}</p>
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Actual: {data.actual}%</p>
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Expected: {data.expected}%</p>
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Weight: {data.weight.toFixed(1)}</p>
      </div>
    );
  };

  return (
    <div className="space-y-8">
      <div className="grid grid-cols-2 gap-6">
        <ScoreCard
          title="Acuity Mix Score"
          score={acuityScoreDetails.score}
          maxScore={15}
          icon={Activity}
          colorScheme="primary"
          details={[
            { label: 'Total Patients', value: acuityData.patientVolume.count },
            { label: 'High Acuity', value: `${acuityData.patientVolume.acuityBreakdown.high} patients` },
            { label: 'Medium Acuity', value: `${acuityData.patientVolume.acuityBreakdown.medium} patients` },
            { label: 'Low Acuity', value: `${acuityData.patientVolume.acuityBreakdown.low} patients` }
          ]}
        />

        <AlertPanel
          title="Distribution Alerts"
          icon={AlertTriangle}
          type="warning"
          alerts={deviations.map(({ level, diff }) => ({
            title: `${level} Acuity`,
            message: diff > 0 
              ? `${Math.abs(diff.toFixed(1))}% above expected - higher resource demand`
              : `${Math.abs(diff.toFixed(1))}% below expected - lower resource demand`
          }))}
        />
      </div>

      <MetricChart
        title="Acuity Distribution Comparison"
        height="64"
        yAxisLabel="Percentage of Patients"
        xAxisDataKey="category"
        tooltipContent={distributionTooltip}
      >
        <BarChart data={distData}>
          <Bar dataKey="actual" name="Actual %" fill="var(--healthcare-primary)" />
          <Bar dataKey="expected" name="Expected %" fill="var(--healthcare-success)" />
        </BarChart>
      </MetricChart>

      <div className="healthcare-card">
        <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
          Distribution Analysis
        </h3>
        <div className="grid grid-cols-3 gap-6">
          {distData.map(level => {
            const diff = level.actual - level.expected;
            return (
              <div key={level.category} className="healthcare-panel">
                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                  {level.category}
                </h4>
                <div className="space-y-1">
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Actual: {level.actual}%
                  </p>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Expected: {level.expected}%
                  </p>
                  <p className={`font-medium ${
                    diff > 0 
                      ? 'text-healthcare-critical dark:text-healthcare-critical-dark'
                      : 'text-healthcare-success dark:text-healthcare-success-dark'
                  }`}>
                    {diff > 0 ? "+" : ""}{diff}% deviation
                  </p>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
};

export default AcuityAnalysis;
