import React, { useMemo } from 'react';
import PropTypes from 'prop-types';
import { mockPrimetimeUtilization } from '../../../../mock-data/primetime-utilization';
import Panel from '../../../ui/Panel';

const ServiceAnalysisView = ({ filters }) => {
  // Extract filter values
  const { selectedHospital, selectedLocation, selectedSpecialty, dateRange } = filters;
  
  // Format service data for the table
  const serviceData = useMemo(() => {
    return Object.entries(mockPrimetimeUtilization.serviceAnalysis || {})
      .filter(([service, data]) => {
        // Filter by specialty if selected
        if (selectedSpecialty && service !== selectedSpecialty && service !== 'Grand Total') {
          return false;
        }
        return true;
      })
      .map(([service, data]) => ({
        service,
        ...data
      }));
  }, [selectedHospital, selectedLocation, selectedSpecialty, dateRange]);
  
  // Function to format percentages
  const formatPercent = (value) => {
    if (value === null || value === undefined) return '';
    return `${value.toFixed(1)}%`;
  };
  
  // Function to format numbers
  const formatNumber = (value) => {
    if (value === null || value === undefined) return '';
    return value.toFixed(2);
  };
  
  // Function to determine the background color for a cell
  const getCellBackground = (value, type) => {
    if (value === null || value === undefined) return '';
    
    if (type === 'primeTime') {
      if (value < 50) return 'bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20';
      if (value < 70) return 'bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/20';
      if (value < 90) return 'bg-healthcare-success/10 dark:bg-healthcare-success-dark/20';
      return 'bg-healthcare-info/10 dark:bg-healthcare-info-dark/20';
    }
    
    return '';
  };

  // Function to determine text color based on value
  const getTextColor = (value, isNegative = false) => {
    if (value === null || value === undefined) return '';
    
    if (isNegative) {
      return value < 0 ? 'text-healthcare-critical dark:text-healthcare-critical-dark' : 'text-healthcare-success dark:text-healthcare-success-dark';
    }
    
    return '';
  };

  return (
    <div className="space-y-6">
      <Panel title="Prime Time Capacity Review by Service" dropLightIntensity="medium">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
              <tr>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Service
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Prime Time Util - Current
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Prime Time Util - Previous
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  % Work During Non Prime Time - Current
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  % Work During Non Prime Time - Previous
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Num of Cases - Current
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Potential Cases possible with Current
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Additional Case Potential
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  # of ORs per week Available
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  # of ORs per week needed
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  # of OR Difference along Cell
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  Num of Cases - Weekend
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  # of ORs needed per Weekend
                </th>
                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                  % Weekend Work During Non Prime Time...
                </th>
              </tr>
            </thead>
            <tbody className="bg-healthcare-surface divide-y divide-healthcare-border dark:bg-healthcare-surface-dark dark:divide-healthcare-border-dark">
              {serviceData.map((row, index) => (
                <tr key={index} className={row.service === 'Grand Total' ? 'font-semibold bg-healthcare-background dark:bg-healthcare-background-dark' : ''}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {row.service}
                  </td>
                  <td className={`px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white ${getCellBackground(row.primeTimeCurrent, 'primeTime')}`}>
                    {formatPercent(row.primeTimeCurrent)}
                  </td>
                  <td className={`px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white ${getCellBackground(row.primeTimePrevious, 'primeTime')}`}>
                    {formatPercent(row.primeTimePrevious)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {formatPercent(row.workDuringPrimeTimeCurrent)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {formatPercent(row.workDuringPrimeTimePrevious)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {row.numOfCasesCurrent}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {row.potentialCases}
                  </td>
                  <td className={`px-6 py-4 whitespace-nowrap text-sm ${getTextColor(row.additionalCasePotential, true)} dark:text-white`}>
                    {row.additionalCasePotential}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {formatNumber(row.ORsPerWeekAvailable)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {formatNumber(row.ORsPerWeekNeeded)}
                  </td>
                  <td className={`px-6 py-4 whitespace-nowrap text-sm ${getTextColor(row.ORDifference, true)} dark:text-white`}>
                    {formatNumber(row.ORDifference)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {row.numOfCasesWeekend}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {formatNumber(row.ORsNeededPerWeekend)}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-healthcare-text-primary dark:text-white">
                    {formatPercent(row.percentWeekendWork)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
      
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Service Utilization Comparison" isSubpanel dropLightIntensity="medium">
          <div className="p-4 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <p>This panel would contain a bar chart comparing prime time utilization across services.</p>
          </div>
        </Panel>
        
        <Panel title="Weekend Utilization by Service" isSubpanel dropLightIntensity="medium">
          <div className="p-4 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <p>This panel would contain a visualization of weekend utilization patterns by service.</p>
          </div>
        </Panel>
      </div>
      
      <Panel title="Utilization Trend by Service" isSubpanel dropLightIntensity="medium">
        <div className="p-4 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <p>This panel would contain a line chart showing utilization trends over time for selected services.</p>
        </div>
      </Panel>
    </div>
  );
};

ServiceAnalysisView.propTypes = {
  filters: PropTypes.shape({
    selectedHospital: PropTypes.string,
    selectedLocation: PropTypes.string,
    selectedSpecialty: PropTypes.string,
    dateRange: PropTypes.shape({
      start: PropTypes.object,
      end: PropTypes.object
    })
  })
};

export default ServiceAnalysisView;
