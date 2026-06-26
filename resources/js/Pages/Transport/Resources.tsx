import TransportLayout from './TransportLayout';
import { useTransportResources, useTransportVendors } from '@/features/transport/hooks';

export default function Resources() {
  const { data: resources } = useTransportResources();
  const { data: vendors } = useTransportVendors();

  return (
    <TransportLayout
      title="Transport Resources"
      subtitle="Internal team, equipment, handoff area, and vendor capacity registry"
      current="/transport/resources"
    >
      <div className="grid gap-4 lg:grid-cols-2">
        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Internal Resources</h2>
          <div className="mt-3 divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            {(resources ?? []).map((resource) => (
              <div key={resource.key} className="flex items-center justify-between py-3 text-sm/[18px]">
                <div>
                  <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{resource.name}</div>
                  <div className="capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{resource.type?.replaceAll('_', ' ')}</div>
                </div>
                <div className="rounded bg-healthcare-hover px-2 py-1 font-semibold dark:bg-healthcare-hover-dark">{resource.available ?? '-'}</div>
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Vendor Connectors</h2>
          <div className="mt-3 space-y-3">
            {(vendors ?? []).map((vendor) => (
              <div key={vendor.key} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{vendor.name}</div>
                <div className="mt-2 flex flex-wrap gap-1">
                  {(vendor.capabilities ?? []).map((capability) => (
                    <span key={capability} className="rounded bg-slate-100 px-2 py-0.5 text-xs/[16px] text-slate-800 dark:bg-slate-800 dark:text-slate-100">
                      {capability.replaceAll('_', ' ')}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </section>
      </div>
    </TransportLayout>
  );
}
