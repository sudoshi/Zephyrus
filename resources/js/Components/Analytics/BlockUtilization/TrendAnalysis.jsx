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
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold text-gray-900 mb-4">Current Period</h3>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-sm text-gray-500">Non-Prime Time</p>
              <p className="text-2xl font-bold text-gray-900">{comparative.nonPrimeTime?.toFixed(1)}%</p>
            </div>
            <div>
              <p className="text-sm text-gray-500">Prime Time Utilization</p>
              <p className="text-2xl font-bold text-gray-900">{comparative.primeTimeUtil?.toFixed(1)}%</p>
            </div>
          </div>
        </div>
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold text-gray-900 mb-4">Previous Period</h3>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-sm text-gray-500">Non-Prime Time</p>
              <p className="text-2xl font-bold text-gray-900">{previous.nonPrimeTime?.toFixed(1)}%</p>
            </div>
            <div>
              <p className="text-sm text-gray-500">Prime Time Utilization</p>
              <p className="text-2xl font-bold text-gray-900">{previous.primeTimeUtil?.toFixed(1)}%</p>
            </div>
          </div>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow p-6">
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
