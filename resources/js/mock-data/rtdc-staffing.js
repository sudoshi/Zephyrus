export const staffingData = {
    currentShift: {
        coverage: 90,
        present: 403,
        required: 448,
        shortage: -45,
        skillMix: {
            'Medical-Surgical': {
                present: 200,
                required: 228,
                gap: -28,
                coverage: 88,
                breakdown: {
                    'RN': { present: 150, required: 171 },
                    'CNA': { present: 35, required: 40 },
                    'Support': { present: 15, required: 17 }
                },
                nextShift: {
                    scheduled: 210,
                    required: 228,
                    coverage: 92
                }
            },
            'ICU': {
                present: 75,
                required: 80,
                gap: -5,
                coverage: 94,
                breakdown: {
                    'RN': { present: 60, required: 64 },
                    'CNA': { present: 10, required: 11 },
                    'Support': { present: 5, required: 5 }
                },
                nextShift: {
                    scheduled: 78,
                    required: 80,
                    coverage: 98
                }
            },
            'Neonatal ICU': {
                present: 55,
                required: 58,
                gap: -3,
                coverage: 95,
                breakdown: {
                    'RN': { present: 45, required: 47 },
                    'CNA': { present: 7, required: 8 },
                    'Support': { present: 3, required: 3 }
                },
                nextShift: {
                    scheduled: 58,
                    required: 58,
                    coverage: 100
                }
            },
            'Pediatrics': {
                present: 38,
                required: 40,
                gap: -2,
                coverage: 95,
                breakdown: {
                    'RN': { present: 30, required: 31 },
                    'CNA': { present: 5, required: 6 },
                    'Support': { present: 3, required: 3 }
                },
                nextShift: {
                    scheduled: 40,
                    required: 40,
                    coverage: 100
                }
            },
            'Obstetrics': {
                present: 35,
                required: 40,
                gap: -5,
                coverage: 88,
                breakdown: {
                    'RN': { present: 28, required: 32 },
                    'CNA': { present: 5, required: 6 },
                    'Support': { present: 2, required: 2 }
                },
                nextShift: {
                    scheduled: 38,
                    required: 40,
                    coverage: 95
                }
            }
        }
    },
    nextShift: {
        scheduled: 424,
        required: 448,
        coverage: 95,
        startTime: '19:00',
        callouts: 2,
        floating: 6
    },
    trends: {
        hourly: [
            { hour: '07:00', coverage: 92 },
            { hour: '08:00', coverage: 91 },
            { hour: '09:00', coverage: 90 },
            { hour: '10:00', coverage: 89 },
            { hour: '11:00', coverage: 90 },
            { hour: '12:00', coverage: 90 },
            { hour: '13:00', coverage: 90 }
        ]
    },
    staffPool: {
        float: {
            available: 8,
            deployed: 6,
            qualified: {
                'Medical-Surgical': 8,
                'ICU': 3,
                'Pediatrics': 4,
                'Obstetrics': 2
            }
        },
        agency: {
            onDuty: 15,
            scheduled: 18,
            requested: 25
        }
    },
    recommendations: [
        {
            unit: 'Medical-Surgical',
            action: 'Add 28 staff',
            priority: 'High',
            status: 'In Progress',
            details: 'Float pool and agency staff being contacted'
        },
        {
            unit: 'ICU',
            action: 'Add 5 staff',
            priority: 'High',
            status: 'In Progress',
            details: 'Specialized ICU staff needed'
        },
        {
            unit: 'Neonatal ICU',
            action: 'Add 3 staff',
            priority: 'Medium',
            status: 'Pending',
            details: 'Reviewing skill mix requirements'
        },
        {
            unit: 'Pediatrics',
            action: 'Add 2 staff',
            priority: 'Low',
            status: 'Scheduled',
            details: 'Staff identified for next shift'
        },
        {
            unit: 'Obstetrics',
            action: 'Add 5 staff',
            priority: 'Medium',
            status: 'In Progress',
            details: 'Agency staff scheduled'
        }
    ],
    callouts: [
        {
            unit: 'Medical-Surgical',
            shift: 'Day',
            count: 3,
            status: 'Not Covered',
            impact: 'High'
        },
        {
            unit: 'ICU',
            shift: 'Day',
            count: 1,
            status: 'Partially Covered',
            impact: 'Medium'
        }
    ],
    skillMixSummary: {
        'RN': {
            total: 313,
            required: 345,
            coverage: 91
        },
        'CNA': {
            total: 62,
            required: 71,
            coverage: 87
        },
        'Support': {
            total: 28,
            required: 32,
            coverage: 88
        }
    },
    forecasts: [
        {
            time: 'Now+1h',
            department: 'Medical-Surgical',
            predicted: 200,
            confidence: 85,
            lowerBound: 190,
            upperBound: 210
        },
        {
            time: 'Now+1h',
            department: 'ICU',
            predicted: 75,
            confidence: 85,
            lowerBound: 70,
            upperBound: 80
        },
        {
            time: 'Now+1h',
            department: 'Neonatal ICU',
            predicted: 55,
            confidence: 85,
            lowerBound: 50,
            upperBound: 60
        },
        {
            time: 'Now+1h',
            department: 'Pediatrics',
            predicted: 38,
            confidence: 85,
            lowerBound: 35,
            upperBound: 41
        },
        {
            time: 'Now+2h',
            department: 'Medical-Surgical',
            predicted: 205,
            confidence: 80,
            lowerBound: 195,
            upperBound: 215
        },
        {
            time: 'Now+2h',
            department: 'ICU',
            predicted: 78,
            confidence: 80,
            lowerBound: 73,
            upperBound: 83
        },
        {
            time: 'Now+2h',
            department: 'Neonatal ICU',
            predicted: 56,
            confidence: 80,
            lowerBound: 51,
            upperBound: 61
        },
        {
            time: 'Now+2h',
            department: 'Pediatrics',
            predicted: 39,
            confidence: 80,
            lowerBound: 36,
            upperBound: 42
        },
        {
            time: 'Now+3h',
            department: 'Medical-Surgical',
            predicted: 210,
            confidence: 90,
            lowerBound: 200,
            upperBound: 220
        },
        {
            time: 'Now+3h',
            department: 'ICU',
            predicted: 80,
            confidence: 90,
            lowerBound: 75,
            upperBound: 85
        },
        {
            time: 'Now+3h',
            department: 'Neonatal ICU',
            predicted: 57,
            confidence: 90,
            lowerBound: 52,
            upperBound: 62
        },
        {
            time: 'Now+3h',
            department: 'Pediatrics',
            predicted: 40,
            confidence: 90,
            lowerBound: 37,
            upperBound: 43
        },
        {
            time: 'Now+4h',
            department: 'Medical-Surgical',
            predicted: 208,
            confidence: 75,
            lowerBound: 198,
            upperBound: 218
        },
        {
            time: 'Now+4h',
            department: 'ICU',
            predicted: 79,
            confidence: 75,
            lowerBound: 74,
            upperBound: 84
        },
        {
            time: 'Now+4h',
            department: 'Neonatal ICU',
            predicted: 56,
            confidence: 75,
            lowerBound: 51,
            upperBound: 61
        },
        {
            time: 'Now+4h',
            department: 'Pediatrics',
            predicted: 39,
            confidence: 75,
            lowerBound: 36,
            upperBound: 42
        }
    ]
};
