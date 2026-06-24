import WorklistPage from './WorklistPage';

export default function Ems() {
  return (
    <WorklistPage
      title="EMS Handoff"
      subtitle="Monitor inbound ETA, receiving readiness, activation needs, and prehospital handoff completion"
      current="/transport/ems"
      requestType="ems"
    />
  );
}
