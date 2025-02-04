import React from 'react';
import PropTypes from 'prop-types';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';

const AlertsAndPredictions = ({ alerts, predictions }) => {
    const getAlertIcon = (type) => {
        switch (type) {
            case 'critical':
                return 'heroicons:exclamation-triangle';
            case 'warning':
                return 'heroicons:exclamation-circle';
            default:
                return 'heroicons:information-circle';
        }
    };

    const getAlertColor = (type) => {
        switch (type) {
            case 'critical':
                return 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark';
            case 'warning':
                return 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark';
            default:
                return 'bg-healthcare-info/20 text-healthcare-info dark:text-healthcare-info-dark';
        }
    };

    const getImpactColor = (impact) => {
        switch (impact) {
            case 'high':
                return 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark';
            case 'medium':
                return 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark';
            default:
                return 'bg-healthcare-info/20 text-healthcare-info dark:text-healthcare-info-dark';
        }
    };

    const formatTime = (timestamp) => {
        const date = new Date(timestamp);
        return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    };

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Active Alerts */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:bell-alert" className="w-5 h-5" />
                            <span>Active Alerts</span>
                        </div>
                    </Card.Title>
                    <Card.Description>Critical notifications requiring attention</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="space-y-4">
                        {alerts.map((alert) => (
                            <div key={alert.id} className="flex items-start space-x-4 p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                <div className={`flex-shrink-0 p-2 rounded-lg ${getAlertColor(alert.type)}`}>
                                    <Icon icon={getAlertIcon(alert.type)} className="w-5 h-5" />
                                </div>
                                <div className="flex-grow">
                                    <div className="flex items-center justify-between">
                                        <h4 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {alert.title}
                                        </h4>
                                        <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                            {formatTime(alert.timestamp)}
                                        </span>
                                    </div>
                                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                                        {alert.message}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </Card.Content>
            </Card>

            {/* Predictions */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:chart-bar-square" className="w-5 h-5" />
                            <span>Predictions</span>
                        </div>
                    </Card.Title>
                    <Card.Description>Forecasted events and potential issues</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="space-y-6">
                        {/* Arrival Predictions */}
                        <div>
                            <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-3">
                                Expected Arrivals
                            </h4>
                            <div className="grid grid-cols-4 gap-2">
                                {predictions.arrivals.map((arrival) => (
                                    <div key={arrival.hour} className="text-center p-2 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                        <div className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                            {arrival.hour}
                                        </div>
                                        <div className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {arrival.predicted}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Admission Predictions */}
                        <div>
                            <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-3">
                                Predicted Admissions
                            </h4>
                            <div className="p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Total Expected
                                    </span>
                                    <span className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {predictions.admissions.predictedCount}
                                    </span>
                                </div>
                                <div className="space-y-2">
                                    {Object.entries(predictions.admissions.byService).map(([service, count]) => (
                                        <div key={service} className="flex items-center justify-between">
                                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark capitalize">
                                                {service}
                                            </span>
                                            <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {count}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* Bottleneck Predictions */}
                        <div>
                            <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-3">
                                Potential Bottlenecks
                            </h4>
                            <div className="space-y-3">
                                {predictions.bottlenecks.map((bottleneck, index) => (
                                    <div key={index} className="p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                        <div className="flex items-center justify-between mb-2">
                                            <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {bottleneck.resource}
                                            </span>
                                            <span className={`text-xs px-2 py-1 rounded-full ${getImpactColor(bottleneck.impact)}`}>
                                                {Math.round(bottleneck.probability * 100)}% probability
                                            </span>
                                        </div>
                                        <div className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                            {bottleneck.timeframe}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </Card.Content>
            </Card>
        </div>
    );
};

AlertsAndPredictions.propTypes = {
    alerts: PropTypes.arrayOf(PropTypes.shape({
        id: PropTypes.number.isRequired,
        type: PropTypes.string.isRequired,
        title: PropTypes.string.isRequired,
        message: PropTypes.string.isRequired,
        timestamp: PropTypes.string.isRequired,
    })).isRequired,
    predictions: PropTypes.shape({
        arrivals: PropTypes.arrayOf(PropTypes.shape({
            hour: PropTypes.string.isRequired,
            predicted: PropTypes.number.isRequired,
            actual: PropTypes.number,
        })).isRequired,
        admissions: PropTypes.shape({
            probability: PropTypes.number.isRequired,
            predictedCount: PropTypes.number.isRequired,
            byService: PropTypes.objectOf(PropTypes.number).isRequired,
        }).isRequired,
        bottlenecks: PropTypes.arrayOf(PropTypes.shape({
            resource: PropTypes.string.isRequired,
            probability: PropTypes.number.isRequired,
            timeframe: PropTypes.string.isRequired,
            impact: PropTypes.string.isRequired,
        })).isRequired,
    }).isRequired,
};

export default AlertsAndPredictions;
