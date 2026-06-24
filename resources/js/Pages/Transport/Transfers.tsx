import WorklistPage from './WorklistPage';

export default function Transfers() {
  return (
    <WorklistPage
      title="Interfacility Transfers"
      subtitle="Track transfer acceptance, bed dependency, transport mode, vendor assignment, and receiving handoff"
      current="/transport/transfers"
      requestType="transfer"
    />
  );
}
