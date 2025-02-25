import React, { useState, useEffect } from 'react';
import { LineChart, XAxis, YAxis, CartesianGrid, Tooltip, Legend, Line, ResponsiveContainer } from 'recharts';
import { Calendar } from 'lucide-react';

const BlockUtilizationDashboard = () => {
  // Sample data based on provided information
  const [selectedLocation, setSelectedLocation] = useState('MEMH OR');
  const [startDate, setStartDate] = useState('10/1/2024');
  const [endDate, setEndDate] = useState('12/31/2024');
  const [compStartDate, setCompStartDate] = useState('1/1/2024');
  const [compEndDate, setCompEndDate] = useState('6/30/2024');
  const [selectedDays, setSelectedDays] = useState(['Multiple values']);
  const [showDaysDropdown, setShowDaysDropdown] = useState(false);

  // Location data mapping
  const locations = [
    { id: 'all', name: '(All)', checked: false },
    { id: 'marh-ir', name: 'MARH IR', checked: false },
    { id: 'marh-or', name: 'MARH OR', checked: false },
    { id: 'memh-mhas-or', name: 'MEMH MHAS OR', checked: false },
    { id: 'memh-or', name: 'MEMH OR', checked: true },
    { id: 'ollh-or', name: 'OLLH OR', checked: false },
    { id: 'vorh-jri-or', name: 'VORH JRI OR', checked: false },
    { id: 'vorh-or', name: 'VORH OR', checked: false },
    { id: 'vssc-or', name: 'VSSC OR', checked: false },
    { id: 'wilh-or', name: 'WILH OR', checked: false }
  ];

  // Mock data for the charts
  const chartData = [
    { month: 'Oct 2024', blockUtilization: 63.8, nonPrimePercent: 10.3 },
    { month: 'Nov 2024', blockUtilization: 67.1, nonPrimePercent: 11.5 },
    { month: 'Dec 2024', blockUtilization: 62.7, nonPrimePercent: 13.4 }
  ];

  const locationStats = {
    'MEMH OR': {
      comparative: {
        nonPrimeTime: 11.7,
        primeTimeUtil: 72.6
      },
      current: {
        nonPrimeTime: 11.7,
        primeTimeUtil: 64.5
      }
    },
    'Grand Total': {
      comparative: {
        nonPrimeTime: 11.7,
        primeTimeUtil: 72.6
      },
      current: {
        nonPrimeTime: 11.7,
        primeTimeUtil: 64.5
      }
    }
  };

  const handleDaySelectToggle = () => {
    setShowDaysDropdown(!showDaysDropdown);
  };

  const handleLocationChange = (locationId) => {
    // In a real implementation, this would update the locations array
    // and trigger data refresh
    console.log(`Location selected: ${locationId}`);
    const locationName = locations.find(loc => loc.id === locationId)?.name;
    if (locationName) setSelectedLocation(locationName);
  };

  return (
    <div className="flex flex-col p-4 bg-gray-50 w-full rounded-md shadow-sm">
      {/* Top Navigation Tabs */}
      <div className="flex border-b mb-4 text-sm">
        <div className="px-3 py-2 bg-gray-200 rounded-t-md border-r border-gray-300">PrimeTime Util Dashboard</div>
        <div className="px-3 py-2 border-r border-gray-300">BU BY SERVICE</div>
        <div className="px-3 py-2 border-r border-gray-300">BU Comparative - Trend</div>
        <div className="px-3 py-2 border-r border-gray-300">BU DOW - After Overall - LOCGrp</div>
        <div className="px-3 py-2 border-r border-gray-300">By Block Group</div>
        <div className="px-3 py-2 border-r border-gray-300">BU Detail</div>
        <div className="px-3 py-2 border-r border-gray-300">BU by DOW</div>
        <div className="px-3 py-2">Non-Prime Time Usage</div>
      </div>

      {/* Title and Date Range */}
      <div className="text-center mb-4">
        <h2 className="text-xl font-bold text-red-600">VIRTUA - Block Utilization and Non-Prime Time use Trend</h2>
        <p className="text-green-600">Current: {startDate} - {endDate} - Comparative: {compStartDate} - {compEndDate}</p>
      </div>

      <div className="flex">
        {/* Left Sidebar - Date Selectors and Filters */}
        <div className="w-1/5 pr-4">
          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
            <div className="relative">
              <input 
                type="text" 
                className="w-full p-2 border rounded-md" 
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
              />
              <Calendar className="absolute right-2 top-2 h-4 w-4 text-gray-500" />
            </div>
          </div>

          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">End Date</label>
            <div className="relative">
              <input 
                type="text" 
                className="w-full p-2 border rounded-md" 
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
              />
              <Calendar className="absolute right-2 top-2 h-4 w-4 text-gray-500" />
            </div>
          </div>

          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Comparative Date Start</label>
            <div className="relative">
              <input 
                type="text" 
                className="w-full p-2 border rounded-md" 
                value={compStartDate}
                onChange={(e) => setCompStartDate(e.target.value)}
              />
              <Calendar className="absolute right-2 top-2 h-4 w-4 text-gray-500" />
            </div>
          </div>

          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Comparative Date End</label>
            <div className="relative">
              <input 
                type="text" 
                className="w-full p-2 border rounded-md" 
                value={compEndDate}
                onChange={(e) => setCompEndDate(e.target.value)}
              />
              <Calendar className="absolute right-2 top-2 h-4 w-4 text-gray-500" />
            </div>
          </div>

          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Location Group</label>
            <div className="space-y-1">
              {locations.map(location => (
                <div key={location.id} className="flex items-center">
                  <input
                    type="checkbox"
                    id={location.id}
                    checked={location.id === 'memh-or'}
                    onChange={() => handleLocationChange(location.id)}
                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                  />
                  <label htmlFor={location.id} className="ml-2 text-sm text-gray-700">
                    {location.name}
                  </label>
                </div>
              ))}
            </div>
          </div>

          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Day of Week</label>
            <div className="relative">
              <button 
                className="w-full p-2 border rounded-md bg-white text-left flex justify-between items-center"
                onClick={handleDaySelectToggle}
              >
                <span>{selectedDays.join(', ')}</span>
                <span className="ml-2">▼</span>
              </button>
              {showDaysDropdown && (
                <div className="absolute top-full left-0 w-full mt-1 bg-white border rounded-md shadow-lg z-10">
                  <div className="p-2 border-b">
                    <label className="flex items-center">
                      <input type="checkbox" className="mr-2" checked />
                      <span>Multiple values</span>
                    </label>
                  </div>
                  {['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'].map(day => (
                    <div key={day} className="p-2 hover:bg-gray-100">
                      <label className="flex items-center">
                        <input type="checkbox" className="mr-2" />
                        <span>{day}</span>
                      </label>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Main Content Area */}
        <div className="w-4/5">
          <div className="bg-gray-100 p-4 text-center mb-4 rounded-md">
            <h3 className="text-lg font-medium">Locations Group: {selectedLocation}</h3>
          </div>

          {/* Data Grid */}
          <div className="mb-6 overflow-hidden border rounded-md">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-r"></th>
                  <th className="py-3 px-6 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-r" colSpan="2">
                    Comparative % Non Prime Time
                  </th>
                  <th className="py-3 px-6 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-r" colSpan="2">
                    Current % Non Prime Time
                  </th>
                  <th className="py-3 px-6 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-r" colSpan="2">
                    Comparative Prime Time Util
                  </th>
                  <th className="py-3 px-6 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" colSpan="2">
                    Current Prime Time Util
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {Object.entries(locationStats).map(([name, stats]) => (
                  <tr key={name} className="hover:bg-gray-50">
                    <td className="py-2 px-6 text-sm font-medium text-gray-900 border-r">{name}</td>
                    <td className="py-2 px-6 text-sm text-center text-gray-500 border-r" colSpan="2">{stats.comparative.nonPrimeTime}%</td>
                    <td className="py-2 px-6 text-sm text-center text-gray-500 border-r" colSpan="2">{stats.current.nonPrimeTime}%</td>
                    <td className="py-2 px-6 text-sm text-center text-green-600 font-medium border-r" colSpan="2">{stats.comparative.primeTimeUtil}%</td>
                    <td className="py-2 px-6 text-sm text-center text-red-600 font-medium" colSpan="2">{stats.current.primeTimeUtil}%</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Chart Area */}
          <div className="mt-4 h-96">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart
                data={chartData}
                margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
              >
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis yAxisId="left" orientation="left" domain={[62, 68]} />
                <YAxis yAxisId="right" orientation="right" domain={[0, 14]} />
                <Tooltip />
                <Legend />
                <Line
                  yAxisId="left"
                  type="monotone"
                  dataKey="blockUtilization"
                  stroke="#000000"
                  strokeWidth={2}
                  name="Block Utilization"
                  activeDot={{ r: 8 }}
                />
                <Line
                  yAxisId="right"
                  type="monotone"
                  dataKey="nonPrimePercent"
                  stroke="#4682B4"
                  strokeWidth={2}
                  name="% Non-Prime"
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </div>
      </div>
    </div>
  );
};

export default BlockUtilTableau;