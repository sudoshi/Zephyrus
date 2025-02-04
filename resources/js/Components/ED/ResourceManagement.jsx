import React from 'react';
import PropTypes from 'prop-types';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';

const ResourceManagement = ({ resources }) => {
    const getUtilizationColor = (percentage) => {
        if (percentage >= 90) return 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark';
        if (percentage >= 75) return 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark';
        return 'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark';
    };

    const calculateUtilization = (inUse, total) => {
        return Math.round((inUse / total) * 100);
    };

    return (
        <Card className="lg:col-span-2">
            <Card.Header>
                <Card.Title>
                    <div className="flex items-center space-x-2">
                        <Icon icon="heroicons:cube" className="w-5 h-5" />
                        <span>Resource Management</span>
                    </div>
                </Card.Title>
                <Card.Description>Current bed and equipment availability</Card.Description>
            </Card.Header>
            <Card.Content>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Bed Status */}
                    <div>
                        <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                            Bed Status
                        </h4>
                        <div className="space-y-4">
                            <div className="flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                <div className="flex items-center space-x-3">
                                    <Icon icon="heroicons:home" className="w-5 h-5" />
                                    <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Total Beds
                                    </span>
                                </div>
                                <div className="flex items-center space-x-4">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {resources.beds.occupied}/{resources.beds.total}
                                    </span>
                                    <span className={`text-xs px-2 py-1 rounded-full ${
                                        getUtilizationColor((resources.beds.occupied / resources.beds.total) * 100)
                                    }`}>
                                        {Math.round((resources.beds.occupied / resources.beds.total) * 100)}% Occupied
                                    </span>
                                </div>
                            </div>

                            {/* Bed Categories */}
                            <div className="space-y-2">
                                {Object.entries(resources.beds.categories).map(([category, data]) => (
                                    <div key={category} className="flex items-center justify-between p-2 bg-healthcare-background/50 dark:bg-healthcare-background-dark/50 rounded-lg">
                                        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark capitalize">
                                            {category.replace(/([A-Z])/g, ' $1').trim()}
                                        </span>
                                        <div className="flex items-center space-x-3">
                                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {data.available}/{data.total} Available
                                            </span>
                                            <div className={`w-2 h-2 rounded-full ${
                                                data.available === 0 ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark' :
                                                data.available <= 2 ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' :
                                                'bg-healthcare-success dark:bg-healthcare-success-dark'
                                            }`} />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Equipment Status */}
                    <div>
                        <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                            Equipment Status
                        </h4>
                        <div className="space-y-4">
                            {Object.entries(resources.equipment).map(([equipment, data]) => (
                                <div key={equipment} className="p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                    <div className="flex items-center justify-between mb-2">
                                        <div className="flex items-center space-x-3">
                                            <Icon 
                                                icon={
                                                    equipment === 'ventilators' ? 'heroicons:heart' :
                                                    equipment === 'monitors' ? 'heroicons:signal' :
                                                    'heroicons:camera'
                                                }
                                                className="w-5 h-5"
                                            />
                                            <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                                                {equipment.replace(/([A-Z])/g, ' $1').trim()}
                                            </span>
                                        </div>
                                        <span className={`text-xs px-2 py-1 rounded-full ${
                                            getUtilizationColor(calculateUtilization(data.inUse, data.total))
                                        }`}>
                                            {calculateUtilization(data.inUse, data.total)}% In Use
                                        </span>
                                    </div>
                                    <div className="flex justify-between text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        <span>Total: {data.total}</span>
                                        <span>Available: {data.total - data.inUse}</span>
                                        <span>In Use: {data.inUse}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </Card.Content>
        </Card>
    );
};

ResourceManagement.propTypes = {
    resources: PropTypes.shape({
        beds: PropTypes.shape({
            total: PropTypes.number.isRequired,
            occupied: PropTypes.number.isRequired,
            cleaning: PropTypes.number,
            available: PropTypes.number.isRequired,
            categories: PropTypes.objectOf(PropTypes.shape({
                total: PropTypes.number.isRequired,
                available: PropTypes.number.isRequired,
            })).isRequired,
        }).isRequired,
        equipment: PropTypes.objectOf(PropTypes.shape({
            total: PropTypes.number.isRequired,
            inUse: PropTypes.number.isRequired,
        })).isRequired,
    }).isRequired,
};

export default ResourceManagement;
