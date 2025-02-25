import React from 'react';
import PropTypes from 'prop-types';
import AnalyticsLayout from '@/Layouts/AnalyticsLayout';
import RoomRunningDashboard from '@/Components/Analytics/RoomRunning/RoomRunningDashboard';

export default function RoomRunning({ auth }) {
  return (
    <AnalyticsLayout
      auth={auth}
      title="Room Running"
    >
      <RoomRunningDashboard />
    </AnalyticsLayout>
  );
}

RoomRunning.propTypes = {
  auth: PropTypes.shape({
    user: PropTypes.shape({
      id: PropTypes.number,
      name: PropTypes.string,
      email: PropTypes.string,
    }).isRequired,
  }).isRequired,
};
