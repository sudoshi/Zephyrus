import React, { createContext, useContext, useState, useEffect } from 'react';
import PropTypes from 'prop-types';

const DashboardContext = createContext();

export function DashboardProvider({ children, currentUrl = '' }) {
    const [currentSection, setCurrentSection] = useState('OR');

    useEffect(() => {
        if (currentUrl.startsWith('/rtdc')) {
            setCurrentSection('RTDC');
        } else if (currentUrl.startsWith('/ed')) {
            setCurrentSection('ED');
        } else {
            setCurrentSection('OR');
        }
    }, [currentUrl]);

    const navigationItems = {
        rtdc: [
            { name: 'Bed Tracking', href: route('rtdc.bed-tracking') },
            { name: 'Ancillary Services', href: route('rtdc.ancillary-services') },
            { name: 'Discharge Prediction', href: route('rtdc.discharge-prediction') },
            { name: 'Global Huddle', href: route('rtdc.global-huddle') },
            { name: 'Unit Huddle', href: route('rtdc.unit-huddle') },
            { name: 'Services Huddle', href: route('rtdc.services-huddle') }
        ],
        or: [
            { name: 'Analytics', href: route('analytics.service') },
            { name: 'Block Schedule', href: route('operations.block-schedule') },
            { name: 'Case Management', href: route('operations.cases') },
            { name: 'Room Status', href: route('operations.room-status') }
        ],
        ed: [
            { name: 'Wait Time', href: route('ed.analytics.wait-time') },
            { name: 'Patient Flow', href: route('ed.analytics.flow') },
            { name: 'Resource Management', href: route('ed.operations.resources') },
            { name: 'Triage', href: route('ed.operations.triage') },
            { name: 'Treatment', href: route('ed.operations.treatment') }
        ]
    };

    const dashboardItems = [
        { name: 'RTDC', href: route('dashboard.rtdc') },
        { name: 'OR', href: route('dashboard.or') },
        { name: 'ED', href: route('dashboard.ed') }
    ];

    const value = {
        navigationItems,
        dashboardItems,
        currentSection
    };

    return (
        <DashboardContext.Provider value={value}>
            {children}
        </DashboardContext.Provider>
    );
}

DashboardProvider.propTypes = {
    currentUrl: PropTypes.string,
    children: PropTypes.node.isRequired
};

DashboardProvider.defaultProps = {
    currentUrl: ''
};

export function useDashboard() {
    const context = useContext(DashboardContext);
    if (context === undefined) {
        throw new Error('useDashboard must be used within a DashboardProvider');
    }
    return context;
}
