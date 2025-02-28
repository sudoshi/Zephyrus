/**
 * Utility functions for transforming and integrating data for analytics dashboards
 */

/**
 * Transforms provider data from CSV format to a structured object
 * @param {Array} providerData - Raw provider data from CSV
 * @returns {Object} Structured provider data
 */
export const transformProviderData = (providerData) => {
  // Group providers by specialty
  const providersBySpecialty = {};
  const providersByGroup = {};
  const allProviders = {};

  providerData.forEach(provider => {
    const { provider_name, surgeon_group_name, title, specialty } = provider;
    
    // Create provider object
    const providerObj = {
      id: slugifyProviderName(provider_name),
      name: provider_name,
      group: surgeon_group_name,
      title: title || '',
      specialty: specialty || ''
    };
    
    // Add to all providers
    allProviders[providerObj.id] = providerObj;
    
    // Group by specialty
    const specialties = specialty ? specialty.split(',').map(s => s.trim()) : ['Unknown'];
    specialties.forEach(spec => {
      if (!providersBySpecialty[spec]) {
        providersBySpecialty[spec] = [];
      }
      providersBySpecialty[spec].push(providerObj);
    });
    
    // Group by surgeon group
    if (!providersByGroup[surgeon_group_name]) {
      providersByGroup[surgeon_group_name] = [];
    }
    providersByGroup[surgeon_group_name].push(providerObj);
  });
  
  return {
    allProviders,
    providersBySpecialty,
    providersByGroup
  };
};

/**
 * Transforms location data from CSV and JSON to a structured object
 * @param {Object} locationData - Location data from JSON
 * @param {Array} roomMapData - Room mapping data from CSV
 * @returns {Object} Structured location data
 */
export const transformLocationData = (locationData, roomMapData) => {
  const { hospitals, orLocations } = locationData;
  
  // Create hospitals map
  const hospitalsMap = {};
  hospitals.forEach(hospital => {
    hospitalsMap[hospital.id] = hospital;
  });
  
  // Create OR locations map
  const orLocationsMap = {};
  orLocations.forEach(location => {
    orLocationsMap[location.id] = {
      ...location,
      hospital: hospitalsMap[location.hospitalId],
      rooms: []
    };
  });
  
  // Add rooms to OR locations
  roomMapData.forEach(room => {
    const { raw_room, prod_room } = room;
    
    // Extract location code from raw_room
    let locationCode = '';
    if (raw_room.includes('MARH')) {
      locationCode = 'marh-or';
    } else if (raw_room.includes('MEMH MHAS')) {
      locationCode = 'memh-mhas-or';
    } else if (raw_room.includes('MEMH')) {
      locationCode = 'memh-or';
    } else if (raw_room.includes('OLLH')) {
      locationCode = 'ollh-or';
    } else if (raw_room.includes('VORH JRI')) {
      locationCode = 'vorh-jri-or';
    } else if (raw_room.includes('VORH')) {
      locationCode = 'vorh-or';
    } else if (raw_room.includes('VSSC')) {
      locationCode = 'vssc-or';
    } else if (raw_room.includes('WILH')) {
      locationCode = 'wilh-or';
    }
    
    if (locationCode && orLocationsMap[locationCode]) {
      orLocationsMap[locationCode].rooms.push({
        raw_name: raw_room,
        prod_name: prod_room,
        id: prod_room
      });
    }
  });
  
  return {
    hospitals: hospitalsMap,
    orLocations: orLocationsMap,
    allLocations: Object.values(orLocationsMap)
  };
};

/**
 * Integrates provider, location, and utilization data
 * @param {Object} providerData - Transformed provider data
 * @param {Object} locationData - Transformed location data
 * @param {Object} utilizationData - OR utilization data
 * @returns {Object} Integrated data for the dashboard
 */
