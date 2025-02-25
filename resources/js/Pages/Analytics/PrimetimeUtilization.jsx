import React from 'react';
import PropTypes from 'prop-types';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import PrimetimeUtilizationDashboard from '@/Components/Analytics/PrimetimeUtilization/PrimetimeUtilizationDashboard';

export default function PrimetimeUtilization({ auth }) {
  return (
    <AnalyticsLayout
      auth={auth}
      title="Primetime Utilization"
    >
      <PrimetimeUtilizationDashboard />
    </AnalyticsLayout>
  );
}

PrimetimeUtilization.propTypes = {
  auth: PropTypes.shape({
    user: PropTypes.shape({
      id: PropTypes.number,
      name: PropTypes.string,
      email: PropTypes.string,
    }).isRequired,
  }).isRequired,
};
