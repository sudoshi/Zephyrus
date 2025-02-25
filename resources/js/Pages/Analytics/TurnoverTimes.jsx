import React from 'react';
import PropTypes from 'prop-types';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import TurnoverTimesDashboard from '@/Components/Analytics/TurnoverTimes/TurnoverTimesDashboard';

export default function TurnoverTimes({ auth }) {
  return (
    <AnalyticsLayout
      auth={auth}
      title="Turnover Times"
    >
      <TurnoverTimesDashboard />
    </AnalyticsLayout>
  );
}

TurnoverTimes.propTypes = {
  auth: PropTypes.shape({
    user: PropTypes.shape({
      id: PropTypes.number,
      name: PropTypes.string,
      email: PropTypes.string,
    }).isRequired,
  }).isRequired,
};