export const integrateData = (providerData, locationData, utilizationData) => {
  const { allProviders, providersBySpecialty } = providerData;
  const { orLocations, allLocations } = locationData;
  
  // Integrate location data with utilization data
  const integratedLocations = {};
  
  Object.entries(utilizationData.sites).forEach(([siteName, siteData]) => {
    // Find matching location
    const location = allLocations.find(loc => loc.name === siteName);
    
    if (location) {
      // Integrate room data
      const integratedRooms = siteData.rooms.map(room => {
        // Find matching room in location data
        const locationRoom = location.rooms.find(r => r.raw_name === room.room);
        
        return {
          ...room,
          locationId: location.id,
          locationName: location.name,
          hospitalId: location.hospitalId,
          hospitalName: location.hospital?.name || '',
          ...(locationRoom || {})
        };
      });
      
      integratedLocations[siteName] = {
        ...siteData,
        id: location.id,
        hospitalId: location.hospitalId,
        hospitalName: location.hospital?.name || '',
        fullName: location.fullName,
        rooms: integratedRooms
      };
    } else {
      integratedLocations[siteName] = siteData;
    }
  });
  
  // Integrate specialty data
  const specialtyUtilization = {};
  
  Object.entries(utilizationData.services || {}).forEach(([specialtyName, data]) => {
    specialtyUtilization[specialtyName] = {
      ...data,
      providers: providersBySpecialty[specialtyName] || []
    };
  });
  
  return {
    locations: integratedLocations,
    specialties: specialtyUtilization,
    providers: allProviders,
    trends: utilizationData.trends || {},
    dayOfWeek: utilizationData.dayOfWeek || {},
    overallMetrics: utilizationData.overallMetrics || {}
  };
};

/**
 * Helper function to create a slug from a provider name
 * @param {string} fullName - Provider's full name
 * @returns {string} Slugified name
 */
export const slugifyProviderName = (fullName) => {
  return fullName
    .toLowerCase()
    // Convert any newline or multiple spaces to single space
    .replace(/\s+/g, ' ')
    // Remove everything except letters, numbers, spaces, commas
    .replace(/[^a-z0-9 ,]/g, '')
    // Trim leading/trailing spaces
    .trim()
    // Replace spaces and commas with single hyphens
    .replace(/[ ,]+/g, '-');
};

/**
 * Formats data for charts
 * @param {Array} data - Raw data array
 * @param {string} indexKey - Key to use as index
 * @param {string|Array} valueKey - Key(s) to use as value
 * @param {string|Array} seriesName - Name(s) for the series
 * @returns {Object} Formatted chart data
 */
export const formatChartData = (data, indexKey, valueKey, seriesName) => {
  if (!data || !data.length) return [];
  
  if (Array.isArray(valueKey)) {
    // Multiple series
    return data.map(item => {
      const result = { [indexKey]: item[indexKey] };
      
      valueKey.forEach((key, index) => {
        const name = Array.isArray(seriesName) ? seriesName[index] : `Series ${index + 1}`;
        result[name] = item[key];
      });
      
      return result;
    });
  } else {
    // Single series
    return data.map(item => ({
      [indexKey]: item[indexKey],
      [seriesName || 'value']: item[valueKey]
    }));
  }
};

/**
 * Calculates opportunity metrics based on utilization data
 * @param {Object} locationData - Location utilization data
 * @param {number} targetUtilization - Target utilization percentage
 * @returns {Object} Opportunity metrics
 */
export const calculateOpportunity = (locationData, targetUtilization = 75.0) => {
  const currentUtil = locationData.utilization || 0;
  
  if (currentUtil >= targetUtilization) {
    return {
      utilizationGap: 0,
      potentialAdditionalCases: 0,
      potentialRevenue: 0
    };
  }
  
  const utilizationGap = targetUtilization - currentUtil;
  const avgCaseDuration = locationData.averageCaseDuration || 120; // minutes
  const availableMinutesPerRoom = 480; // 8 hours
  const daysPerMonth = 22; // approximate
  const roomCount = locationData.rooms?.length || 0;
  
  const additionalMinutes = (utilizationGap / 100) * availableMinutesPerRoom * roomCount * daysPerMonth;
  const additionalCases = Math.floor(additionalMinutes / avgCaseDuration);
  
  // Estimate potential revenue (placeholder calculation)
  const avgRevenuePerCase = 5000; // placeholder value
  const potentialRevenue = additionalCases * avgRevenuePerCase;
  
  return {
    utilizationGap,
    potentialAdditionalCases: additionalCases,
    potentialRevenue
  };
};

/**
 * Calculates efficiency metrics
 * @param {Object} locationData - Location utilization data
 * @returns {Object} Efficiency metrics
 */
export const calculateEfficiencyMetrics = (locationData) => {
  const turnoverTime = locationData.averageTurnoverTime || 0;
  const caseDuration = locationData.averageCaseDuration || 0;
  
  // Calculate efficiency ratio (case duration / total time)
  const efficiencyRatio = caseDuration / (caseDuration + turnoverTime);
  
  // Calculate cases per day per room
  const minutesPerDay = 480; // 8 hours
  const casesPerDay = minutesPerDay / (caseDuration + turnoverTime);
  
  return {
    efficiencyRatio: efficiencyRatio * 100, // as percentage
    casesPerDay,
    turnoverTime,
    caseDuration
  };
};
