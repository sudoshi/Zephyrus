import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { RadiologyReads } from '@/features/radiology/schemas';

type Point = RadiologyReads['backlog']['points'][number];

function rows(points: Point[]) {
  return points.map((point) => ({
    ...point,
    label: new Date(point.bucketEnd).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
  }));
}

export default function ReadBacklogChart({ points }: { points: Point[] }) {
  const data = rows(points);
  if (data.length === 0) return <p className="p-6 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No complete backlog buckets are available.</p>;

  return (
    <figure role="img" aria-label="Radiology unread backlog, entered exams, and finalized reports by complete hourly bucket">
      <div className="h-72">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={data} margin={{ top: 20, right: 24, bottom: 12, left: 0 }}>
            <CartesianGrid stroke="var(--color-gray-300)" strokeDasharray="3 3" />
            <XAxis dataKey="label" tick={{ fill: 'var(--color-gray-500)', fontSize: 12 }} />
            <YAxis allowDecimals={false} tick={{ fill: 'var(--color-gray-500)', fontSize: 12 }} />
            <Tooltip />
            <Legend />
            <Line type="monotone" dataKey="openAtEnd" name="Unread at end" stroke="var(--healthcare-critical)" strokeWidth={2} dot={false} />
            <Line type="monotone" dataKey="entered" name="Acquisitions completed" stroke="var(--healthcare-info)" strokeWidth={2} dot={false} />
            <Line type="monotone" dataKey="finalized" name="Reports finalized" stroke="var(--healthcare-success)" strokeWidth={2} dot={false} />
          </LineChart>
        </ResponsiveContainer>
      </div>
      <figcaption className="px-4 pb-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Only complete, comparable 60-minute buckets are shown.</figcaption>
      <div className="sr-only">
        <table>
          <caption>Accessible Radiology read backlog summary</caption>
          <thead><tr><th>Bucket end</th><th>Unread at end</th><th>Acquisitions completed</th><th>Reports finalized</th><th>Net change</th></tr></thead>
          <tbody>{data.map((point) => <tr key={point.bucketEnd}><th>{point.bucketEnd}</th><td>{point.openAtEnd}</td><td>{point.entered}</td><td>{point.finalized}</td><td>{point.netChange}</td></tr>)}</tbody>
        </table>
      </div>
    </figure>
  );
}
