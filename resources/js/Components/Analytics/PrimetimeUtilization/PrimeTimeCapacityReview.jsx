import React, { useState, useRef } from 'react';
import PropTypes from 'prop-types';
import { mockPrimeTimeCapacityReview } from '@/mock-data/primetime-capacity-review';
import { ResponsiveLine } from '@nivo/line';
import Panel from '@/Components/ui/Panel';
import getChartTheme from '@/utils/chartTheme';
import { Icon } from '@iconify/react';

const PrimeTimeCapacityReview = ({ site = 'MARH OR' }) => {
  const [selectedSite, setSelectedSite] = useState(site);
  const [isLoading, setIsLoading] = useState(false);
  const siteData = mockPrimeTimeCapacityReview.sites[selectedSite] || mockPrimeTimeCapacityReview.sites['MARH OR'];
  
  // Handle site change with loading state
  const handleSiteChange = (newSite) => {
    setIsLoading(true);
    setSelectedSite(newSite);
    
    // Simulate loading delay
    setTimeout(() => {
      setIsLoading(false);
    }, 500);
  };
  
  // Format utilization trend data for chart
  const utilizationTrendData = [
    {
      id: 'Average Prime Time Utilization',
      data: [
        ...mockPrimeTimeCapacityReview.utilizationTrend['2024'].map(item => ({
          x: `${item.month} 2024`,
          y: item.value
        })),
        ...mockPrimeTimeCapacityReview.utilizationTrend['2025'].map(item => ({
          x: `${item.month} 2025`,
          y: item.value
        }))
      ].sort((a, b) => {
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        const [aMonth, aYear] = a.x.split(' ');
        const [bMonth, bYear] = b.x.split(' ');
        
        if (aYear !== bYear) {
          return parseInt(aYear) - parseInt(bYear);
        }
        
        return months.indexOf(aMonth) - months.indexOf(bMonth);
      })
    }
  ];
  
  // Format ORs per day trend data for chart
  const orsPerDayTrendData = [
    {
      id: 'Average # of 8 Hour ORs per day',
      data: [
        ...mockPrimeTimeCapacityReview.orsPerDayTrend['2024'].map(item => ({
          x: `${item.month} 2024`,
          y: item.value
        })),
        ...mockPrimeTimeCapacityReview.orsPerDayTrend['2025'].map(item => ({
          x: `${item.month} 2025`,
          y: item.value
        }))
      ].sort((a, b) => {
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        const [aMonth, aYear] = a.x.split(' ');
        const [bMonth, bYear] = b.x.split(' ');
        
        if (aYear !== bYear) {
          return parseInt(aYear) - parseInt(bYear);
        }
        
        return months.indexOf(aMonth) - months.indexOf(bMonth);
      })
    }
  ];
  
  // Get chart theme
  const chartTheme = getChartTheme();
  
  // Helper function to determine if a metric has improved or worsened
  const getMetricChangeIndicator = (current, previous, isHigherBetter = true) => {
    if (current === previous) return null;
    
    const hasImproved = isHigherBetter ? current > previous : current < previous;
    
    return (
      <Icon 
        icon={hasImproved ? 'carbon:arrow-up' : 'carbon:arrow-down'} 
        className={`ml-1 h-4 w-4 ${hasImproved ? 'text-green-500' : 'text-red-500'}`} 
      />
    );
  };
  
  // Helper function to parse numeric values from formatted strings
  const parseNumericValue = (value) => {
    if (typeof value === 'number') return value;
    
    // Remove commas and percentage signs
    return parseFloat(value.replace(/,/g, '').replace(/%/g, ''));
  };
  
  // Helper function to calculate percent change
  const getPercentChange = (current, previous) => {
    const currentValue = parseFloat(current.replace('%', '').replace(',', ''));
    const previousValue = parseFloat(previous.replace('%', '').replace(',', ''));
    
    if (previousValue === 0) return '0%';
    
    const change = ((currentValue - previousValue) / previousValue) * 100;
    const sign = change > 0 ? '+' : '';
    return `${sign}${change.toFixed(2)}%`;
  };
  
  // Custom tooltip for the table cells
  const CellTooltip = ({ label, value, description, previousValue, isHigherBetter }) => {
    const [showTooltip, setShowTooltip] = useState(false);
    const [tooltipPosition, setTooltipPosition] = useState({ top: 0, left: 0 });
    const tooltipRef = useRef(null);
    const triggerRef = useRef(null);
    
    const getChangeIndicator = () => {
      if (previousValue === undefined) return null;
      
      const currentValue = parseFloat(value.replace('%', '').replace(',', ''));
      const prevValue = parseFloat(previousValue.replace('%', '').replace(',', ''));
      const isImproved = isHigherBetter ? currentValue > prevValue : currentValue < prevValue;
      
      return (
        <span className={`ml-2 ${isImproved ? 'text-green-500' : 'text-red-500'}`}>
          {isImproved ? <Icon icon="carbon:arrow-up" className="inline h-4 w-4" /> : <Icon icon="carbon:arrow-down" className="inline h-4 w-4" />}
        </span>
      );
    };
    
    const handleMouseEnter = () => {
      if (triggerRef.current) {
        const rect = triggerRef.current.getBoundingClientRect();
        setTooltipPosition({
          top: rect.top,
          left: rect.right + 10
        });
        setShowTooltip(true);
      }
    };
    
    const handleMouseLeave = () => {
      setShowTooltip(false);
    };
    
    return (
      <>
        <div 
          ref={triggerRef}
          className="flex items-center cursor-help" 
          onMouseEnter={handleMouseEnter}
          onMouseLeave={handleMouseLeave}
        >
          <span className="font-medium">{value}</span>
          {previousValue !== undefined && getChangeIndicator()}
          <Icon icon="carbon:information" className="ml-1 text-gray-400 h-4 w-4" />
        </div>
        
        {showTooltip && (
          <div 
            ref={tooltipRef}
            className="fixed z-[9999] bg-gray-800 text-white dark:bg-gray-700 p-3 rounded shadow-lg w-72"
            style={{
              top: `${tooltipPosition.top}px`,
              left: `${tooltipPosition.left}px`,
              transform: 'translateY(-50%)'
            }}
          >
            <div className="font-bold text-base border-b border-gray-600 pb-1 mb-2 break-words">{label}</div>
            <div className="text-sm mb-2 break-words">{description}</div>
            {previousValue !== undefined && (
              <div className="text-sm">
                <div className="flex justify-between">
                  <span>Current:</span>
                  <span className="font-semibold">{value}</span>
                </div>
                <div className="flex justify-between">
                  <span>Previous:</span>
                  <span className="font-semibold">{previousValue}</span>
                </div>
                <div className="flex justify-between mt-1">
                  <span>Change:</span>
                  <span className={`font-semibold ${
                    isHigherBetter 
                      ? parseFloat(value) > parseFloat(previousValue) ? 'text-green-400' : 'text-red-400'
                      : parseFloat(value) < parseFloat(previousValue) ? 'text-green-400' : 'text-red-400'
                  }`}>
                    {getPercentChange(value, previousValue)}
                  </span>
                </div>
              </div>
            )}
            <div className="text-xs mt-2 bg-gray-700 dark:bg-gray-600 p-2 rounded">
              <div className="grid grid-cols-2 gap-1">
                <div className="text-gray-300">Current Period:</div>
                <div>{siteData.metricStartDate} - {siteData.metricEndDate}</div>
                <div className="text-gray-300">Previous Period:</div>
                <div>{siteData.metricPreviousStartDate} - {siteData.metricPreviousEndDate}</div>
              </div>
            </div>
          </div>
        )}
      </>
    );
  };
  
  return (
    <Panel title="Prime Time Capacity Review" className="mb-6">
      <div className="flex flex-col">
        <div className="mb-4 flex justify-between items-center">
          <div className="text-lg font-bold">Site: {selectedSite}</div>
          <div>
            <select 
              className="bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-md px-3 py-1 text-sm"
              value={selectedSite}
              onChange={(e) => handleSiteChange(e.target.value)}
              disabled={isLoading}
            >
              {Object.keys(mockPrimeTimeCapacityReview.sites).map(site => (
                <option key={site} value={site}>{site}</option>
              ))}
            </select>
          </div>
        </div>
        
        {isLoading ? (
          <div className="flex justify-center items-center h-64">
            <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-blue-500"></div>
          </div>
        ) : (
          <>
            {/* Main Table */}
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 border border-gray-200 dark:border-gray-700">
                <thead className="bg-gray-100 dark:bg-gray-800">
                  <tr>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      Prime Time<br/>Util<br/>CURRENT
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      Prime Time<br/>Util<br/>PREVIOUS
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      % Work<br/>During Non<br/>Prime Time<br/>CURRENT
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      % Work<br/>During Non<br/>Prime Time<br/>PREVIOUS
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      Num of<br/>Cases<br/>CURRENT
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      Potential<br/>Cases
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      Additional<br/>Case<br/>Potential
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      # of ORs<br/>per<br/>week
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      # of ORs<br/>per week<br/>needed
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      # of OR<br/>Difference
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      Num of<br/>Weekend<br/>Cases
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      # of ORs<br/>available<br/>per<br/>Weekend
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700">
                      # of ORs<br/>needed per<br/>Weekend
                    </th>
                    <th scope="col" className="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      % Weekend<br/>Work During<br/>Non Prime<br/>Time
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                  <tr>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="Prime Time Util - CURRENT" 
                        value={`${siteData.primeTimeCurrent}%`} 
                        description="Current prime time utilization percentage"
                        previousValue={`${siteData.primeTimePrevious}%`}
                        isHigherBetter={true}
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="Prime Time Util - PREVIOUS" 
                        value={`${siteData.primeTimePrevious}%`} 
                        description="Previous prime time utilization percentage"
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="% Work During Non Prime Time - CURRENT" 
                        value={`${siteData.workDuringNonPrimeTimeCurrent}%`} 
                        description="Current percentage of work during non-prime time"
                        previousValue={`${siteData.workDuringNonPrimeTimePrevious}%`}
                        isHigherBetter={false}
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="% Work During Non Prime Time - PREVIOUS" 
                        value={`${siteData.workDuringNonPrimeTimePrevious}%`} 
                        description="Previous percentage of work during non-prime time"
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="Num of Cases - CURRENT" 
                        value={siteData.numOfCasesCurrent.toLocaleString()} 
                        description="Current number of cases"
                        previousValue={siteData.numOfCasesPrevious.toLocaleString()}
                        isHigherBetter={true}
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="Potential Cases" 
                        value={siteData.potentialCases.toLocaleString()} 
                        description="Potential cases possible with current block"
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="Additional Case Potential" 
                        value={siteData.additionalCasePotential.toLocaleString()} 
                        description="Additional case potential"
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="# of ORs per week" 
                        value={siteData.numOfORsPerWeek.toFixed(2)} 
                        description="Number of ORs per week"
                        previousValue={siteData.numOfORsPerWeekPrevious.toFixed(2)}
                        isHigherBetter={true}
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="# of ORs per week needed" 
                        value={siteData.numOfORsPerWeekNeeded.toFixed(2)} 
                        description="Number of ORs per week needed"
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="# of OR Difference" 
                        value={siteData.numOfORDifference.toFixed(2)} 
                        description="Difference between available and needed ORs"
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="Num of Weekend Cases" 
                        value={siteData.numOfWeekendCases.toLocaleString()} 
                        description="Number of weekend cases"
                        previousValue={siteData.numOfWeekendCasesPrevious.toLocaleString()}
                        isHigherBetter={true}
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="# of ORs available per Weekend" 
                        value={siteData.numOfORsAvailablePerWeekend.toFixed(2)} 
                        description="Number of ORs available per weekend"
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap border-r border-gray-200 dark:border-gray-700">
                      <CellTooltip 
                        label="# of ORs needed per Weekend" 
                        value={siteData.numOfORsNeededPerWeekend.toFixed(2)} 
                        description="Number of ORs needed per weekend"
                      />
                    </td>
                    <td className="px-3 py-4 whitespace-nowrap">
                      <CellTooltip 
                        label="% Weekend Work During Non Prime Time" 
                        value={`${siteData.percentWeekendWorkDuringNonPrimeTime}%`} 
                        description="Percentage of weekend work during non-prime time"
                        previousValue={`${siteData.percentWeekendWorkDuringNonPrimeTimePrevious}%`}
                        isHigherBetter={false}
                      />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            
            {/* Charts */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
              {/* Average Prime Time Utilization Chart */}
              <div className="h-80 bg-white dark:bg-gray-900 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                <div className="text-lg font-bold mb-2">Average Prime Time Utilization</div>
                <ResponsiveLine
                  data={utilizationTrendData}
                  margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                  xScale={{ type: 'point' }}
                  yScale={{ type: 'linear', min: 60, max: 85, stacked: false }}
                  curve="monotoneX"
                  axisBottom={{
                    tickSize: 5,
                    tickPadding: 5,
                    tickRotation: -45,
                    legend: '',
                    legendOffset: 36,
                    legendPosition: 'middle',
                    format: (value) => value.split(' ')[0].substring(0, 3)
                  }}
                  axisLeft={{
                    tickSize: 5,
                    tickPadding: 5,
                    tickRotation: 0,
                    legend: 'Average Prime Time Utilization',
                    legendOffset: -50,
                    legendPosition: 'middle'
                  }}
                  colors={['#3182ce']}
                  pointSize={8}
                  pointColor={{ theme: 'background' }}
                  pointBorderWidth={2}
                  pointBorderColor={{ from: 'serieColor' }}
                  pointLabelYOffset={-12}
                  useMesh={true}
                  enableSlices="x"
                  theme={chartTheme}
                  gridYValues={[65, 70, 75, 80]}
                  enableArea={true}
                  areaOpacity={0.1}
                  lineWidth={3}
                  legends={[
                    {
                      anchor: 'bottom',
                      direction: 'row',
                      justify: false,
                      translateX: 0,
                      translateY: 50,
                      itemsSpacing: 0,
                      itemDirection: 'left-to-right',
                      itemWidth: 80,
                      itemHeight: 20,
                      itemOpacity: 0.75,
                      symbolSize: 12,
                      symbolShape: 'circle',
                      symbolBorderColor: 'rgba(0, 0, 0, .5)',
                    }
                  ]}
                />
              </div>
              
              {/* Average # of 8 Hour ORs per day Chart */}
              <div className="h-80 bg-white dark:bg-gray-900 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                <div className="text-lg font-bold mb-2">Average # of 8 Hour ORs per day trend</div>
                <ResponsiveLine
                  data={orsPerDayTrendData}
                  margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
                  xScale={{ type: 'point' }}
                  yScale={{ type: 'linear', min: 4.5, max: 6.5, stacked: false }}
                  curve="monotoneX"
                  axisBottom={{
                    tickSize: 5,
                    tickPadding: 5,
                    tickRotation: -45,
                    legend: '',
                    legendOffset: 36,
                    legendPosition: 'middle',
                    format: (value) => value.split(' ')[0].substring(0, 3)
                  }}
                  axisLeft={{
                    tickSize: 5,
                    tickPadding: 5,
                    tickRotation: 0,
                    legend: 'Average # of 8 Hour ORs per day',
                    legendOffset: -50,
                    legendPosition: 'middle'
                  }}
                  colors={['#38a169']}
                  pointSize={8}
                  pointColor={{ theme: 'background' }}
                  pointBorderWidth={2}
                  pointBorderColor={{ from: 'serieColor' }}
                  pointLabelYOffset={-12}
                  useMesh={true}
                  enableSlices="x"
                  theme={chartTheme}
                  gridYValues={[4.5, 5.0, 5.5, 6.0, 6.5]}
                  enableArea={true}
                  areaOpacity={0.1}
                  lineWidth={3}
                  legends={[
                    {
                      anchor: 'bottom',
                      direction: 'row',
                      justify: false,
                      translateX: 0,
                      translateY: 50,
                      itemsSpacing: 0,
                      itemDirection: 'left-to-right',
                      itemWidth: 80,
                      itemHeight: 20,
                      itemOpacity: 0.75,
                      symbolSize: 12,
                      symbolShape: 'circle',
                      symbolBorderColor: 'rgba(0, 0, 0, .5)',
                    }
                  ]}
                />
              </div>
            </div>
          </>
        )}
      </div>
    </Panel>
  );
};

PrimeTimeCapacityReview.propTypes = {
  site: PropTypes.string
};

export default PrimeTimeCapacityReview;
