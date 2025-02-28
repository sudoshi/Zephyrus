import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockRoomRunning } from '@/mock-data/room-running';
import { ResponsiveLine } from '@nivo/line';
import Panel from '@/Components/ui/Panel';
import { useDarkMode } from '@/hooks/useDarkMode';
import getChartTheme from '@/utils/chartTheme';

const OverviewView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  const [isDarkMode] = useDarkMode();
  
  // Get MEMH OR data
  const memhORData = useMemo(() => {
    return mockRoomRunning.memhOR || {};
  }, []);
  
  // Get location data based on filters
  const locationData = useMemo(() => {
    // Default to first location if none selected
    const locationKey = selectedLocation || Object.keys(mockRoomRunning.sites)[0];
    return mockRoomRunning.sites[locationKey] || mockRoomRunning.sites[Object.keys(mockRoomRunning.sites)[0]];
  }, [selectedLocation]);
  
  // Get day data based on filters
  const dayData = useMemo(() => {
    // Default to weekdays if no specific day is selected
    return mockRoomRunning.weekdays.averageRoomsRunning;
  }, []);
  
  // Format rooms running by hour data for line chart
  const roomsRunningByHourData = useMemo(() => {
    return [
      {
        id: 'Rooms Running',
        data: dayData.map(item => ({
          x: item.time,
          y: item.value
        }))
      }
    ];
  }, [dayData]);
  
  // Format room utilization by hour data
  const roomUtilizationByHourData = useMemo(() => {
    return [
      {
        id: 'Rooms Running',
        data: Object.entries(locationData.roomsRunningByHour).map(([hour, value]) => ({
          x: hour,
          y: value
        }))
      },
      {
        id: 'Utilization %',
        data: Object.entries(locationData.roomsRunningByHour).map(([hour, value]) => ({
          x: hour,
          y: (value / locationData.totalRooms) * 100
        }))
      }
    ];
  }, [locationData]);
  
  // Format data for the main rooms running chart
  const roomsRunningChartData = useMemo(() => {
    if (!memhORData.dataSeries) return [];
    
    // Always show the three main lines: maxStaffing, idealStaffing, and avgTotalOccupied
    const keysToShow = ['maxStaffing', 'idealStaffing', 'avgTotalOccupied'];
    
    return keysToShow.map(key => {
      const series = memhORData.dataSeries[key];
      return {
        id: series.id,
        color: series.color,
        data: series.data.map(item => ({
          x: item.time,
          y: item.value
        }))
      };
    });
  }, [memhORData]);
  
  // Get statistics for the chart
  const chartStats = useMemo(() => {
    return memhORData.statistics || {};
  }, [memhORData]);
  
  // Get vertical markers
  const verticalMarkers = useMemo(() => {
    return memhORData.verticalMarkers || [];
  }, [memhORData]);
  
  // Custom layer for vertical markers
  const CustomVerticalMarkers = ({ xScale, innerWidth, innerHeight }) => {
    if (!verticalMarkers || verticalMarkers.length === 0) return null;
    
    return (
      <g>
        {verticalMarkers.map((marker, index) => {
          const xPos = xScale(marker.time);
          
          return (
            <g key={`marker-${index}`}>
              <line
                x1={xPos}
                y1={0}
                x2={xPos}
                y2={innerHeight}
                stroke={'rgba(255, 255, 255, 0.3)'}
                strokeWidth={1}
                strokeDasharray="4 4"
              />
              <text
                x={xPos}
                y={15}
                textAnchor="middle"
                fontSize={10}
                fontWeight="bold"
                fill={'rgba(255, 255, 255, 0.8)'}
              >
                {marker.label || marker.time}
              </text>
            </g>
          );
        })}
      </g>
    );
  };

  // Use shared chart theme
  const theme = getChartTheme();

  // Custom tooltip component
  const CustomTooltip = ({ point }) => {
    return (
      <div
        style={{
          background: 'rgba(31, 41, 55, 0.9)',
          color: '#ffffff',
          padding: '9px 12px',
          border: '1px solid rgba(255, 255, 255, 0.2)',
          borderRadius: '4px',
          boxShadow: '0 2px 4px rgba(0,0,0,0.3)'
        }}
      >
        <div style={{ fontWeight: 'bold', marginBottom: '4px' }}>
          Time: {point.data.x}
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          <div
            style={{
              width: '12px',
              height: '12px',
              backgroundColor: point.serieColor,
              borderRadius: '50%'
            }}
          />
          <div>{point.serieId}: {point.data.y}</div>
        </div>
        {chartStats && (
          <div style={{ marginTop: '8px', fontSize: '11px', borderTop: '1px solid rgba(255, 255, 255, 0.1)', paddingTop: '4px' }}>
            <div>Average: {chartStats.average}</div>
            <div>Avg + 1 StDev: {chartStats.averagePlus1StdDev}</div>
            <div>Max: {chartStats.max}</div>
          </div>
        )}
      </div>
    );
  };

  return (
    <div className="space-y-6">
      {/* Title and Subtitle */}
      <div className="text-center mb-4">
        <h2 className="text-xl font-bold">{memhORData.title || "Rooms Running Charts"}</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400">{memhORData.subtitle || ""}</p>
        <p className="text-sm font-medium mt-2">
          For: {memhORData.filters?.orGroup || "All OR Groups"}
          <br />
          Days of Week: {memhORData.filters?.weekday?.join(", ") || "All Days"}
        </p>
      </div>
      
      {/* Filter Controls */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div>
          <label className="block text-sm font-medium mb-1">Start Date</label>
          <input type="date" className="w-full rounded border border-gray-300 dark:border-gray-700 dark:bg-gray-800" defaultValue="2024-09-01" />
        </div>
        <div>
          <label className="block text-sm font-medium mb-1">End Date</label>
          <input type="date" className="w-full rounded border border-gray-300 dark:border-gray-700 dark:bg-gray-800" defaultValue="2024-12-31" />
        </div>
        <div>
          <label className="block text-sm font-medium mb-1">OR Group</label>
          <select className="w-full rounded border border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            <option>MEMH OR</option>
            <option>VORH Main OR</option>
            <option>VORH JRI OR</option>
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium mb-1">WEEKDAY</label>
          <select className="w-full rounded border border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            <option>(Multiple values)</option>
            <option>Monday</option>
            <option>Tuesday</option>
            <option>Wednesday</option>
            <option>Thursday</option>
            <option>Friday</option>
          </select>
        </div>
      </div>
      
      {/* Main Rooms Running Chart */}
      <Panel title="Rooms Running by Time of Day" isSubpanel dropLightIntensity="medium">
        <div className="h-192">
          <ResponsiveLine
            data={roomsRunningChartData}
            margin={{ top: 40, right: 20, bottom: 60, left: 60 }}
            xScale={{ 
              type: 'point',
              precision: 0
            }}
            yScale={{ 
              type: 'linear', 
              min: 0, 
              max: 12 
            }}
            curve="monotoneX"
            axisTop={null}
            axisRight={null}
            axisBottom={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: 'Time of Day',
              legendOffset: 40,
              legendPosition: 'middle'
            }}
            axisLeft={{
              tickSize: 5,
              tickPadding: 5,
              tickRotation: 0,
              legend: '# of Rooms Running',
              legendOffset: -50,
              legendPosition: 'middle'
            }}
            colors={d => d.color || { scheme: 'category10' }}
            pointSize={6}
            pointColor={{ theme: 'background' }}
            pointBorderWidth={2}
            pointBorderColor={{ from: 'serieColor' }}
            pointLabelYOffset={-12}
            useMesh={true}
            enableSlices="x"
            sliceTooltip={({ slice }) => {
              return (
                <div
                  style={{
                    background: 'rgba(31, 41, 55, 0.9)',
                    color: '#ffffff',
                    padding: '9px 12px',
                    border: '1px solid rgba(255, 255, 255, 0.2)',
                    borderRadius: '4px',
                    boxShadow: '0 2px 4px rgba(0,0,0,0.3)'
                  }}
                >
                  <div style={{ fontWeight: 'bold', marginBottom: '4px' }}>
                    Time: {slice.points[0].data.x}
                  </div>
                  {slice.points.map(point => (
                    <div key={point.id} style={{ display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '3px' }}>
                      <div
                        style={{
                          width: '12px',
                          height: '12px',
                          backgroundColor: point.serieColor,
                          borderRadius: '50%'
                        }}
                      />
                      <div>{point.serieId}: {point.data.y}</div>
                    </div>
                  ))}
                  {chartStats && (
                    <div style={{ marginTop: '8px', fontSize: '11px', borderTop: '1px solid rgba(255, 255, 255, 0.1)', paddingTop: '4px' }}>
                      <div>Average: {chartStats.average}</div>
                      <div>Avg + 1 StDev: {chartStats.averagePlus1StdDev}</div>
                      <div>Max: {chartStats.max}</div>
                    </div>
                  )}
                </div>
              );
            }}
            gridXValues={['0', '4', '8', '12', '16', '20', '24']}
            gridYValues={[0, 2, 4, 6, 8, 10, 12]}
            theme={theme}
            legends={[
              {
                anchor: 'bottom',
                direction: 'row',
                justify: false,
                translateX: 0,
                translateY: 50,
                itemsSpacing: 10,
                itemDirection: 'left-to-right',
                itemWidth: 100,
                itemHeight: 20,
                itemOpacity: 0.75,
                symbolSize: 12,
                symbolShape: 'circle',
                symbolBorderColor: 'rgba(0, 0, 0, .5)',
                effects: [
                  {
                    on: 'hover',
                    style: {
                      itemBackground: 'rgba(0, 0, 0, .03)',
                      itemOpacity: 1
                    }
                  }
                ]
              }
            ]}
            layers={[
              'grid',
              'markers',
              'axes',
              'areas',
              'lines',
              'points',
              'slices',
              'mesh',
              'legends',
              CustomVerticalMarkers
            ]}
          />
        </div>
      </Panel>
      
      {/* Original charts */}
      <div className="mt-8">
        <h3 className="text-lg font-medium mb-4">Additional Analysis</h3>
        
        {/* Summary Metrics */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <Panel title="Avg. Rooms Running" isSubpanel dropLightIntensity="medium">
            <div className="text-2xl font-bold">{locationData.averageRoomsRunning}</div>
            <p className="text-xs text-muted-foreground">Out of {locationData.totalRooms} total rooms</p>
          </Panel>
          
          <Panel title="Utilization Rate" isSubpanel dropLightIntensity="medium">
            <div className="text-2xl font-bold">{locationData.utilizationRate}%</div>
            <p className="text-xs text-muted-foreground">Overall room utilization</p>
          </Panel>
          
          <Panel title="Total Cases" isSubpanel dropLightIntensity="medium">
            <div className="text-2xl font-bold">{locationData.totalCases}</div>
            <p className="text-xs text-muted-foreground">Cases performed in selected period</p>
          </Panel>
          
          <Panel title="Avg. Case Duration" isSubpanel dropLightIntensity="medium">
            <div className="text-2xl font-bold">{locationData.averageCaseDuration} min</div>
            <p className="text-xs text-muted-foreground">Average case duration</p>
          </Panel>
        </div>
        
        {/* Rooms Running by Hour */}
        <Panel title="Rooms Running by Hour - Weekdays" isSubpanel dropLightIntensity="medium">
          <div className="h-80">
            <ResponsiveLine
              data={roomsRunningByHourData}
              margin={{ top: 20, right: 20, bottom: 50, left: 60 }}
              xScale={{ type: 'point' }}
              yScale={{ 
                type: 'linear', 
                min: 0, 
                max: 'auto' 
              }}
              axisTop={null}
              axisRight={null}
              axisBottom={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Time',
                legendOffset: 36,
                legendPosition: 'middle'
              }}
              axisLeft={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Rooms Running',
                legendOffset: -40,
                legendPosition: 'middle'
              }}
              colors={{ scheme: 'category10' }}
              pointSize={10}
              pointColor={{ theme: 'background' }}
              pointBorderWidth={2}
              pointBorderColor={{ from: 'serieColor' }}
              pointLabelYOffset={-12}
              useMesh={true}
              enableArea={true}
              areaOpacity={0.3}
              theme={theme}
              legends={[
                {
                  anchor: 'bottom-right',
                  direction: 'column',
                  justify: false,
                  translateX: 0,
                  translateY: 0,
                  itemsSpacing: 0,
                  itemDirection: 'left-to-right',
                  itemWidth: 80,
                  itemHeight: 20,
                  itemOpacity: 0.75,
                  symbolSize: 12,
                  symbolShape: 'circle',
                  symbolBorderColor: 'rgba(0, 0, 0, .5)',
                  effects: [
                    {
                      on: 'hover',
                      style: {
                        itemBackground: 'rgba(0, 0, 0, .03)',
                        itemOpacity: 1
                      }
                    }
                  ]
                }
              ]}
            />
          </div>
        </Panel>
        
        {/* Room Utilization by Hour */}
        <Panel title={`Room Utilization by Hour - ${selectedLocation || Object.keys(mockRoomRunning.sites)[0]}`} isSubpanel dropLightIntensity="medium">
          <div className="h-80">
            <ResponsiveLine
              data={roomUtilizationByHourData}
              margin={{ top: 20, right: 110, bottom: 50, left: 60 }}
              xScale={{ type: 'point' }}
              yScale={{ 
                type: 'linear', 
                min: 0, 
                max: 'auto' 
              }}
              axisTop={null}
              axisRight={null}
              axisBottom={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Hour',
                legendOffset: 36,
                legendPosition: 'middle'
              }}
              axisLeft={{
                tickSize: 5,
                tickPadding: 5,
                tickRotation: 0,
                legend: 'Rooms Running / Utilization (%)',
                legendOffset: -40,
                legendPosition: 'middle'
              }}
              colors={{ scheme: 'category10' }}
              pointSize={10}
              pointColor={{ theme: 'background' }}
              pointBorderWidth={2}
              pointBorderColor={{ from: 'serieColor' }}
              pointLabelYOffset={-12}
              useMesh={true}
              theme={theme}
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
                  symbolBorderColor: 'rgba(0, 0, 0, .5)',
                  effects: [
                    {
                      on: 'hover',
                      style: {
                        itemBackground: 'rgba(0, 0, 0, .03)',
                        itemOpacity: 1
                      }
                    }
                  ]
                }
              ]}
            />
          </div>
        </Panel>
      </div>
    </div>
  );
};

OverviewView.propTypes = {
  filters: PropTypes.shape({
    selectedHospital: PropTypes.string,
    selectedLocation: PropTypes.string,
    selectedSpecialty: PropTypes.string,
    selectedSurgeon: PropTypes.string,
    dateRange: PropTypes.shape({
      startDate: PropTypes.instanceOf(Date),
      endDate: PropTypes.instanceOf(Date)
    }),
    showComparison: PropTypes.bool,
    comparisonDateRange: PropTypes.shape({
      startDate: PropTypes.instanceOf(Date),
      endDate: PropTypes.instanceOf(Date)
    })
  }).isRequired
};

export default OverviewView;
