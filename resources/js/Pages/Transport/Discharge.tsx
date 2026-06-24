import WorklistPage from './WorklistPage';

export default function Discharge() {
  return (
    <WorklistPage
      title="Discharge Transport"
      subtitle="Coordinate discharge lounge moves, NEMT, rideshare, wheelchair, stretcher, and family pickup dependencies"
      current="/transport/discharge"
      requestType="discharge"
    />
  );
}
