import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import LineChart from '@/Components/Dashboard/Charts/LineChart';

const DemandCapacityModel = () => {
    // Sample data - this would come from the API/mock data in real implementation
    const data = [
        { date: '2 PM', demand: 480, capacity: 485 },
        { date: '4 PM', demand: 485, capacity: 488 },
        { date: '6 PM', demand: 490, capacity: 492 },
        { date: '8 PM', demand: 488, capacity: 495 },
        { date: '10 PM', demand: 485, capacity: 490 },
        { date: '12 AM', demand: 482, capacity: 488 },
        { date: '2 AM', demand: 478, capacity: 485 },
        { date: '4 AM', demand: 475, capacity: 482 },
        { date: '6 AM', demand: 472, capacity: 480 },
        { date: '8 AM', demand: 470, capacity: 478 }
    ];

    const mismatchPoints = data
        .map(point => ({
            time: point.date,
            demand: point.demand,
            capacity: point.capacity,
            difference: point.capacity - point.demand
        }))
        .filter(point => Math.abs(point.difference) > 5);

    return (
        <Card>
            <Card.Header>
                <Card.Title>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                            <span>Demand-Capacity Model</span>
                        </div>
                        <div className="flex items-center space-x-4">
                            <div className="flex items-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                <div className="w-3 h-3 rounded-full bg-healthcare-warning mr-2"></div>
                                Predicted Demand
                            </div>
                            <div className="flex items-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                <div className="w-3 h-3 rounded-full bg-healthcare-primary mr-2"></div>
                                Available Capacity
                            </div>
                        </div>
                    </div>
                </Card.Title>
            </Card.Header>
            <Card.Content>
                <div className="h-64 mb-6">
                    <LineChart data={data} />
                </div>
                {mismatchPoints.length > 0 && (
                    <div className="space-y-4">
                        <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Significant Mismatches
                        </h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            {mismatchPoints.map((point, index) => (
                                <div 
                                    key={index}
                                    className="p-4 rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark"
                                >
                                    <div className="flex items-center justify-between mb-2">
                                        <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {point.time}
                                        </span>
                                        <Icon 
                                            icon={point.difference > 0 ? "heroicons:arrow-trending-up" : "heroicons:arrow-trending-down"}
                                            className={`w-5 h-5 ${
                                                point.difference > 0 
                                                    ? "text-healthcare-success" 
                                                    : "text-healthcare-critical"
                                            }`}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Demand: {point.demand}
                                        </div>
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Capacity: {point.capacity}
                                        </div>
                                        <div className={`text-sm font-medium ${
                                            point.difference > 0 
                                                ? "text-healthcare-success" 
                                                : "text-healthcare-critical"
                                        }`}>
                                            {Math.abs(point.difference)} bed {point.difference > 0 ? "surplus" : "deficit"}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </Card.Content>
        </Card>
    );
};

export default DemandCapacityModel;
