import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { Card } from '@/Components/ui/flowbite';
import { LineChart } from '@/Components/ui/charts';
import { NivoThemeProvider } from '@/Components/ui';
import { TrendingUp, Calendar, AlertTriangle } from 'lucide-react';

/**
 * Component for displaying utilization trends in the OR Utilization Dashboard
 */
const UtilizationTrendsCard = ({ 
  trendsData = [],
  comparisonTrendsData = [],
  showComparison = false,
  className = ''
}) => {
  // Validate data
  const isValidData = useMemo(() => {
    return Array.isArray(trendsData) && trendsData.length > 0;
  }, [trendsData]);
  
  // Format data for line chart
  const chartData = useMemo(() => {
    if (!isValidData) {
      return [{ id: 'No Data', data: [] }];
    }
    
    try {
      const currentPeriodData = trendsData.map(point => {
        if (!point || typeof point !== 'object') {
          console.warn('Invalid trend data point:', point);
          return { x: 'Unknown', y: 0 };
        }
        
        return {
          x: point.date || 'Unknown',
          y: typeof point.utilization === 'number' ? point.utilization : 0
        };
      });
      
      const comparisonPeriodData = Array.isArray(comparisonTrendsData) 
        ? comparisonTrendsData.map(point => {
            if (!point || typeof point !== 'object') {
              return { x: 'Unknown', y: 0 };
            }
            
            return {
              x: point.date || 'Unknown',
              y: typeof point.utilization === 'number' ? point.utilization : 0
            };
          })
        : [];
      
      const result = [
        {
          id: 'Current Period',
          data: currentPeriodData
        }
      ];
      
      if (showComparison && comparisonPeriodData.length > 0) {
        result.push({
          id: 'Comparison Period',
          data: comparisonPeriodData
        });
      }
      
      return result;
    } catch (err) {
      console.error('Error formatting chart data:', err);
      return [{ id: 'Error', data: [] }];
    }
  }, [trendsData, comparisonTrendsData, showComparison, isValidData]);
  
  // Calculate average utilization for current period
  const averageUtilization = useMemo(() => {
    if (!isValidData) return 0;
    
    try {
      const validPoints = trendsData.filter(point => 
        point && typeof point === 'object' && typeof point.utilization === 'number'
      );
      
      if (validPoints.length === 0) return 0;
      
      return validPoints.reduce((sum, point) => sum + point.utilization, 0) / validPoints.length;
    } catch (err) {
      console.error('Error calculating average utilization:', err);
      return 0;
    }
  }, [trendsData, isValidData]);
  
  // Calculate average utilization for comparison period
  const averageComparisonUtilization = useMemo(() => {
    if (!Array.isArray(comparisonTrendsData) || comparisonTrendsData.length === 0) return 0;
    
    try {
      const validPoints = comparisonTrendsData.filter(point => 
        point && typeof point === 'object' && typeof point.utilization === 'number'
      );
      
      if (validPoints.length === 0) return 0;
      
      return validPoints.reduce((sum, point) => sum + point.utilization, 0) / validPoints.length;
    } catch (err) {
      console.error('Error calculating comparison average utilization:', err);
      return 0;
    }
  }, [comparisonTrendsData]);
  
  // Calculate utilization change
  const utilizationChange = useMemo(() => {
    if (averageComparisonUtilization === 0) return 0;
    return ((averageUtilization - averageComparisonUtilization) / averageComparisonUtilization) * 100;
  }, [averageUtilization, averageComparisonUtilization]);
  
  // Determine color based on utilization change
  const getChangeColor = (change) => {
    if (change > 5) return 'text-green-500';
    if (change > 0) return 'text-blue-500';
    if (change > -5) return 'text-yellow-500';
    return 'text-red-500';
  };
  
  const changeColor = getChangeColor(utilizationChange);
  
  // Find peak and trough days
  const peakDay = useMemo(() => {
    if (!isValidData) return null;
    
    try {
      const validPoints = trendsData.filter(point => 
        point && typeof point === 'object' && typeof point.utilization === 'number'
      );
      
      if (validPoints.length === 0) return null;
      
      return validPoints.reduce((max, point) => 
        point.utilization > (max.utilization || 0) ? point : max, validPoints[0]);
    } catch (err) {
      console.error('Error finding peak day:', err);
      return null;
    }
  }, [trendsData, isValidData]);
  
  const troughDay = useMemo(() => {
    if (!isValidData) return null;
    
    try {
      const validPoints = trendsData.filter(point => 
        point && typeof point === 'object' && typeof point.utilization === 'number'
      );
      
      if (validPoints.length === 0) return null;
      
      return validPoints.reduce((min, point) => 
        point.utilization < (min.utilization || Number.MAX_VALUE) ? point : min, validPoints[0]);
    } catch (err) {
      console.error('Error finding trough day:', err);
      return null;
    }
  }, [trendsData, isValidData]);
  
  return (
    <Card className={`healthcare-card ${className}`}>
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center">
          <TrendingUp className="h-5 w-5 mr-2 text-healthcare-primary dark:text-healthcare-primary-dark" />
          <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Utilization Trends
          </h3>
        </div>
        
        <div className="flex space-x-4">
          <div className="text-right">
            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Avg Utilization
            </div>
            <div className="text-lg font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {averageUtilization.toFixed(1)}%
            </div>
          </div>
          
          {showComparison && (
            <div className="text-right">
              <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                vs. Comparison
              </div>
              <div className={`text-lg font-bold ${changeColor}`}>
                {utilizationChange > 0 ? '+' : ''}{utilizationChange.toFixed(1)}%
              </div>
            </div>
          )}
        </div>
      </div>
      
      {/* Line Chart */}
      <div className="h-80">
        {!isValidData ? (
          <div className="flex flex-col items-center justify-center h-full bg-gray-50 dark:bg-gray-800 rounded-lg">
            <AlertTriangle className="h-12 w-12 text-yellow-500 mb-4" />
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">No Trend Data Available</h3>
            <p className="text-sm text-gray-500 dark:text-gray-400 mt-2 text-center max-w-md">
              There is no utilization trend data available for the selected filters.
              Try selecting a different time period or location.
            </p>
          </div>
        ) : (
          <NivoThemeProvider>
            <LineChart 
              data={chartData}
              margin={{ top: 10, right: 110, bottom: 50, left: 60 }}
              xScale={{ type: 'point' }}
              yScale={{ 
                type: 'linear', 
                min: 'auto', 
                max: 'auto', 
                stacked: false, 
                reverse: false 
              }}
              axisTop={null}
              axisRight={null}
              axisBottom={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: -45,
                legend: 'Date',
                legendOffset: 40,
                legendPosition: 'middle'
              }}
              axisLeft={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Utilization (%)',
                legendOffset: -50,
                legendPosition: 'middle'
              }}
              colors={{ scheme: 'category10' }}
              pointSize={10}
              pointColor={{ theme: 'background' }}
              pointBorderWidth={2}
              pointBorderColor={{ from: 'serieColor' }}
              pointLabelYOffset={-12}
              useMesh={true}
              legends={[
                {
                  anchor: 'bottom-right',
                  direction: 'column',
                  justify: false,
                  translateX: 100,
                  translateY: 0,
                  itemsSpacing: 0,
                  itemDirection: 'left-to-right',
                  itemWidth: 80,
                  itemHeight: 20,
                  itemOpacity: 0.75,
                  symbolSize: 12,
                  symbolShape: 'circle',
                  symbolBorderColor: 'rgba(0, 0, 0, .5)'
                }
              ]}
            />
          </NivoThemeProvider>
        )}
      </div>
      
      {/* Insights */}
      <div className="mt-4 pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
        <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2 flex items-center">
          <Calendar className="h-4 w-4 mr-2" />
          Trend Insights
        </h4>
        {!isValidData ? (
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark italic">
            No insights available due to insufficient data.
          </p>
        ) : (
          <ul className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark space-y-1">
            {peakDay && (
              <li className="flex items-start">
                <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
                <span>Peak utilization of {peakDay.utilization.toFixed(1)}% occurred on {peakDay.date}.</span>
              </li>
            )}
            {troughDay && (
              <li className="flex items-start">
                <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
                <span>Lowest utilization of {troughDay.utilization.toFixed(1)}% occurred on {troughDay.date}.</span>
              </li>
            )}
            {showComparison && (
              <li className="flex items-start">
                <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
                <span>
                  {utilizationChange > 0 
                    ? `Utilization has improved by ${utilizationChange.toFixed(1)}% compared to the previous period.`
                    : `Utilization has decreased by ${Math.abs(utilizationChange).toFixed(1)}% compared to the previous period.`
                  }
                </span>
              </li>
            )}
          </ul>
        )}
      </div>
    </Card>
  );
};

UtilizationTrendsCard.propTypes = {
  trendsData: PropTypes.arrayOf(
    PropTypes.shape({
      date: PropTypes.string.isRequired,
      utilization: PropTypes.number
    })
  ),
  comparisonTrendsData: PropTypes.arrayOf(
    PropTypes.shape({
      date: PropTypes.string.isRequired,
      utilization: PropTypes.number
    })
  ),
  showComparison: PropTypes.bool,
  className: PropTypes.string
};

export default UtilizationTrendsCard;
