import React from 'react';
import PropTypes from 'prop-types';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import ORUtilizationDashboard from '@/Components/Analytics/ORUtilization/ORUtilizationDashboard';

export default function ORUtilization({ auth }) {
  return (
    <AnalyticsLayout
      auth={auth}
      title="OR Utilization"
    >
      <ORUtilizationDashboard />
    </AnalyticsLayout>
  );
}

ORUtilization.propTypes = {
  auth: PropTypes.shape({
    user: PropTypes.shape({
      id: PropTypes.number,
      name: PropTypes.string,
      email: PropTypes.string,
    }).isRequired,
  }).isRequired,
};
