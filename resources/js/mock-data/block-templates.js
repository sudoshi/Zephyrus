export const mockBlockTemplates = [
    {
        block_id: 1,
        room_id: 1,
        service_id: 1,
        days_of_week: [1, 3, 5], // Mon, Wed, Fri
        start_time: '08:00:00',
        end_time: '12:00:00'
    },
    {
        block_id: 2,
        room_id: 2,
        service_id: 2,
        days_of_week: [2, 4], // Tue, Thu
        start_time: '13:00:00',
        end_time: '17:00:00'
    },
    {
        block_id: 3,
        room_id: 3,
        service_id: 3,
        days_of_week: [1, 2, 3, 4, 5], // Mon-Fri
        start_time: '09:00:00',
        end_time: '15:00:00'
    }
];

export const mockBlockUtilization = {
    utilization: [
        {
            block_id: 1,
            title: 'Morning Block',
            service_name: 'Orthopedics',
            utilization_percentage: 85
        },
        {
            block_id: 2,
            title: 'Afternoon Block',
            service_name: 'General Surgery',
            utilization_percentage: 75
        },
        {
            block_id: 3,
            title: 'All-Day Block',
            service_name: 'Cardiology',
            utilization_percentage: 90
        }
    ]
};

export const mockServices = [
    {
        service_id: 1,
        name: 'Orthopedics',
        code: 'ORTHO'
    },
    {
        service_id: 2,
        name: 'General Surgery',
        code: 'GS'
    },
    {
        service_id: 3,
        name: 'Cardiology',
        code: 'CARD'
    },
    {
        service_id: 4,
        name: 'Neurosurgery',
        code: 'NEURO'
    },
    {
        service_id: 5,
        name: 'Urology',
        code: 'URO'
    }
];
