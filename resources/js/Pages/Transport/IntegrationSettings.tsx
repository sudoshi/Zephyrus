import TransportLayout from './TransportLayout';
import { useTransportVendors } from '@/features/transport/hooks';

const standards = [
  ['FHIR ServiceRequest', 'Canonical request/order for transport, transfer, referral, and discharge ride needs.'],
  ['FHIR Task', 'Execution state, assignee, progress, and fulfillment linkage back to the request.'],
  ['HL7 ADT', 'Encounter and location movement events such as admissions, transfers, discharges, and cancellations.'],
  ['Direct / C-CDA', 'Fallback transition packets where bidirectional APIs are not available.'],
  ['Vendor REST + Webhooks', 'Ride quote, tender, book, cancel, status, ETA, and handoff completion callbacks.'],
];

export default function IntegrationSettings() {
  const { data: vendors } = useTransportVendors();

  return (
    <TransportLayout
      title="Transport Integrations"
      subtitle="Connector roadmap for inpatient transport, transfer centers, NEMT, care transitions, and EMS handoff"
      current="/transport/settings/integrations"
    >
      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Vendor Targets</h2>
          <div className="mt-3 space-y-3">
            {(vendors ?? []).map((vendor) => (
              <div key={vendor.key} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <div className="flex items-center justify-between gap-3">
                  <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{vendor.name}</div>
                  <span className="rounded bg-amber-100 px-2 py-0.5 text-[12px]/[16px] font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                    Planned
                  </span>
                </div>
                <div className="mt-2 flex flex-wrap gap-1">
                  {(vendor.capabilities ?? []).map((capability) => (
                    <span key={capability} className="rounded bg-slate-100 px-2 py-0.5 text-[12px]/[16px] text-slate-800 dark:bg-slate-800 dark:text-slate-100">
                      {capability.replaceAll('_', ' ')}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Standards Backbone</h2>
          <div className="mt-3 divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            {standards.map(([name, description]) => (
              <div key={name} className="py-3">
                <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{name}</div>
                <div className="mt-1 text-[13px]/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{description}</div>
              </div>
            ))}
          </div>
        </section>
      </div>
    </TransportLayout>
  );
}
