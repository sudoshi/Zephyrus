import React from 'react';
import PropTypes from 'prop-types';
import LineChart from '../../Dashboard/Charts/LineChart';
import { TrendDataPropType } from './types';

const TrendAnalysis = ({ siteData }) => {
  // Format trend data for the chart
  const formatChartData = () => {
    if (!siteData?.utilization || !siteData?.nonPrimeTime) return [];

    return siteData.utilization.map((point, index) => ({
      date: point.month,
      value: point.value,
      breakdown: {
        'Non-Prime Time': siteData.nonPrimeTime[index].value
      }
    }));
  };

  const chartOptions = {
    responsive: true,
    plugins: {
      legend: {
        position: 'top'
      },
      title: {
        display: true,
        text: 'Block Utilization and Non-Prime Time Trends'
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        max: 100,
        title: {
          display: true,
          text: 'Percentage (%)'
        }
      }
    }
  };

  // Comparative metrics section
  const comparative = siteData?.comparative?.current || {};
  const previous = siteData?.comparative?.previous || {};

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-4">
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Current Period</h3>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Non-Prime Time</p>
              <p className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{comparative.nonPrimeTime?.toFixed(1)}%</p>
            </div>
            <div>
              <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Prime Time Utilization</p>
              <p className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{comparative.primeTimeUtil?.toFixed(1)}%</p>
            </div>
          </div>
        </div>
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Previous Period</h3>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Non-Prime Time</p>
              <p className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{previous.nonPrimeTime?.toFixed(1)}%</p>
            </div>
            <div>
              <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Prime Time Utilization</p>
              <p className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{previous.primeTimeUtil?.toFixed(1)}%</p>
            </div>
          </div>
        </div>
      </div>

      <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-6">
        <div className="h-96">
          <LineChart data={formatChartData()} />
        </div>
      </div>
    </div>
  );
};

TrendAnalysis.propTypes = {
  siteData: TrendDataPropType
};

TrendAnalysis.defaultProps = {
  siteData: null
};

export default TrendAnalysis;
