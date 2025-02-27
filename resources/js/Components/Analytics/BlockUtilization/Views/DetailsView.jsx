import React, { useState } from 'react';
import { mockBlockUtilization } from '@/mock-data/block-utilization';
import { Icon } from '@iconify/react';
import Panel from '@/Components/ui/Panel';

const DetailsView = ({ filters }) => {
  const [sortConfig, setSortConfig] = useState({
    key: 'service',
    direction: 'ascending'
  });
  
  const [searchTerm, setSearchTerm] = useState('');
  
  // Combine service and site data for a comprehensive table
  const tableData = [
    ...mockBlockUtilization.serviceData.map(service => ({
      id: `service-${service.name}`,
      type: 'Service',
      name: service.name,
      inBlockUtilization: service.metrics.inBlockUtilization,
      totalBlockUtilization: service.metrics.totalBlockUtilization,
      nonPrimePercentage: service.metrics.nonPrimePercentage,
      trend: service.metrics.utilizationTrend
    })),
    ...Object.entries(mockBlockUtilization.sites).map(([siteName, data], index) => ({
      id: `site-${index}`,
      type: 'Location',
      name: siteName,
      inBlockUtilization: data.metrics.inBlockUtilization,
      totalBlockUtilization: data.metrics.totalBlockUtilization,
      nonPrimePercentage: data.metrics.nonPrimePercentage,
      trend: data.metrics.utilizationTrend
    }))
  ];
  
  // Sorting logic
  const sortedData = [...tableData].sort((a, b) => {
    if (a[sortConfig.key] < b[sortConfig.key]) {
      return sortConfig.direction === 'ascending' ? -1 : 1;
    }
    if (a[sortConfig.key] > b[sortConfig.key]) {
      return sortConfig.direction === 'ascending' ? 1 : -1;
    }
    return 0;
  });
  
  // Filtering logic
  const filteredData = sortedData.filter(item => 
    item.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
    item.type.toLowerCase().includes(searchTerm.toLowerCase())
  );
  
  // Helper function to format percentages
  const formatPercentage = (value) => {
    if (typeof value === 'number') {
      return `${value.toFixed(1)}%`;
    } else if (typeof value === 'string') {
      // If it's already a string, just return it (it might already have % sign)
      return value;
    }
    return 'N/A';
  };

  // Request a sort
  const requestSort = (key) => {
    let direction = 'ascending';
    if (sortConfig.key === key && sortConfig.direction === 'ascending') {
      direction = 'descending';
    }
    setSortConfig({ key, direction });
  };
  
  // Get the sort direction indicator
  const getSortDirectionIndicator = (key) => {
    if (sortConfig.key === key) {
      return sortConfig.direction === 'ascending' 
        ? <Icon icon="heroicons:chevron-up" className="w-4 h-4" />
        : <Icon icon="heroicons:chevron-down" className="w-4 h-4" />;
    }
    return null;
  };

  return (
    <div className="animate-fadeIn">
      <Panel title="Block Utilization Details" dropLightIntensity="medium">
        <div className="mb-4">
          <div className="relative">
            <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <Icon icon="heroicons:magnifying-glass" className="h-5 w-5 text-gray-400" />
            </div>
            <input
              type="text"
              className="block w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md leading-5 bg-white dark:bg-gray-800 placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
              placeholder="Search by service or location"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th 
                  scope="col" 
                  className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer"
                  onClick={() => requestSort('type')}
                >
                  <div className="flex items-center">
                    Type
                    {getSortDirectionIndicator('type')}
                  </div>
                </th>
                <th 
                  scope="col" 
                  className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer"
                  onClick={() => requestSort('name')}
                >
                  <div className="flex items-center">
                    Name
                    {getSortDirectionIndicator('name')}
                  </div>
                </th>
                <th 
                  scope="col" 
                  className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer"
                  onClick={() => requestSort('inBlockUtilization')}
                >
                  <div className="flex items-center">
                    In-Block Utilization
                    {getSortDirectionIndicator('inBlockUtilization')}
                  </div>
                </th>
                <th 
                  scope="col" 
                  className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer"
                  onClick={() => requestSort('totalBlockUtilization')}
                >
                  <div className="flex items-center">
                    Total Block Utilization
                    {getSortDirectionIndicator('totalBlockUtilization')}
                  </div>
                </th>
                <th 
                  scope="col" 
                  className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer"
                  onClick={() => requestSort('nonPrimePercentage')}
                >
                  <div className="flex items-center">
                    Non-Prime Time
                    {getSortDirectionIndicator('nonPrimePercentage')}
                  </div>
                </th>
                <th 
                  scope="col" 
                  className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer"
                  onClick={() => requestSort('trend')}
                >
                  <div className="flex items-center">
                    Trend
                    {getSortDirectionIndicator('trend')}
                  </div>
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
              {filteredData.map((item) => (
                <tr key={item.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                    <span className={`px-2 py-1 rounded-full text-xs ${
                      item.type === 'Service' ? 'bg-blue-100 dark:bg-blue-800 text-blue-800 dark:text-blue-100' : 'bg-green-100 dark:bg-green-800 text-green-800 dark:text-green-100'
                    }`}>
                      {item.type}
                    </span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    {item.name}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    {formatPercentage(item.inBlockUtilization)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    {formatPercentage(item.totalBlockUtilization)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    {formatPercentage(item.nonPrimePercentage)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    <div className={`flex items-center ${
                      item.trend && item.trend.startsWith('+') ? 'text-emerald-500' : 
                      item.trend && item.trend.startsWith('-') ? 'text-red-500' : 'text-gray-500'
                    }`}>
                      <Icon 
                        icon={
                          item.trend && item.trend.startsWith('+') ? 'heroicons:arrow-trending-up' : 
                          item.trend && item.trend.startsWith('-') ? 'heroicons:arrow-trending-down' : 
                          'heroicons:minus'
                        } 
                        className="w-4 h-4 mr-1" 
                      />
                      <span>{item.trend || 'N/A'}</span>
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                    <div className="flex space-x-2">
                      <button className="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                        <Icon icon="heroicons:eye" className="w-5 h-5" />
                      </button>
                      <button className="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
                        <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                      </button>
                      <button className="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300">
                        <Icon icon="heroicons:ellipsis-horizontal" className="w-5 h-5" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {filteredData.length === 0 && (
                <tr>
                  <td colSpan="7" className="px-6 py-4 text-center text-gray-500 dark:text-gray-300">
                    No data found matching your search criteria
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        
        <div className="flex justify-between items-center mt-4">
          <div className="text-sm text-gray-700 dark:text-gray-300">
            Showing <span className="font-medium">{filteredData.length}</span> of <span className="font-medium">{tableData.length}</span> results
          </div>
          <div className="flex space-x-2">
            <button className="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm">Previous</button>
            <button className="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm bg-blue-600 dark:bg-blue-800 text-white dark:text-white">1</button>
            <button className="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm">Next</button>
          </div>
        </div>
      </Panel>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
        <Panel title="Utilization Insights" dropLightIntensity="medium">
          <div className="space-y-4">
            <Panel title="Top Performers" isSubpanel={true} dropLightIntensity="subtle">
              <ul className="space-y-2">
                {sortedData
                  .sort((a, b) => b.inBlockUtilization - a.inBlockUtilization)
                  .slice(0, 3)
                  .map((item, index) => (
                    <li key={index} className="flex justify-between items-center">
                      <span className="text-sm text-gray-700 dark:text-gray-300">{item.name} ({item.type})</span>
                      <span className="text-sm font-medium text-emerald-600 dark:text-emerald-400">{formatPercentage(item.inBlockUtilization)}</span>
                    </li>
                  ))}
              </ul>
            </Panel>
            
            <Panel title="Needs Improvement" isSubpanel={true} dropLightIntensity="subtle">
              <ul className="space-y-2">
                {sortedData
                  .sort((a, b) => a.inBlockUtilization - b.inBlockUtilization)
                  .slice(0, 3)
                  .map((item, index) => (
                    <li key={index} className="flex justify-between items-center">
                      <span className="text-sm text-gray-700 dark:text-gray-300">{item.name} ({item.type})</span>
                      <span className="text-sm font-medium text-red-600 dark:text-red-400">{formatPercentage(item.inBlockUtilization)}</span>
                    </li>
                  ))}
              </ul>
            </Panel>
          </div>
        </Panel>
        
        <Panel title="Utilization Analysis" dropLightIntensity="medium">
          <div className="space-y-4">
            <Panel title="Service vs. Location Analysis" isSubpanel={true} dropLightIntensity="medium">
              <p className="text-sm text-gray-600 dark:text-gray-300 mb-3">
                Comparison of average utilization metrics between services and locations.
              </p>
              
              <div className="grid grid-cols-2 gap-4">
                <div className="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg">
                  <h4 className="text-sm font-medium text-blue-800 dark:text-blue-300 mb-1">Services</h4>
                  <p className="text-xl font-semibold text-blue-600 dark:text-blue-400">
                    {formatPercentage(
                      tableData
                        .filter(item => item.type === 'Service')
                        .reduce((sum, item) => sum + item.inBlockUtilization, 0) / 
                        tableData.filter(item => item.type === 'Service').length
                    )}
                  </p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Average In-Block Utilization</p>
                </div>
                
                <div className="bg-green-50 dark:bg-green-900/20 p-3 rounded-lg">
                  <h4 className="text-sm font-medium text-green-800 dark:text-green-300 mb-1">Locations</h4>
                  <p className="text-xl font-semibold text-green-600 dark:text-green-400">
                    {formatPercentage(
                      tableData
                        .filter(item => item.type === 'Location')
                        .reduce((sum, item) => sum + item.inBlockUtilization, 0) / 
                        tableData.filter(item => item.type === 'Location').length
                    )}
                  </p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Average In-Block Utilization</p>
                </div>
              </div>
            </Panel>
            
            <Panel title="Recommendations" isSubpanel={true} dropLightIntensity="strong">
              <ul className="space-y-2">
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-blue-500">
                    <Icon icon="heroicons:check-circle" className="w-5 h-5" />
                  </div>
                  <p className="ml-2 text-sm text-gray-600 dark:text-gray-300">
                    Focus on improving utilization for the bottom 3 performers through targeted interventions.
                  </p>
                </li>
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-blue-500">
                    <Icon icon="heroicons:check-circle" className="w-5 h-5" />
                  </div>
                  <p className="ml-2 text-sm text-gray-600 dark:text-gray-300">
                    Analyze the practices of top performers to identify transferable strategies.
                  </p>
                </li>
                <li className="flex items-start">
                  <div className="flex-shrink-0 h-5 w-5 text-blue-500">
                    <Icon icon="heroicons:check-circle" className="w-5 h-5" />
                  </div>
                  <p className="ml-2 text-sm text-gray-600 dark:text-gray-300">
                    Consider reallocating blocks from low-utilization services to high-demand areas.
                  </p>
                </li>
              </ul>
            </Panel>
          </div>
        </Panel>
      </div>
    </div>
  );
};

export default DetailsView;
