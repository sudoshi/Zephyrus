import React from 'react';
import type { PatientFlowEvent } from '@/features/patientFlowNavigator/types';

interface NavigatorFeedProps {
  feed: PatientFlowEvent[];
  /** When true the lens hides patient identity — show the event, not the person. */
  redactIdentity: boolean;
  /**
   * H1.2: rows select the patient (non-pointer selection path). Omitted for
   * aggregate lenses where no tokens exist to select.
   */
  onSelectPatient?: (patientId: string) => void;
}

export default function NavigatorFeed({ feed, redactIdentity, onSelectPatient }: NavigatorFeedProps) {
  return (
    <aside className="patient-flow-feed" aria-label="Recent replay events">
      <strong>Recent events</strong>
      <ol>
        {feed.map((event) => {
          const body = (
            <>
              <time dateTime={event.occurred_at}>
                {new Date(event.occurred_at).toLocaleString([], {
                  month: 'short',
                  day: '2-digit',
                  hour: '2-digit',
                  minute: '2-digit',
                  second: '2-digit',
                })}
              </time>
              <span>
                {redactIdentity ? '' : `${event.patient_display_id} `}
                {event.event_type.replaceAll('_', ' ')}
                {event.from_location ? ` from ${event.from_location}` : ''}
                {event.to_location ? ` to ${event.to_location}` : ''}
                {!event.from_location && !event.to_location && event.service_line
                  ? ` in ${event.service_line.replaceAll('_', ' ')}`
                  : ''}
              </span>
            </>
          );
          return (
            <li key={event.event_id}>
              {onSelectPatient ? (
                <button
                  type="button"
                  className="patient-flow-feed-row"
                  title="Select this patient in the scene"
                  onClick={() => onSelectPatient(event.patient_id)}
                >
                  {body}
                </button>
              ) : (
                body
              )}
            </li>
          );
        })}
      </ol>
    </aside>
  );
}
