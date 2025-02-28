import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { Card } from '@/Components/ui/flowbite';
import { PieChart } from '@/Components/ui/charts';
import { NivoThemeProvider } from '@/Components/ui';
import { Users, PieChart as PieChartIcon } from 'lucide-react';

/**
 * Component for displaying specialty distribution in the OR Utilization Dashboard
 */
const SpecialtyDistributionCard = ({ 
  specialtyData = {},
  className = ''
}) => {
  // Format data for pie chart
  const chartData = useMemo(() => {
    const data = Object.entries(specialtyData).map(([specialty, metrics]) => ({
      id: specialty,
      label: specialty,
      value: metrics.totalCases || 0
    }));
    
    // Sort by value descending
    return data.sort((a, b) => b.value - a.value);
  }, [specialtyData]);
  
  // Calculate total cases
  const totalCases = useMemo(() => {
    return chartData.reduce((sum, item) => sum + item.value, 0);
  }, [chartData]);
  
  // Calculate percentages for the table
  const specialtyTable = useMemo(() => {
    return chartData.map(item => ({
      specialty: item.label,
      cases: item.value,
      percentage: totalCases > 0 ? (item.value / totalCases) * 100 : 0
    }));
  }, [chartData, totalCases]);
  
  return (
    <Card className={`healthcare-card ${className}`}>
      <div className="flex items-center mb-4">
        <PieChartIcon className="h-5 w-5 mr-2 text-healthcare-primary dark:text-healthcare-primary-dark" />
        <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Specialty Distribution
        </h3>
      </div>
      
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Pie Chart */}
        <div className="h-64">
          <NivoThemeProvider>
            <PieChart 
              data={chartData}
              margin={{ top: 10, right: 10, bottom: 10, left: 10 }}
              innerRadius={0.5}
              padAngle={0.7}
              cornerRadius={3}
              activeOuterRadiusOffset={8}
              borderWidth={1}
              borderColor={{ from: 'color', modifiers: [['darker', 0.2]] }}
              arcLinkLabelsSkipAngle={10}
              arcLinkLabelsTextColor="#333333"
              arcLinkLabelsThickness={2}
              arcLinkLabelsColor={{ from: 'color' }}
              arcLabelsSkipAngle={10}
              arcLabelsTextColor={{ from: 'color', modifiers: [['darker', 2]] }}
              legends={[]}
            />
          </NivoThemeProvider>
        </div>
        
        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-sm text-left text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            <thead className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark">
              <tr>
                <th scope="col" className="px-4 py-2">Specialty</th>
                <th scope="col" className="px-4 py-2 text-right">Cases</th>
                <th scope="col" className="px-4 py-2 text-right">%</th>
              </tr>
            </thead>
            <tbody>
              {specialtyTable.map((item, index) => (
                <tr 
                  key={item.specialty} 
                  className={`border-b border-healthcare-border dark:border-healthcare-border-dark ${
                    index % 2 === 0 ? '' : 'bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark'
                  }`}
                >
                  <td className="px-4 py-2 font-medium">{item.specialty}</td>
                  <td className="px-4 py-2 text-right">{item.cases}</td>
                  <td className="px-4 py-2 text-right">{item.percentage.toFixed(1)}%</td>
                </tr>
              ))}
              <tr className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark font-medium">
                <td className="px-4 py-2">Total</td>
                <td className="px-4 py-2 text-right">{totalCases}</td>
                <td className="px-4 py-2 text-right">100%</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      
      {/* Insights */}
      <div className="mt-4 pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
        <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2 flex items-center">
          <Users className="h-4 w-4 mr-2" />
          Distribution Insights
        </h4>
        <ul className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark space-y-1">
          {chartData.length > 0 && (
            <li className="flex items-start">
              <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
              <span>{chartData[0].label} represents the highest volume specialty with {chartData[0].value} cases ({(chartData[0].value / totalCases * 100).toFixed(1)}% of total).</span>
            </li>
          )}
          {chartData.length > 2 && (
            <li className="flex items-start">
              <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
              <span>Top 3 specialties account for {(
                (chartData[0].value + chartData[1].value + chartData[2].value) / totalCases * 100
              ).toFixed(1)}% of total case volume.</span>
            </li>
          )}
          <li className="flex items-start">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
            <span>Consider specialty-specific optimization strategies for high-volume services.</span>
          </li>
        </ul>
      </div>
    </Card>
  );
};

SpecialtyDistributionCard.propTypes = {
  specialtyData: PropTypes.object,
  className: PropTypes.string
};

export default SpecialtyDistributionCard;
