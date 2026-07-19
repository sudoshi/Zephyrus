import React, { useEffect, useRef, useState } from 'react';
import type { IntroStop } from '@/features/patientFlowNavigator/introTour';

interface AnchorRect {
  top: number;
  left: number;
  width: number;
  height: number;
}

interface NavigatorIntroProps {
  stops: IntroStop[];
  index: number;
  onIndexChange: (index: number) => void;
  onDismiss: () => void;
}

/**
 * Coach-mark card for the first-run intro (H5.1). Each stop outlines its
 * anchored control with a gold-tint ring (the filter-chip "notice" treatment,
 * deliberately outside the status-color space) and explains it in the card.
 * A missing anchor (layout variant, jsdom) degrades to the card alone.
 */
export default function NavigatorIntro({ stops, index, onIndexChange, onDismiss }: NavigatorIntroProps) {
  // The stop list can shrink if the rounds run unloads mid-intro.
  const clamped = Math.min(index, stops.length - 1);
  const stop = stops[clamped] ?? null;
  const [anchorRect, setAnchorRect] = useState<AnchorRect | null>(null);
  const cardRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    cardRef.current?.focus();
  }, []);

  useEffect(() => {
    if (!stop) return undefined;
    const measure = (): void => {
      const rect = document.querySelector(stop.anchor)?.getBoundingClientRect();
      setAnchorRect(
        rect && rect.width > 0
          ? { top: rect.top, left: rect.left, width: rect.width, height: rect.height }
          : null,
      );
    };
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, [stop]);

  if (!stop) return null;
  const last = clamped === stops.length - 1;

  return (
    <>
      {anchorRect && (
        <div
          className="patient-flow-intro-ring"
          aria-hidden="true"
          style={{
            top: anchorRect.top - 4,
            left: anchorRect.left - 4,
            width: anchorRect.width + 8,
            height: anchorRect.height + 8,
          }}
        />
      )}
      <div
        className="patient-flow-intro"
        role="dialog"
        aria-label="Navigator introduction"
        tabIndex={-1}
        ref={cardRef}
        onKeyDown={(event) => {
          if (event.key === 'Escape') onDismiss();
        }}
      >
        <strong>{stop.title}</strong>
        <p>{stop.body}</p>
        <div className="patient-flow-intro-footer">
          <span className="patient-flow-intro-count">{clamped + 1} of {stops.length}</span>
          <div className="patient-flow-intro-buttons">
            <button type="button" onClick={onDismiss}>Skip</button>
            {clamped > 0 && (
              <button type="button" onClick={() => onIndexChange(clamped - 1)}>Back</button>
            )}
            {last ? (
              <button type="button" className="patient-flow-intro-primary" onClick={onDismiss}>Done</button>
            ) : (
              <button type="button" className="patient-flow-intro-primary" onClick={() => onIndexChange(clamped + 1)}>Next</button>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
