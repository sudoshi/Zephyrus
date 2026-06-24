import WorklistPage from './WorklistPage';

export default function Inpatient() {
  return (
    <WorklistPage
      title="Inpatient Transport"
      subtitle="Coordinate unit-to-unit, diagnostic, procedure, discharge lounge, and equipment movement"
      current="/transport/inpatient"
      requestType="inpatient"
    />
  );
}
