import React, { useState, useMemo } from 'react';
import { Icon } from '@iconify/react';
import Panel from '@/Components/ui/Panel';

const DetailsView = ({ filters, data }) => {
  // Extract filter values from the new filter structure
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  const [sortField, setSortField] = useState('name');
  const [sortDirection, setSortDirection] = useState('asc');
  
  // Filter data based on hierarchical filters
  const filteredData = useMemo(() => {
    let filteredBlockData = [...data.blockData];
    
    // Filter by hospital if selected
    if (selectedHospital) {
      filteredBlockData = filteredBlockData.filter(block => 
        block.sites && block.sites.some(site => site.includes(selectedHospital))
      );
    }
    
    // Filter by location if selected
    if (selectedLocation) {
      filteredBlockData = filteredBlockData.filter(block => 
        block.sites && block.sites.some(site => site.includes(selectedLocation))
      );
    }
    
    // Filter by specialty if selected
    if (selectedSpecialty) {
      filteredBlockData = filteredBlockData.filter(block => 
        block.specialty === selectedSpecialty
      );
    }
    
    return filteredBlockData;
  }, [selectedHospital, selectedLocation, selectedSpecialty, dateRange]);

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

  // Sort the data based on the selected field and direction
  const sortedData = useMemo(() => {
    if (!filteredData) return [];
    
    return [...filteredData].sort((a, b) => {
      let aValue = a[sortField];
      let bValue = b[sortField];
      
      // Handle string comparisons
      if (typeof aValue === 'string' && typeof bValue === 'string') {
        aValue = aValue.toLowerCase();
        bValue = bValue.toLowerCase();
      }
      
      // Handle numeric comparisons
      if (sortDirection === 'asc') {
        return aValue > bValue ? 1 : -1;
      } else {
        return aValue < bValue ? 1 : -1;
      }
    });
  }, [filteredData, sortField, sortDirection]);

  // Handle sorting when a column header is clicked
  const handleSort = (field) => {
    if (sortField === field) {
      // Toggle direction if same field
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      // New field, default to ascending
      setSortField(field);
      setSortDirection('asc');
    }
  };

  // Render sort icon
  const renderSortIcon = (field) => {
    if (sortField !== field) return null;
    
    return (
      <Icon 
        icon={sortDirection === 'asc' ? 'heroicons:chevron-up' : 'heroicons:chevron-down'} 
        className="inline-block ml-1" 
      />
    );
  };

  return (
    <div className="animate-fadeIn">
      <Panel title="Block Details" dropLightIntensity="medium">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
              <tr>
                <th
                  scope="col"
                  className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider cursor-pointer"
                  onClick={() => handleSort('name')}
                >
                  Block Name {renderSortIcon('name')}
                </th>
                <th
                  scope="col"
                  className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider cursor-pointer"
                  onClick={() => handleSort('specialty')}
                >
                  Specialty {renderSortIcon('specialty')}
                </th>
                <th
                  scope="col"
                  className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider cursor-pointer"
                  onClick={() => handleSort('location')}
                >
                  Location {renderSortIcon('location')}
                </th>
                <th
                  scope="col"
                  className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider cursor-pointer"
                  onClick={() => handleSort('utilization')}
                >
                  Utilization {renderSortIcon('utilization')}
                </th>
                <th
                  scope="col"
                  className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider cursor-pointer"
                  onClick={() => handleSort('released')}
                >
                  Status {renderSortIcon('released')}
                </th>
              </tr>
            </thead>
            <tbody className="bg-healthcare-surface dark:bg-healthcare-surface-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              {sortedData.map((block, index) => (
                <tr key={index} className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark">
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-healthcare-text-primary dark:text-white">
                    {block.name}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {block.specialty}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {block.location}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <div className="flex items-center">
                      <div className="w-16 mr-2">{formatPercentage(block.utilization)}</div>
                      <div className="w-24 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2.5">
                        <div
                          className={`h-2.5 rounded-full ${
                            block.utilization > 75 ? 'bg-healthcare-success dark:bg-healthcare-success-dark' :
                            block.utilization > 70 ? 'bg-healthcare-info dark:bg-healthcare-info-dark' :
                            block.utilization > 65 ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' : 'bg-healthcare-critical dark:bg-healthcare-critical-dark'
                          }`}
                          style={{ width: `${block.utilization}%` }}
                        />
                      </div>
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${
                      block.released ? 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark' : 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success-dark/20 dark:text-healthcare-success-dark'
                    }`}>
                      {block.released ? 'Released' : 'Active'}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
};

export default DetailsView;
