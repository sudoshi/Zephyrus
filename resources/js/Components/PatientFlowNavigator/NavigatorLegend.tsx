import React, { useState } from 'react';
import { Key } from 'lucide-react';
import { LEGEND_SECTIONS } from '@/features/patientFlowNavigator/sceneVocabulary';
import type { SceneShape } from '@/features/patientFlowNavigator/sceneVocabulary';
import type { PatientLayerState } from '@/features/patientFlowNavigator/types';

/**
 * The scene key (finding E-1) — rendered verbatim from sceneVocabulary so the
 * legend and the materials can never disagree. Collapsed to a "Key" pill by
 * default (bottom-left, above the status bar); rows for hidden layers dim and
 * say so. Status colors always appear with their worded meaning.
 */

interface NavigatorLegendProps {
  layers: PatientLayerState;
}

function hex(colorHex: number): string {
  return `#${colorHex.toString(16).padStart(6, '0')}`;
}

function LegendGlyph({ shape, colorHex }: { shape: SceneShape; colorHex: number }) {
  const color = hex(colorHex);
  switch (shape) {
    case 'sphere':
      return <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="5.5" fill={color} /></svg>;
    case 'line':
      return <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 12 L14 4" stroke={color} strokeWidth="2" fill="none" /></svg>;
    case 'disk':
      return <svg viewBox="0 0 16 16" aria-hidden="true"><ellipse cx="8" cy="8" rx="6.5" ry="3.2" fill={color} opacity="0.8" /></svg>;
    case 'pip':
      return <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="2.4" fill={color} /></svg>;
    case 'ghost':
      return <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="5.5" fill={color} opacity="0.4" stroke={color} strokeDasharray="2 2" /></svg>;
    case 'pillar':
      return <svg viewBox="0 0 16 16" aria-hidden="true"><rect x="5.5" y="2" width="5" height="12" rx="1" fill={color} opacity="0.75" /></svg>;
    case 'diamond':
      return <svg viewBox="0 0 16 16" aria-hidden="true"><rect x="4.5" y="4.5" width="7" height="7" fill={color} transform="rotate(45 8 8)" /></svg>;
    case 'ring':
      return <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="5" fill="none" stroke={color} strokeWidth="2.4" /></svg>;
    case 'block':
      return <svg viewBox="0 0 16 16" aria-hidden="true"><rect x="2.5" y="4" width="11" height="8" rx="1.5" fill={color} opacity="0.85" /></svg>;
    default:
      return null;
  }
}

export default function NavigatorLegend({ layers }: NavigatorLegendProps) {
  const [open, setOpen] = useState(false);

  return (
    <div className="patient-flow-legend">
      <button
        type="button"
        className="patient-flow-legend-toggle"
        aria-expanded={open}
        title={open ? 'Collapse the scene key' : 'What do the shapes and colors mean?'}
        onClick={() => setOpen((value) => !value)}
      >
        <Key aria-hidden="true" />
        Key
      </button>

      {open && (
        <div className="patient-flow-legend-panel" role="region" aria-label="Scene key">
          {LEGEND_SECTIONS.map((section) => (
            <section key={section.title}>
              <h3>{section.title}</h3>
              <ul>
                {section.entries.map((entry) => {
                  const hidden = !layers[entry.layer];
                  return (
                    <li key={entry.key} className={hidden ? 'legend-hidden' : ''}>
                      <span className="patient-flow-legend-glyph">
                        <LegendGlyph shape={entry.shape} colorHex={entry.colorHex} />
                      </span>
                      <span className="patient-flow-legend-text">
                        <strong>{entry.label}{hidden ? ' (hidden)' : ''}</strong>
                        <small>{entry.description}</small>
                      </span>
                    </li>
                  );
                })}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}
