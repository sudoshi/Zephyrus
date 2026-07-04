import React from 'react';
import type { PatientFlowEvent } from '@/features/patientFlowNavigator/types';

interface NavigatorFeedProps {
  feed: PatientFlowEvent[];
  /** When true the lens hides patient identity — show the event, not the person. */
  redactIdentity: boolean;
}

export default function NavigatorFeed({ feed, redactIdentity }: NavigatorFeedProps) {
  return (
    <aside className="patient-flow-feed" aria-label="Live event feed">
      <strong>Stream</strong>
      <ol>
        {feed.map((event) => (
          <li key={event.event_id}>
            <span>{new Date(event.occurred_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
            <span>
              {redactIdentity ? '' : `${event.patient_display_id} `}
              {event.event_type} {event.to_location}
            </span>
          </li>
        ))}
      </ol>
    </aside>
  );
}
