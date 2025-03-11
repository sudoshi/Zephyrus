import { useState, useEffect, useCallback } from 'react';
import axios from 'axios';

/**
 * Custom hook to fetch and manage patient flow data
 * 
 * @param {Object} filters - Filters to apply to the data
 * @returns {Object} - { data, isLoading, error, refresh, derivedMetrics, hasData }
 */
export const usePatientFlowData = (filters) => {
  const [data, setData] = useState({
    nodes: [],
    edges: [],
    metrics: {},
    locations: {},
    departments: {},
    units: {},
    patientTypes: {}
  });
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [hasData, setHasData] = useState(false);
  
  // Derived metrics calculated from raw data
  const [derivedMetrics, setDerivedMetrics] = useState({
    avgCycleTime: 0,
    avgWaitTime: 0,
    throughput: 0,
    bottlenecks: [],
    patientVolume: 0,
    resourceUtilization: 0
  });

  // Function to calculate derived metrics from raw data
  const calculateDerivedMetrics = useCallback((rawData) => {
    if (!rawData || !rawData.metrics) return;
    
    // Sample calculation logic - in a real implementation, this would use actual data
    const metrics = {
      avgCycleTime: rawData.metrics.cycleTime || 0,
      avgWaitTime: rawData.metrics.waitTime || 0,
      throughput: rawData.metrics.throughput || 0,
      bottlenecks: rawData.bottlenecks || [],
      patientVolume: rawData.metrics.volume || 0,
      resourceUtilization: rawData.metrics.utilization || 0
    };
    
    setDerivedMetrics(metrics);
  }, []);

  // Function to refresh data
  const refresh = useCallback(async (force = false) => {
    setIsLoading(true);
    
    try {
      // In a real implementation, this would use the filters to fetch data from the API
      // For now, we'll simulate a data fetch with a timeout
      
      // Construct API endpoint and parameters based on filters
      const params = {
        hospital: filters.selectedHospital,
        department: filters.selectedDepartment,
        patientType: filters.selectedPatientType,
        startDate: filters.dateRange?.startDate?.toISOString(),
        endDate: filters.dateRange?.endDate?.toISOString(),
        showComparison: filters.showComparison,
        compStartDate: filters.comparisonDateRange?.startDate?.toISOString(),
        compEndDate: filters.comparisonDateRange?.endDate?.toISOString()
      };
      
      // Simulate API call
      // In production, this would be an actual API call like:
      // const response = await axios.get('/api/patient-flow', { params });
      
      // For development, we'll simulate the response
      await new Promise(resolve => setTimeout(resolve, 1000));
      
      // Sample data structure
      const sampleData = {
        nodes: [
          { id: 'start', type: 'start', label: 'Admission', position: { x: 0, y: 100 } },
          { id: 'triage', type: 'activity', label: 'Triage', position: { x: 150, y: 100 } },
          { id: 'assessment', type: 'activity', label: 'Initial Assessment', position: { x: 300, y: 100 } },
          { id: 'treatment', type: 'activity', label: 'Treatment', position: { x: 450, y: 100 } },
          { id: 'discharge', type: 'end', label: 'Discharge', position: { x: 600, y: 100 } }
        ],
        edges: [
          { id: 'e1', source: 'start', target: 'triage', label: '100%' },
          { id: 'e2', source: 'triage', target: 'assessment', label: '100%' },
          { id: 'e3', source: 'assessment', target: 'treatment', label: '100%' },
          { id: 'e4', source: 'treatment', target: 'discharge', label: '100%' }
        ],
        metrics: {
          cycleTime: 240, // minutes
          waitTime: 45, // minutes
          throughput: 120, // patients per day
          volume: 3600, // patients per month
          utilization: 78 // percent
        },
        bottlenecks: [
          { nodeId: 'assessment', waitTime: 25, utilization: 92 },
          { nodeId: 'treatment', waitTime: 15, utilization: 85 }
        ],
        locations: {
          'Virtua Marlton Hospital': { id: 'marh', name: 'Virtua Marlton Hospital', fullName: 'Virtua Marlton Hospital' },
          'Virtua Mount Holly Hospital': { id: 'memh', name: 'Virtua Mount Holly Hospital', fullName: 'Virtua Mount Holly Hospital' },
          'Virtua Our Lady of Lourdes Hospital': { id: 'ollh', name: 'Virtua Our Lady of Lourdes Hospital', fullName: 'Virtua Our Lady of Lourdes Hospital' },
          'Virtua Voorhees Hospital': { id: 'vorh', name: 'Virtua Voorhees Hospital', fullName: 'Virtua Voorhees Hospital' }
        },
        departments: {
          'Emergency': { id: 'er', name: 'Emergency' },
          'Surgery': { id: 'surg', name: 'Surgery' },
          'Medical/Surgical': { id: 'medsurg', name: 'Medical/Surgical' },
          'ICU': { id: 'icu', name: 'ICU' },
          'Cardiology': { id: 'cardio', name: 'Cardiology' }
        },
        units: {
          'ER-1': { id: 'er1', name: 'ER-1', department: 'Emergency' },
          'ER-2': { id: 'er2', name: 'ER-2', department: 'Emergency' },
          'OR-1': { id: 'or1', name: 'OR-1', department: 'Surgery' },
          'OR-2': { id: 'or2', name: 'OR-2', department: 'Surgery' },
          'MS-1': { id: 'ms1', name: 'MS-1', department: 'Medical/Surgical' }
        },
        patientTypes: {
          'Inpatient': { id: 'ip', name: 'Inpatient' },
          'Outpatient': { id: 'op', name: 'Outpatient' },
          'Emergency': { id: 'er', name: 'Emergency' },
          'Observation': { id: 'obs', name: 'Observation' },
          'Surgical': { id: 'surg', name: 'Surgical' }
        }
      };
      
      setData(sampleData);
      calculateDerivedMetrics(sampleData);
      setHasData(true);
      setError(null);
    } catch (err) {
      console.error('Error fetching patient flow data:', err);
      setError(err.message || 'Failed to load patient flow data');
      setHasData(false);
    } finally {
      setIsLoading(false);
    }
  }, [filters, calculateDerivedMetrics]);

  // Fetch data when filters change
  useEffect(() => {
    refresh();
  }, [
    refresh,
    filters.selectedHospital,
    filters.selectedDepartment,
    filters.selectedPatientType,
    filters.dateRange?.startDate,
    filters.dateRange?.endDate,
    filters.showComparison
  ]);

  return { 
    data, 
    isLoading, 
    error, 
    refresh,
    derivedMetrics,
    hasData
  };
};

// Default export removed to standardize on named exports only
