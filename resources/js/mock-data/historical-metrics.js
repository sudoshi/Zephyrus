// Generate dates for the last 6 months
const generateDates = (count) => {
    const dates = [];
    const today = new Date();
    for (let i = count - 1; i >= 0; i--) {
        const date = new Date(today);
        date.setDate(today.getDate() - i);
        dates.push(date.toISOString().split('T')[0]);
    }
    return dates;
};

// Generate smooth trend data with realistic patterns
const generateTrendData = (count, min, max, options = {}) => {
    const {
        trendDirection = 0, // -1 for downward, 0 for neutral, 1 for upward
        volatility = 0.1, // How much random variation to add
        seasonality = 0.1, // Strength of seasonal pattern
        smoothing = 0.7, // How much to smooth the data (0-1)
        weekendEffect = 0, // Reduce values on weekends by this factor
    } = options;

    // Generate base trend
    const trend = Array(count).fill(0).map((_, i) => {
        const trendValue = (i / count) * (max - min) * 0.2 * trendDirection;
        return trendValue;
    });

    // Add seasonality (30-day cycle)
    const seasonalPattern = Array(count).fill(0).map((_, i) => {
        return Math.sin((i / 30) * 2 * Math.PI) * seasonality * (max - min);
    });

    // Generate initial random values
    let values = Array(count).fill(0).map((_, i) => {
        const baseValue = (min + max) / 2;
        const trendValue = trend[i];
        const seasonalValue = seasonalPattern[i];
        const randomValue = (Math.random() - 0.5) * 2 * volatility * (max - min);
        
        // Apply weekend effect if specified
        const date = new Date();
        date.setDate(date.getDate() - (count - 1 - i));
        const isWeekend = date.getDay() === 0 || date.getDay() === 6;
        const weekendMultiplier = isWeekend ? (1 - weekendEffect) : 1;

        return (baseValue + trendValue + seasonalValue + randomValue) * weekendMultiplier;
    });

    // Apply exponential smoothing
    for (let i = 1; i < values.length; i++) {
        values[i] = smoothing * values[i] + (1 - smoothing) * values[i - 1];
    }

    // Ensure values stay within bounds
    return values.map(v => Math.max(min, Math.min(max, v)));
};

const dates = generateDates(180); // 6 months of daily data

// 1. Average Length of Stay (ALOS) Data
const alosData = dates.map((date, i) => ({
    date,
    alos: generateTrendData(180, 4, 6, {
        trendDirection: -0.5,
        volatility: 0.05,
        seasonality: 0.05,
        smoothing: 0.8,
        weekendEffect: 0.1,
    })[i],
    target: 5,
}));

// 2. ED-to-Inpatient Conversion Rate Data
const edConversionData = dates.map((date, i) => ({
    date,
    conversionRate: generateTrendData(180, 15, 25, {
        trendDirection: 0.3,
        volatility: 0.08,
        seasonality: 0.15,
        smoothing: 0.75,
        weekendEffect: 0.15,
    })[i],
    visitVolume: generateTrendData(180, 100, 200, {
        trendDirection: 0.2,
        volatility: 0.15,
        seasonality: 0.2,
        smoothing: 0.7,
        weekendEffect: 0.3,
    })[i],
}));

// 3. Bed Occupancy by Service Line Data
const serviceLines = ['Medicine', 'Surgery', 'ICU', 'Pediatrics', 'Obstetrics'];
const bedOccupancyData = dates.map((date, i) => {
    const dataPoint = { date };
    const serviceConfigs = {
        Medicine: { min: 40, max: 60, seasonality: 0.1, weekendEffect: 0.1 },
        Surgery: { min: 20, max: 35, seasonality: 0.15, weekendEffect: 0.4 },
        ICU: { min: 15, max: 25, seasonality: 0.05, weekendEffect: 0.05 },
        Pediatrics: { min: 10, max: 20, seasonality: 0.2, weekendEffect: 0.1 },
        Obstetrics: { min: 8, max: 15, seasonality: 0.1, weekendEffect: 0.05 },
    };

    serviceLines.forEach(service => {
        const config = serviceConfigs[service];
        const values = generateTrendData(180, config.min, config.max, {
            volatility: 0.1,
            seasonality: config.seasonality,
            smoothing: 0.8,
            weekendEffect: config.weekendEffect,
        });
        dataPoint[service] = values[i];
    });
    return dataPoint;
});

// 4. Patient Flow Metrics Data
const flowMetricsData = dates.map((date, i) => ({
    date,
    edToAdmission: generateTrendData(180, 180, 360, {
        trendDirection: -0.3,
        volatility: 0.1,
        seasonality: 0.15,
        smoothing: 0.75,
        weekendEffect: 0.2,
    })[i],
    dischargeToExit: generateTrendData(180, 90, 180, {
        trendDirection: -0.2,
        volatility: 0.12,
        seasonality: 0.1,
        smoothing: 0.7,
        weekendEffect: 0.15,
    })[i],
    transferTime: generateTrendData(180, 60, 120, {
        trendDirection: -0.1,
        volatility: 0.08,
        seasonality: 0.05,
        smoothing: 0.8,
        weekendEffect: 0.1,
    })[i],
}));

// 5. Staffing vs Census Data
const staffingData = dates.map((date, i) => ({
    date,
    nurseRatio: generateTrendData(180, 0.2, 0.3, {
        trendDirection: 0.1,
        volatility: 0.05,
        seasonality: 0.05,
        smoothing: 0.9,
        weekendEffect: 0.1,
    })[i],
    census: generateTrendData(180, 150, 250, {
        trendDirection: 0.2,
        volatility: 0.1,
        seasonality: 0.15,
        smoothing: 0.8,
        weekendEffect: 0.15,
    })[i],
}));

// 6. Quality Indicators Data
const qualityData = dates.map((date, i) => ({
    date,
    readmissionRate: generateTrendData(180, 8, 12, {
        trendDirection: -0.3,
        volatility: 0.08,
        seasonality: 0.05,
        smoothing: 0.85,
        weekendEffect: 0.05,
    })[i],
    hacRate: generateTrendData(180, 1, 3, {
        trendDirection: -0.4,
        volatility: 0.1,
        seasonality: 0.05,
        smoothing: 0.9,
        weekendEffect: 0,
    })[i],
    satisfactionScore: generateTrendData(180, 85, 95, {
        trendDirection: 0.2,
        volatility: 0.05,
        seasonality: 0.05,
        smoothing: 0.9,
        weekendEffect: 0.05,
    })[i],
}));

export const historicalMetrics = {
    alos: alosData,
    edConversion: edConversionData,
    bedOccupancy: bedOccupancyData,
    flowMetrics: flowMetricsData,
    staffing: staffingData,
    quality: qualityData,
    serviceLines,
};
