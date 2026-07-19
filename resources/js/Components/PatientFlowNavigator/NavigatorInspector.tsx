import React from 'react';

interface NavigatorInspectorProps {
  title: string;
  rows: Array<[string, string]>;
  /** Optional deep-link action (e.g. round stop → Rounds board; R-2). */
  action?: { label: string; href: string } | null;
}

export default function NavigatorInspector({ title, rows, action = null }: NavigatorInspectorProps) {
  return (
    <aside className="patient-flow-inspector" aria-live="polite">
      <strong>{title}</strong>
      <dl>
        {rows.map(([key, value]) => (
          <React.Fragment key={key}>
            <dt>{key}</dt>
            <dd>{value}</dd>
          </React.Fragment>
        ))}
      </dl>
      {action && (
        <a className="patient-flow-inspector-action" href={action.href}>
          {action.label} →
        </a>
      )}
    </aside>
  );
}
