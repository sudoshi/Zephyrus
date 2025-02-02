import React from 'react';
import PropTypes from 'prop-types';
import { Icon } from '@iconify/react';

const SummaryCard = ({ title, value, trend, icon, color, isDarkMode }) => (
  <div className={`${isDarkMode ? 'bg-gray-800' : 'bg-white'} rounded-lg shadow-sm p-4 flex items-center space-x-4`}>
    <div className={`p-3 rounded-lg ${color}`}>
      <Icon icon={icon} className="w-6 h-6 text-white" />
    </div>
    <div>
      <h3 className={`text-sm font-medium ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{title}</h3>
      <div className="flex items-center mt-1">
        <p className={`text-2xl font-bold ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>{value}</p>
        {trend && (
          <span className={`ml-2 flex items-center text-sm ${
            trend > 0 ? 'text-green-600' : 'text-red-600'
          }`}>
            <Icon 
              icon={trend > 0 ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
              className="w-4 h-4 mr-1"
            />
            {Math.abs(trend)}%
          </span>
        )}
      </div>
    </div>
  </div>
);

const SummaryCards = ({ metrics, isDarkMode = false }) => {
  const cards = [
    {
      title: 'Overall Block Utilization',
      value: `${metrics.overallUtilization.toFixed(1)}%`,
      trend: metrics.utilizationTrend,
      icon: 'heroicons:clock',
      color: isDarkMode ? 'bg-blue-500' : 'bg-blue-600'
    },
    {
      title: 'Prime Time Usage',
      value: `${metrics.primeTimeUsage.toFixed(1)}%`,
      trend: metrics.primeTimeTrend,
      icon: 'heroicons:sun',
      color: isDarkMode ? 'bg-amber-500' : 'bg-amber-600'
    },
    {
      title: 'Total Cases',
      value: metrics.totalCases,
      trend: metrics.casesTrend,
      icon: 'heroicons:user-group',
      color: isDarkMode ? 'bg-green-500' : 'bg-green-600'
    },
    {
      title: 'Out of Block Cases',
      value: metrics.outOfBlockCases,
      trend: metrics.outOfBlockTrend,
      icon: 'heroicons:exclamation-triangle',
      color: isDarkMode ? 'bg-red-500' : 'bg-red-600'
    }
  ];

  return (
    <div className="grid grid-cols-4 gap-4">
      {cards.map(card => (
        <SummaryCard key={card.title} {...card} isDarkMode={isDarkMode} />
      ))}
    </div>
  );
};

SummaryCard.propTypes = {
  title: PropTypes.string.isRequired,
  value: PropTypes.oneOfType([PropTypes.string, PropTypes.number]).isRequired,
  trend: PropTypes.number,
  icon: PropTypes.string.isRequired,
  color: PropTypes.string.isRequired,
  isDarkMode: PropTypes.bool
};

SummaryCard.defaultProps = {
  trend: null,
  isDarkMode: false
};

SummaryCards.propTypes = {
  metrics: PropTypes.shape({
    overallUtilization: PropTypes.number.isRequired,
    utilizationTrend: PropTypes.number.isRequired,
    primeTimeUsage: PropTypes.number.isRequired,
    primeTimeTrend: PropTypes.number.isRequired,
    totalCases: PropTypes.number.isRequired,
    casesTrend: PropTypes.number.isRequired,
    outOfBlockCases: PropTypes.number.isRequired,
    outOfBlockTrend: PropTypes.number.isRequired
  }).isRequired,
  isDarkMode: PropTypes.bool
};

SummaryCards.defaultProps = {
  isDarkMode: false
};

export default SummaryCards;
