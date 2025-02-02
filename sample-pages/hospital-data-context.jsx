// File: resources/js/Pages/HospitalDataContext/hospital-data-context.js

import React, { createContext, useContext, useState, useMemo } from 'react';
import generateMockHospitalData from './generateMockHospitalData';

/**
 * Create a context for hospital data
 */
const HospitalDataContext = createContext();

/**
 * Provider that generates (or fetches) the mock data ONCE
 * and makes it available to any children components.
 */
export function HospitalDataProvider({ children }) {
  // Generate initial data
  const initialData = useMemo(() => generateMockHospitalData(), []);
  
  // Use state to allow updates
  const [patients, setPatients] = useState(initialData.patients);
  const [unitStats] = useState(initialData.unitStats);
  const [lastUpdated, setLastUpdated] = useState(initialData.lastUpdated);

  // ======= Compute "summaryStats" for the Global Huddle =======
  const totalPatients = patients.length;

  // All patients marked as "Critical"
  const criticalPatients = patients.filter((p) => p.status === 'Critical').length;

  // Anyone with an isolation type != 'None'
  const isolationPatients = patients.filter(
    (p) => p.isolation && p.isolation !== 'None'
  ).length;

  // Expected Discharges for *today*
  const expectedDischarges = patients.filter(
    (p) => new Date(p.expectedDC).toDateString() === new Date().toDateString()
  ).length;

  // Summarize discharge barriers by category
  const dischargeBarriers = patients
    .flatMap((p) => p.dischargePlan?.dischargeBarriers || [])
    .reduce((acc, barrier) => {
      const cat = barrier.category || 'unknown';
      if (!acc[cat]) acc[cat] = 0;
      acc[cat]++;
      return acc;
    }, {});

  // Summarize the census by each service line
  const byService = patients.reduce((acc, patient) => {
    acc[patient.service] = (acc[patient.service] || 0) + 1;
    return acc;
  }, {});

  /**
   * summaryStats is what the Global Huddle needs,
   * but we'll also provide `patients` & `unitStats` for the Service Huddle.
   */
  const summaryStats = {
    totalPatients,
    criticalPatients,
    isolationPatients,
    expectedDischarges,
    dischargeBarriers,
    byService,
    unitStats, // The Global Huddle references unitStats directly
  };

  /**
   * Return all data in context:
   * - patients, unitStats for Service Huddle
   * - summaryStats for Global Huddle
   */
  const contextValue = {
    patients,
    setPatients,
    unitStats,
    lastUpdated,
    setLastUpdated,
    summaryStats,
  };

  return (
    <HospitalDataContext.Provider value={contextValue}>
      {children}
    </HospitalDataContext.Provider>
  );
}

/**
 * Simple hook for consuming the hospital data anywhere
 */
export function useHospitalData() {
  return useContext(HospitalDataContext);
}
