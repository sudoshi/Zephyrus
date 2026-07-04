import React from 'react';

interface NavigatorInspectorProps {
  title: string;
  rows: Array<[string, string]>;
}

export default function NavigatorInspector({ title, rows }: NavigatorInspectorProps) {
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
    </aside>
  );
}
