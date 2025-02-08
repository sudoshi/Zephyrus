import { services } from './rtdc';

// Helper functions for generating realistic trend data
const generateTimePoints = (timeRange, interval = 60) => {
    const now = new Date();
    const points = [];
    let time;

    switch (timeRange) {
        case '24h':
            // Generate points every hour for last 24 hours
            for (let i = 24; i >= 0; i--) {
                time = new Date(now.getTime() - i * 60 * 60 * 1000);
                points.push(time);
            }
            break;
        case '7d':
            // Generate points every 4 hours for last 7 days
            for (let i = 7 * 24; i >= 0; i -= 4) {
                time = new Date(now.getTime() - i * 60 * 60 * 1000);
                points.push(time);
            }
            break;
        case '30d':
            // Generate points every day for last 30 days
            for (let i = 30; i >= 0; i--) {
                time = new Date(now.getTime() - i * 24 * 60 * 60 * 1000);
                points.push(time);
            }
            break;
    }
    return points;
};

// Generate base pattern for different service types
const generateBasePattern = (hour, serviceType) => {
    // Base patterns for different service types
    // Map service categories to pattern types
    const categoryToPattern = {
        imaging: 'imaging',
        therapy: 'therapy',
        support: 'support'
    };

    const patterns = {
        imaging: {
            // Higher demand during day shifts (8am-6pm), moderate in evening, low at night
            baseline: 45,
            dayShift: hour >= 8 && hour < 18 ? 30 : 0,
            eveningShift: hour >= 18 && hour < 22 ? 15 : 0,
            nightShift: hour >= 22 || hour < 8 ? -15 : 0,
            peakHours: [10, 14], // Mid-morning and mid-afternoon peaks
        },
        therapy: {
            // Concentrated during therapy hours (8am-5pm)
            baseline: 40,
            dayShift: hour >= 8 && hour < 17 ? 35 : -30,
            peakHours: [9, 13, 15], // Morning and afternoon therapy sessions
        },
        support: {
            // Varies by specific service
            baseline: 35,
            dayShift: hour >= 6 && hour < 22 ? 25 : -15,
            peakHours: [9, 14, 19], // Medication times and shift changes
        }
    };

    // Get the appropriate pattern type for the service category
    const patternType = categoryToPattern[serviceType] || 'support';

    const pattern = patterns[patternType];
    let value = pattern.baseline;

    // Add shift adjustments
    value += pattern.dayShift || 0;
    value += pattern.eveningShift || 0;
    value += pattern.nightShift || 0;

    // Add peak hour effects
    if (pattern.peakHours.includes(hour)) {
        value += 20;
    }

    return value;
};

// Add realistic variations to the base pattern
const addVariations = (baseValue, timeRange) => {
    // Random variation (±10%)
    const randomVariation = (Math.random() - 0.5) * 0.2 * baseValue;
    
    // Day of week effect (weekdays busier than weekends)
    const day = new Date().getDay();
    const weekendEffect = (day === 0 || day === 6) ? -10 : 0;
    
    // Monthly pattern (beginning of month busier)
    const dayOfMonth = new Date().getDate();
    const monthlyEffect = dayOfMonth <= 5 ? 10 : 0;

    return Math.max(15, Math.round(baseValue + randomVariation + weekendEffect + monthlyEffect));
};

// Generate trend data for a specific service
export const generateServiceTrend = (serviceType, timeRange = '24h') => {
    const timePoints = generateTimePoints(timeRange);
    
    // Map service type to pattern type
    const patternType = serviceType === 'imaging' ? 'imaging' :
                       serviceType === 'therapy' ? 'therapy' :
                       'support';
    
    return timePoints.map(time => {
        const hour = time.getHours();
        const baseValue = generateBasePattern(hour, patternType);
        const value = addVariations(baseValue, timeRange);
        
        return {
            time: time.toISOString(),
            value,
            // Additional metadata for analysis
            isWeekend: time.getDay() === 0 || time.getDay() === 6,
            shift: hour >= 7 && hour < 15 ? 'day' : 
                   hour >= 15 && hour < 23 ? 'evening' : 'night',
        };
    });
};

// Generate trends for all services in a unit
export const generateUnitTrends = (unit, timeRange = '24h') => {
    const trends = {};
    
    Object.entries(unit.services).forEach(([serviceId, service]) => {
        if (service) {
            const serviceInfo = services.find(s => s.id === serviceId);
            if (serviceInfo) {
                trends[serviceId] = generateServiceTrend(serviceInfo.category, timeRange);
            }
        }
    });
    
    return trends;
};

// Helper function to analyze trend direction
export const analyzeTrend = (data) => {
    if (!data || data.length < 2) return 'stable';
    
    const recentValues = data.slice(-6); // Look at last 6 points
    const firstAvg = recentValues.slice(0, 3).reduce((a, b) => a + b.value, 0) / 3;
    const lastAvg = recentValues.slice(-3).reduce((a, b) => a + b.value, 0) / 3;
    
    const difference = lastAvg - firstAvg;
    if (Math.abs(difference) < 5) return 'stable';
    return difference > 0 ? 'increasing' : 'decreasing';
};

// Helper function to get peak times
export const getPeakTimes = (data) => {
    if (!data || data.length === 0) return [];
    
    const threshold = Math.max(...data.map(d => d.value)) * 0.9; // 90% of max
    return data.filter(d => d.value >= threshold)
               .map(d => new Date(d.time).toLocaleTimeString());
};

// Helper function to calculate utilization rate
export const calculateUtilization = (data) => {
    if (!data || data.length === 0) return 0;
    
    const total = data.reduce((sum, point) => sum + point.value, 0);
    return Math.round((total / (data.length * 60)) * 100); // Assuming 60 min is 100% utilization
};

// Export functions for use in components
export const trendUtils = {
    generateServiceTrend,
    generateUnitTrends,
    analyzeTrend,
    getPeakTimes,
    calculateUtilization,
};
