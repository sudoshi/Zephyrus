import { Radio } from 'lucide-react';
import WorklistPage from './WorklistPage';

export default function Ems() {
  return (
    <WorklistPage
      title="EMS Handoff"
      subtitle="Monitor inbound ETA, receiving readiness, activation needs, and prehospital handoff completion"
      current="/transport/ems"
      requestType="ems"
    >
      <div className="flex items-start gap-2 rounded-md border border-healthcare-info/30 bg-healthcare-info/10 p-3 text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-info/40 dark:bg-healthcare-info/20 dark:text-healthcare-text-secondary-dark">
        <Radio className="mt-0.5 h-4 w-4 shrink-0 text-healthcare-info dark:text-healthcare-info-dark" />
        <span>
          <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Inbound readiness:</span>{' '}
          confirm a receiving bay and clinical team before the unit arrives. STAT prehospital alerts should be acknowledged immediately.
        </span>
      </div>
    </WorklistPage>
  );
}
