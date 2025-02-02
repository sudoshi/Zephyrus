const generateServiceData = () => {
  // Service performance data
  const services = [
    {
      service_id: 1,
      service_name: "Orthopedics",
      case_count: 245,
      avg_utilization: 85.3,
      avg_turnover: 28.5,
      on_time_start_percentage: 87.5,
      avg_duration: 120,
    },
    {
      service_id: 2,
      service_name: "General Surgery",
      case_count: 312,
      avg_utilization: 83.1,
      avg_turnover: 25.8,
      on_time_start_percentage: 84.2,
      avg_duration: 95,
    },
    {
      service_id: 3,
      service_name: "Cardiology",
      case_count: 178,
      avg_utilization: 81.9,
      avg_turnover: 29.1,
      on_time_start_percentage: 86.1,
      avg_duration: 150,
    },
    {
      service_id: 4,
      service_name: "Neurosurgery",
      case_count: 156,
      avg_utilization: 79.4,
      avg_turnover: 26.7,
      on_time_start_percentage: 83.0,
      avg_duration: 180,
    },
    {
      service_id: 5,
      service_name: "ENT",
      case_count: 289,
      avg_utilization: 78.6,
      avg_turnover: 24.3,
      on_time_start_percentage: 88.5,
      avg_duration: 75,
    },
  ];

  // Generate 30 days of trend data
  const trends = [];
  for (let i = 0; i < 30; i++) {
    const date = new Date();
    date.setDate(date.getDate() - (29 - i));

    trends.push({
      date: date.toISOString().split("T")[0],
      utilization: Math.floor(Math.random() * 20) + 70, // 70-90%
      case_count: Math.floor(Math.random() * 15) + 10,
    });
  }

  // Calculate summary metrics
  const totals = {
    average_utilization:
      services.reduce((acc, s) => acc + s.avg_utilization, 0) / services.length,
    total_cases: services.reduce((acc, s) => acc + s.case_count, 0),
    average_turnover:
      services.reduce((acc, s) => acc + s.avg_turnover, 0) / services.length,
  };

  const sites = {
    "Default Site": {
      services,
      totals,
    },
  };

  return {
    sites,
    trends,
  };
};

const mockServiceAnalytics = generateServiceData();

export { mockServiceAnalytics };
