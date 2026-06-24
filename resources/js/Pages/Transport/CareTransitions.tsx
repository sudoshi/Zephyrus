import WorklistPage from './WorklistPage';

export default function CareTransitions() {
  return (
    <WorklistPage
      title="Care Transitions"
      subtitle="Monitor post-acute placement, referral packets, authorizations, ADT alerts, and transport dependencies"
      current="/transport/care-transitions"
      requestType="care_transition"
    />
  );
}
