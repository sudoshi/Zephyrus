import WorklistPage from './WorklistPage';

export default function Requests() {
  return (
    <WorklistPage
      title="Transport Requests"
      subtitle="Canonical movement requests across inpatient, transfer, discharge, EMS, and post-acute workflows"
      current="/transport/requests"
    />
  );
}
