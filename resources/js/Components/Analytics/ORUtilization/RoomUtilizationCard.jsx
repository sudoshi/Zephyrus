import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { Card } from '@/Components/ui/flowbite';
import { BarChart } from '@/Components/ui/charts';
import { NivoThemeProvider } from '@/Components/ui';
import { BarChart2, TrendingUp } from 'lucide-react';

/**
 * Component for displaying room utilization in the OR Utilization Dashboard
 */
const RoomUtilizationCard = ({ 
  roomData = [],
  className = ''
}) => {
  // Format data for bar chart with defensive coding
  const chartData = useMemo(() => {
    // Ensure roomData is an array
    if (!Array.isArray(roomData)) return [];
    
    return roomData.map(room => {
      // Ensure room is an object
      if (!room || typeof room !== 'object') return null;
      
      return {
        room: room.room || room.name || 'Unknown Room', // Fallback to name or default
        utilization: room.utilization || 0,
        primeTimeUtilization: room.primeTimeUtilization || 0,
        nonPrimeTimeUtilization: room.nonPrimeTimeUtilization || 0
      };
    })
    .filter(Boolean) // Remove null entries
    .sort((a, b) => b.utilization - a.utilization);
  }, [roomData]);
  
  // Calculate average utilization with defensive coding
  const averageUtilization = useMemo(() => {
    if (!chartData || chartData.length === 0) return 0;
    return chartData.reduce((sum, room) => sum + (room.utilization || 0), 0) / chartData.length;
  }, [chartData]);
  
  // Calculate average prime time utilization with defensive coding
  const averagePrimeTimeUtilization = useMemo(() => {
    if (!chartData || chartData.length === 0) return 0;
    return chartData.reduce((sum, room) => sum + (room.primeTimeUtilization || 0), 0) / chartData.length;
  }, [chartData]);
  
  // Format data for Nivo bar chart with defensive coding
  const nivoChartData = useMemo(() => {
    if (!chartData || chartData.length === 0) return [];
    
    return chartData.map(room => {
      // Safe string replacement with null checks
      const roomName = typeof room.room === 'string' 
        ? room.room.replace(/VH /g, '').replace(/ OR /g, ' ')
        : 'Unknown Room';
      
      return {
        room: roomName,
        'Overall': room.utilization || 0,
        'Prime Time': room.primeTimeUtilization || 0,
        'Non-Prime Time': room.nonPrimeTimeUtilization || 0
      };
    });
  }, [chartData]);
  
  // Determine color based on utilization
  const getUtilizationColor = (utilization) => {
    if (utilization >= 80) return 'text-green-500';
    if (utilization >= 70) return 'text-blue-500';
    if (utilization >= 60) return 'text-yellow-500';
    return 'text-red-500';
  };
  
  const utilizationColor = getUtilizationColor(averageUtilization);
  const primeTimeUtilizationColor = getUtilizationColor(averagePrimeTimeUtilization);
  
  // If no data, show a message
  if (!chartData || chartData.length === 0) {
    return (
      <Card className={`healthcare-card ${className}`}>
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center">
            <BarChart2 className="h-5 w-5 mr-2 text-healthcare-primary dark:text-healthcare-primary-dark" />
            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Room Utilization
            </h3>
          </div>
        </div>
        <div className="flex justify-center items-center h-80">
          <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            No room utilization data available.
          </p>
        </div>
      </Card>
    );
  }
  
  return (
    <Card className={`healthcare-card ${className}`}>
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center">
          <BarChart2 className="h-5 w-5 mr-2 text-healthcare-primary dark:text-healthcare-primary-dark" />
          <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Room Utilization
          </h3>
        </div>
        
        <div className="flex space-x-4">
          <div className="text-right">
            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Avg Utilization
            </div>
            <div className={`text-lg font-bold ${utilizationColor}`}>
              {averageUtilization.toFixed(1)}%
            </div>
          </div>
          
          <div className="text-right">
            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Avg Prime Time
            </div>
            <div className={`text-lg font-bold ${primeTimeUtilizationColor}`}>
              {averagePrimeTimeUtilization.toFixed(1)}%
            </div>
          </div>
        </div>
      </div>
      
      {/* Bar Chart */}
      <div className="h-80">
        <NivoThemeProvider>
          <BarChart 
            data={nivoChartData}
            keys={['Overall', 'Prime Time', 'Non-Prime Time']}
            indexBy="room"
            margin={{ top: 10, right: 130, bottom: 50, left: 60 }}
            padding={0.3}
            groupMode="grouped"
            layout="vertical"
            colorScheme="mixed"
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Room',
              legendPosition: 'middle',
              legendOffset: 40
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Utilization (%)',
              legendPosition: 'middle',
              legendOffset: -50
            }}
            legends={[
              {
                dataFrom: 'keys',
                anchor: 'bottom-right',
                direction: 'column',
                justify: false,
                translateX: 120,
                translateY: 0,
                itemsSpacing: 2,
                itemWidth: 100,
                itemHeight: 20,
                itemDirection: 'left-to-right',
                itemOpacity: 0.85,
                symbolSize: 20
              }
            ]}
            animate={true}
            motionStiffness={90}
            motionDamping={15}
          />
        </NivoThemeProvider>
      </div>
      
      {/* Insights */}
      <div className="mt-4 pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
        <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2 flex items-center">
          <TrendingUp className="h-4 w-4 mr-2" />
          Room Utilization Insights
        </h4>
        <ul className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark space-y-1">
          {chartData.length > 0 && (
            <li className="flex items-start">
              <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
              <span>
                {typeof chartData[0].room === 'string' 
                  ? chartData[0].room.replace(/VH /g, '') 
                  : 'Top room'} has the highest utilization at {chartData[0].utilization.toFixed(1)}%, {(chartData[0].utilization - averageUtilization).toFixed(1)}% above average.
              </span>
            </li>
          )}
          {chartData.length > 1 && (
            <li className="flex items-start">
              <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
              <span>The difference between highest and lowest utilized rooms is {(chartData[0].utilization - chartData[chartData.length - 1].utilization).toFixed(1)}%.</span>
            </li>
          )}
          <li className="flex items-start">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-healthcare-primary dark:bg-healthcare-primary-dark mt-1.5 mr-2"></span>
            <span>Consider rebalancing case assignments to improve utilization across all rooms.</span>
          </li>
        </ul>
      </div>
    </Card>
  );
};

RoomUtilizationCard.propTypes = {
  roomData: PropTypes.arrayOf(
    PropTypes.shape({
      room: PropTypes.string, // Changed from required to optional
      name: PropTypes.string, // Added as an alternative
      utilization: PropTypes.number,
      primeTimeUtilization: PropTypes.number,
      nonPrimeTimeUtilization: PropTypes.number
    })
  ),
  className: PropTypes.string
};

export default RoomUtilizationCard;
