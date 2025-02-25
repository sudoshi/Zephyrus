import React, { useState } from 'react';
import { Card, Button, Tabs } from '@/Components/ui/flowbite';
import { BarChart, LineChart } from '@/Components/ui/charts';
import { NivoThemeProvider } from '@/Components/ui';
import { format } from 'date-fns';
import { Calendar as CalendarIcon } from 'lucide-react';
import { mockBlockUtilization } from '@/mock-data/block-utilization';
import { DatePicker } from '@/Components/ui/flowbite/DatePicker';

// This is a refactored version of BlockUtilizationDashboard using the new UI components
export default function BlockUtilizationDashboard() {
  // Current period
  const [selectedLocation, setSelectedLocation] = useState('MARH OR');
  const [selectedTab, setSelectedTab] = useState('overview');
  const [selectedService, setSelectedService] = useState(null);
  const [selectedProvider, setSelectedProvider] = useState(null);
  const [startDate, setStartDate] = useState(new Date(2024, 9, 1)); // Oct 1, 2024
  const [endDate, setEndDate] = useState(new Date(2024, 11, 31)); // Dec 31, 2024
  
  // Comparative period
  const [compStartDate, setCompStartDate] = useState(new Date(2024, 0, 1)); // Jan 1, 2024
  const [compEndDate, setCompEndDate] = useState(new Date(2024, 5, 30)); // Jun 30, 2024
  
  // Day of week filter
  const [selectedDays, setSelectedDays] = useState(['All']);
  const [showDaysDropdown, setShowDaysDropdown] = useState(false);
  
  // View mode
  const [showComparison, setShowComparison] = useState(false);

  // Location options with checkbox state
  const locationOptions = Object.keys(mockBlockUtilization.sites).map(site => ({
    id: site.toLowerCase().replace(/\s+/g, '-'),
    name: site,
    checked: site === 'MARH OR'
  }));
  
  const dayOptions = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
  
  // Get service options based on selected location
  const getServiceOptions = () => {
    if (!selectedLocation) return [];
    const site = mockBlockUtilization.sites[selectedLocation];
    if (Array.isArray(site.services)) {
      return site.services.map(service => service.service_name);
    } else if (site.services) {
      return Object.keys(site.services);
    }
    return [];
  };
  
  const serviceOptions = getServiceOptions();
  
  // Get provider options based on selected location and service
  const getProviderOptions = () => {
    if (!selectedLocation || !selectedService) return [];
    const site = mockBlockUtilization.sites[selectedLocation];
    
    if (Array.isArray(site.services)) {
      const service = site.services.find(s => s.service_name === selectedService);
      if (service && service.providers) {
        return service.providers.map(provider => provider.name);
      }
    } else if (site.services && site.services[selectedService] && site.services[selectedService].providers) {
      return Object.keys(site.services[selectedService].providers);
    }
    
    return [];
  };
  
  const providerOptions = getProviderOptions();
  
  // Get site data
  const siteData = mockBlockUtilization.sites[selectedLocation];
  
  // Get overall metrics
  const overallMetrics = mockBlockUtilization.overallMetrics;

  // Format data for charts
  const formatServiceData = () => {
    if (Array.isArray(siteData.services)) {
      return siteData.services.map(service => ({
        name: service.service_name,
        inBlockUtilization: service.in_block_utilization,
        totalBlockUtilization: service.total_block_utilization,
        nonPrimePercentage: service.non_prime_percentage
      }));
    } else if (siteData.services) {
      return Object.entries(siteData.services).map(([name, data]) => ({
        name,
        inBlockUtilization: data.metrics?.utilization || 0,
        totalBlockUtilization: data.metrics?.utilization || 0,
        nonPrimePercentage: 0
      }));
    }
    return [];
  };

  const serviceData = formatServiceData();
  
  // Format day of week data
  const formatDayOfWeekData = () => {
    const dayOfWeekData = mockBlockUtilization.dayOfWeek;
    if (!dayOfWeekData) return [];
    
    const selectedServiceData = dayOfWeekData[selectedService || Object.keys(dayOfWeekData)[0]];
    if (!selectedServiceData) return [];
    
    return Object.entries(selectedServiceData)
      .filter(([day]) => day !== 'total')
      .map(([day, value]) => ({
        name: day,
        utilization: value
      }));
  };
  
  const dayOfWeekData = formatDayOfWeekData();
  
  // Format trend data for Nivo LineChart
  const formatTrendData = () => {
    const trends = mockBlockUtilization.trends;
    if (!trends || !trends['VORH JRI OR']) return [];
    
    return LineChart.formatData(
      trends['VORH JRI OR'].utilization,
      'month',
      'value',
      'Utilization %'
    );
  };
  
  const trendData = formatTrendData();

  // Format non-prime time trend data for Nivo LineChart
  const formatNonPrimeTimeTrendData = () => {
    const trends = mockBlockUtilization.trends;
    if (!trends || !trends['VORH JRI OR']) return [];
    
    return LineChart.formatData(
      trends['VORH JRI OR'].nonPrimeTime,
      'month',
      'value',
      'Non-Prime Time %'
    );
  };
  
  const nonPrimeTimeTrendData = formatNonPrimeTimeTrendData();

  return (
    <div>
      {/* Main Layout with Sidebar and Content */}
      <div className="flex flex-col md:flex-row gap-6">
        {/* Left Sidebar - Filters */}
        <div className="w-full md:w-1/4 lg:w-1/5 space-y-4">
          <div className="healthcare-card">
            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Filters</h3>
            
            {/* Current Period */}
            <div className="mb-4">
              <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Current Period</h4>
              <div className="space-y-3">
                <div>
                  <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Start Date</label>
                  <DatePicker 
                    value={startDate}
                    onChange={setStartDate}
                    maxDate={endDate}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">End Date</label>
                  <DatePicker 
                    value={endDate}
                    onChange={setEndDate}
                    minDate={startDate}
                  />
                </div>
              </div>
            </div>
            
            {/* Comparative Period */}
            <div className="mb-4">
              <div className="flex items-center justify-between mb-2">
                <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Comparative Period</h4>
                <label className="inline-flex items-center cursor-pointer">
                  <input 
                    type="checkbox" 
                    className="sr-only peer"
                    checked={showComparison}
                    onChange={() => setShowComparison(!showComparison)}
                  />
                  <div className="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-healthcare-primary dark:peer-checked:bg-healthcare-primary-dark"></div>
                </label>
              </div>
              
              {showComparison && (
                <div className="space-y-3">
                  <div>
                    <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">Start Date</label>
                    <DatePicker 
                      value={compStartDate}
                      onChange={setCompStartDate}
                      maxDate={compEndDate}
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">End Date</label>
                    <DatePicker 
                      value={compEndDate}
                      onChange={setCompEndDate}
                      minDate={compStartDate}
                    />
                  </div>
                </div>
              )}
            </div>
            
            {/* Location */}
            <div className="mb-4">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Location</label>
              <select 
                className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                value={selectedLocation} 
                onChange={(e) => {
                  setSelectedLocation(e.target.value);
                  setSelectedService(null);
                  setSelectedProvider(null);
                }}
              >
                {locationOptions.map(location => (
                  <option key={location.id} value={location.name}>{location.name}</option>
                ))}
              </select>
            </div>
            
            {/* Service */}
            <div className="mb-4">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Service</label>
              <select 
                className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                value={selectedService || ''} 
                onChange={(e) => {
                  setSelectedService(e.target.value || null);
                  setSelectedProvider(null);
                }}
              >
                <option value="">All Services</option>
                {serviceOptions.map(service => (
                  <option key={service} value={service}>{service}</option>
                ))}
              </select>
            </div>
            
            {/* Provider (conditional) */}
            {selectedService && (
              <div className="mb-4">
                <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Provider</label>
                <select 
                  className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                  value={selectedProvider || ''} 
                  onChange={(e) => setSelectedProvider(e.target.value || null)}
                >
                  <option value="">All Providers</option>
                  {providerOptions.map(provider => (
                    <option key={provider} value={provider}>{provider}</option>
                  ))}
                </select>
              </div>
            )}
            
            {/* Day of Week */}
            <div className="mb-4">
              <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Day of Week</label>
              <div className="relative">
                <button 
                  className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md w-full p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark text-left flex justify-between items-center"
                  onClick={() => setShowDaysDropdown(!showDaysDropdown)}
                >
                  <span>{selectedDays.join(', ')}</span>
                  <span className="ml-2">▼</span>
                </button>
                {showDaysDropdown && (
                  <div className="absolute top-full left-0 w-full mt-1 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-lg z-10">
                    <div className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark">
                      <label className="flex items-center">
                        <input 
                          type="checkbox" 
                          className="mr-2" 
                          checked={selectedDays.includes('All')}
                          onChange={() => setSelectedDays(['All'])}
                        />
                        <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">All Days</span>
                      </label>
                    </div>
                    {dayOptions.map(day => (
                      <div key={day} className="p-2 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark">
                        <label className="flex items-center">
                          <input 
                            type="checkbox" 
                            className="mr-2"
                            checked={selectedDays.includes(day)}
                            onChange={() => {
                              if (selectedDays.includes('All')) {
                                setSelectedDays([day]);
                              } else if (selectedDays.includes(day)) {
                                const newDays = selectedDays.filter(d => d !== day);
                                setSelectedDays(newDays.length ? newDays : ['All']);
                              } else {
                                setSelectedDays([...selectedDays.filter(d => d !== 'All'), day]);
                              }
                            }}
                          />
                          <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{day}</span>
                        </label>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
        
        {/* Main Content Area */}
        <div className="w-full md:w-3/4 lg:w-4/5">
          
          {/* Summary Cards */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <Card className="healthcare-card">
              <h5 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">In-Block Utilization</h5>
              <div className="text-2xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
                {siteData.totals?.in_block_utilization || 
                 overallMetrics.byService?.[Object.keys(overallMetrics.byService)[0]]?.in_block_utilization || 
                 "63.5"}%
              </div>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Utilization within allocated block time</p>
            </Card>
            <Card className="healthcare-card">
              <h5 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">Total Block Utilization</h5>
              <div className="text-2xl font-bold text-healthcare-success dark:text-healthcare-success-dark">
                {siteData.totals?.total_block_utilization || 
                 overallMetrics.byService?.[Object.keys(overallMetrics.byService)[0]]?.total_block_utilization || 
                 "75.4"}%
              </div>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Total utilization including out-of-block time</p>
            </Card>
            <Card className="healthcare-card">
              <h5 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">Non-Prime Time %</h5>
              <div className="text-2xl font-bold text-healthcare-warning dark:text-healthcare-warning-dark">
                {siteData.totals?.non_prime_percentage || 
                 overallMetrics.byService?.[Object.keys(overallMetrics.byService)[0]]?.non_prime_percentage || 
                 "16.4"}%
              </div>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Percentage of cases in non-prime time</p>
            </Card>
            <Card className="healthcare-card">
              <h5 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-1">Total Cases</h5>
              <div className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {siteData.totals?.numof_cases || 
                 overallMetrics.byService?.[Object.keys(overallMetrics.byService)[0]]?.cases || 
                 "1,106"}
              </div>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Cases performed in selected period</p>
            </Card>
          </div>
          
          {/* Comparative Data Grid (when comparison is enabled) */}
          {showComparison && (
            <div className="healthcare-card mb-6 overflow-hidden">
              <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Comparative Analysis</h3>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  <thead>
                    <tr className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark">
                      <th className="py-3 px-4 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider border-r border-healthcare-border dark:border-healthcare-border-dark">Location</th>
                      <th className="py-3 px-4 text-center text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider border-r border-healthcare-border dark:border-healthcare-border-dark" colSpan="2">Comparative % Non Prime Time</th>
                      <th className="py-3 px-4 text-center text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider border-r border-healthcare-border dark:border-healthcare-border-dark" colSpan="2">Current % Non Prime Time</th>
                      <th className="py-3 px-4 text-center text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider border-r border-healthcare-border dark:border-healthcare-border-dark" colSpan="2">Comparative Prime Time Util</th>
                      <th className="py-3 px-4 text-center text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider" colSpan="2">Current Prime Time Util</th>
                    </tr>
                  </thead>
                  <tbody className="bg-healthcare-surface dark:bg-healthcare-surface-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    <tr>
                      <td className="py-2 px-4 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-r border-healthcare-border dark:border-healthcare-border-dark">{selectedLocation}</td>
                      <td className="py-2 px-4 text-sm text-center text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-r border-healthcare-border dark:border-healthcare-border-dark" colSpan="2">11.7%</td>
                      <td className="py-2 px-4 text-sm text-center text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-r border-healthcare-border dark:border-healthcare-border-dark" colSpan="2">
                        {siteData.totals?.non_prime_percentage || "16.4"}%
                      </td>
                      <td className="py-2 px-4 text-sm text-center font-medium text-healthcare-success dark:text-healthcare-success-dark border-r border-healthcare-border dark:border-healthcare-border-dark" colSpan="2">72.6%</td>
                      <td className="py-2 px-4 text-sm text-center font-medium text-healthcare-primary dark:text-healthcare-primary-dark" colSpan="2">
                        {siteData.totals?.in_block_utilization || "63.5"}%
                      </td>
                    </tr>
                    <tr className="bg-healthcare-surface-hover dark:bg-healthcare-surface-hover-dark">
                      <td className="py-2 px-4 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-r border-healthcare-border dark:border-healthcare-border-dark">Grand Total</td>
                      <td className="py-2 px-4 text-sm text-center text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-r border-healthcare-border dark:border-healthcare-border-dark" colSpan="2">11.7%</td>
                      <td className="py-2 px-4 text-sm text-center text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-r border-healthcare-border dark:border-healthcare-border-dark" colSpan="2">
                        {siteData.totals?.non_prime_percentage || "16.4"}%
                      </td>
                      <td className="py-2 px-4 text-sm text-center font-medium text-healthcare-success dark:text-healthcare-success-dark border-r border-healthcare-border dark:border-healthcare-border-dark" colSpan="2">72.6%</td>
                      <td className="py-2 px-4 text-sm text-center font-medium text-healthcare-primary dark:text-healthcare-primary-dark" colSpan="2">
                        {siteData.totals?.in_block_utilization || "63.5"}%
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          )}
          
          {/* Tabs */}
          <div className="healthcare-card">
            <Tabs className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark text-[14px]">
              <Tabs.Item title="Overview">
                <div className="space-y-4 pt-4">
                  <div className="healthcare-panel dark:bg-healthcare-surface-dark p-4 rounded-lg">
                    <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Block Utilization Overview - {selectedLocation}</h3>
                    <div className="h-80">
                      <NivoThemeProvider>
                        <BarChart 
                          data={serviceData}
                          keys={['inBlockUtilization', 'totalBlockUtilization']}
                          indexBy="name"
                          margin={{ top: 20, right: 30, left: 60, bottom: 70 }}
                          colorScheme="primary"
                          layout="vertical"
                        />
                      </NivoThemeProvider>
                    </div>
                  </div>
                  <div className="healthcare-panel dark:bg-healthcare-surface-dark p-4 rounded-lg">
                    <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Non-Prime Time Percentage by Service - {selectedLocation}</h3>
                    <div className="h-80">
                      <NivoThemeProvider>
                        <BarChart 
                          data={serviceData}
                          keys={['nonPrimePercentage']}
                          indexBy="name"
                          margin={{ top: 20, right: 30, left: 60, bottom: 70 }}
                          colorScheme="warning"
                          layout="vertical"
                        />
                      </NivoThemeProvider>
                    </div>
                  </div>
                </div>
              </Tabs.Item>
              <Tabs.Item title="Services">
                <div className="space-y-4 pt-4">
                  <div className="healthcare-panel dark:bg-healthcare-surface-dark p-4 rounded-lg">
                    <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Service Utilization Comparison - {selectedLocation}</h3>
                    <div className="h-96">
                      <NivoThemeProvider>
                        <BarChart 
                          data={serviceData}
                          keys={['inBlockUtilization', 'totalBlockUtilization']}
                          indexBy="name"
                          margin={{ top: 20, right: 30, left: 150, bottom: 70 }}
                          colorScheme="primary"
                          layout="horizontal"
                        />
                      </NivoThemeProvider>
                    </div>
                  </div>
                </div>
              </Tabs.Item>
              <Tabs.Item title="Day of Week">
                <div className="space-y-4 pt-4">
                  <div className="healthcare-panel dark:bg-healthcare-surface-dark p-4 rounded-lg">
                    <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Day of Week Utilization - {selectedService || 'All Services'}</h3>
                    <div className="h-80">
                      <NivoThemeProvider>
                        <BarChart 
                          data={dayOfWeekData}
                          keys={['utilization']}
                          indexBy="name"
                          margin={{ top: 20, right: 30, left: 60, bottom: 10 }}
                          colorScheme="primary"
                          layout="vertical"
                        />
                      </NivoThemeProvider>
                    </div>
                  </div>
                </div>
              </Tabs.Item>
              <Tabs.Item title="Trends">
                <div className="space-y-4 pt-4">
                  <div className="healthcare-panel dark:bg-healthcare-surface-dark p-4 rounded-lg">
                    <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Utilization Trends - VORH JRI OR</h3>
                    <div className="h-80">
                      <NivoThemeProvider>
                        <LineChart 
                          data={trendData}
                          margin={{ top: 20, right: 30, left: 60, bottom: 10 }}
                          colorScheme="primary"
                          enableArea={true}
                          curve="monotoneX"
                        />
                      </NivoThemeProvider>
                    </div>
                  </div>
                  <div className="healthcare-panel dark:bg-healthcare-surface-dark p-4 rounded-lg">
                    <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">Non-Prime Time Trends - VORH JRI OR</h3>
                    <div className="h-80">
                      <NivoThemeProvider>
                        <LineChart 
                          data={nonPrimeTimeTrendData}
                          margin={{ top: 20, right: 30, left: 60, bottom: 10 }}
                          colorScheme="success"
                          enableArea={true}
                          curve="monotoneX"
                        />
                      </NivoThemeProvider>
                    </div>
                  </div>
                </div>
              </Tabs.Item>
            </Tabs>
          </div>
        </div>
      </div>
    </div>
  );
}
