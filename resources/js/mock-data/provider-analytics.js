const generateProviderData = () => {
    // Provider performance data
    const providers = [
        {
            provider_id: 1,
            provider_name: "Dr. Sarah Johnson",
            specialty: "Orthopedic Surgery",
            total_cases: 145,
            avg_cases_per_day: 4.8,
            on_time_percentage: 85,
            avg_case_duration: 120,
            utilization: 82,
            procedures: [
                { name: "Total Knee Replacement", count: 45 },
                { name: "Hip Replacement", count: 38 },
                { name: "Shoulder Arthroscopy", count: 32 },
                { name: "ACL Repair", count: 30 }
            ]
        },
        {
            provider_id: 2,
            provider_name: "Dr. Michael Chen",
            specialty: "General Surgery",
            total_cases: 168,
            avg_cases_per_day: 5.6,
            on_time_percentage: 78,
            avg_case_duration: 95,
            utilization: 75,
            procedures: [
                { name: "Laparoscopic Cholecystectomy", count: 52 },
                { name: "Appendectomy", count: 48 },
                { name: "Hernia Repair", count: 38 },
                { name: "Breast Biopsy", count: 30 }
            ]
        },
        {
            provider_id: 3,
            provider_name: "Dr. Emily Rodriguez",
            specialty: "Cardiac Surgery",
            total_cases: 89,
            avg_cases_per_day: 3.0,
            on_time_percentage: 92,
            avg_case_duration: 180,
            utilization: 88,
            procedures: [
                { name: "CABG", count: 35 },
                { name: "Valve Replacement", count: 28 },
                { name: "Angioplasty", count: 15 },
                { name: "Pacemaker Insertion", count: 11 }
            ]
        },
        {
            provider_id: 4,
            provider_name: "Dr. James Wilson",
            specialty: "Neurosurgery",
            total_cases: 72,
            avg_cases_per_day: 2.4,
            on_time_percentage: 88,
            avg_case_duration: 210,
            utilization: 85,
            procedures: [
                { name: "Craniotomy", count: 25 },
                { name: "Spinal Fusion", count: 20 },
                { name: "Brain Tumor Resection", count: 15 },
                { name: "Disc Surgery", count: 12 }
            ]
        },
        {
            provider_id: 5,
            provider_name: "Dr. Lisa Thompson",
            specialty: "ENT Surgery",
            total_cases: 198,
            avg_cases_per_day: 6.6,
            on_time_percentage: 82,
            avg_case_duration: 75,
            utilization: 79,
            procedures: [
                { name: "Tonsillectomy", count: 65 },
                { name: "Septoplasty", count: 55 },
                { name: "Sinus Surgery", count: 45 },
                { name: "Ear Tube Placement", count: 33 }
            ]
        }
    ];

    // Generate 30 days of trend data with more realistic patterns
    const trends = [];
    for (let i = 0; i < 30; i++) {
        const date = new Date();
        date.setDate(date.getDate() - (29 - i));
        
        // Create more realistic patterns with slight variations
        // Weekday vs weekend patterns
        const isWeekend = date.getDay() === 0 || date.getDay() === 6;
        const baseCount = isWeekend ? 8 : 15;
        const baseOnTime = isWeekend ? 85 : 82;
        
        trends.push({
            date: date.toISOString().split('T')[0],
            case_count: Math.floor(Math.random() * 5) + baseCount,
            on_time_percentage: Math.floor(Math.random() * 10) + baseOnTime,
            avg_duration: Math.floor(Math.random() * 30) + 90
        });
    }

    // Calculate summary metrics
    const summary = {
        total_cases: providers.reduce((acc, p) => acc + p.total_cases, 0),
        avg_on_time: providers.reduce((acc, p) => acc + p.on_time_percentage, 0) / providers.length,
        avg_duration: providers.reduce((acc, p) => acc + p.avg_case_duration, 0) / providers.length,
        avg_utilization: providers.reduce((acc, p) => acc + p.utilization, 0) / providers.length,
        total_procedures: providers.reduce((acc, p) => acc + p.procedures.reduce((sum, proc) => sum + proc.count, 0), 0)
    };

    return {
        providers,
        trends,
        summary
    };
};

const mockProviderAnalytics = generateProviderData();
export { mockProviderAnalytics };
