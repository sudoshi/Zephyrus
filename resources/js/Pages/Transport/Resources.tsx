import TransportLayout from './TransportLayout';
import { OperationalDataError, SourceFreshnessBanner } from '@/Components/Operations/OperationalDataState';
import { useTransportOverview, useTransportResources, useTransportVendors } from '@/features/transport/hooks';

export default function Resources() {
  const resources = useTransportResources();
  const vendors = useTransportVendors();
  const overview = useTransportOverview();

  return (
    <TransportLayout
      title="Transport Resources"
      subtitle="Internal team, equipment, handoff area, and vendor capacity registry"
      current="/transport/resources"
    >
      {resources.isError || vendors.isError ? (
        <OperationalDataError
          title="Transport resources unavailable"
          error={resources.error ?? vendors.error}
          onRetry={() => { void resources.refetch(); void vendors.refetch(); }}
        />
      ) : resources.isLoading || vendors.isLoading ? (
        <div className="rounded-md border border-healthcare-border p-4 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">Loading transport resources...</div>
      ) : (
        <>
      {overview.data ? <SourceFreshnessBanner source={overview.data.source} onRetry={() => void overview.refetch()} /> : null}
      <div className="grid gap-4 lg:grid-cols-2">
        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Internal Resources</h2>
          <div className="mt-3 divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            {(resources.data ?? []).map((resource) => (
              <div key={resource.key} className="flex items-center justify-between py-3 text-sm/[18px]">
                <div>
                  <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{resource.name}</div>
                  <div className="capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{resource.type?.replaceAll('_', ' ')}</div>
                </div>
                <div className="text-right">
                  <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{resource.available ?? 'Unknown'} available</div>
                  {resource.capacity !== undefined ? <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{resource.busy ?? 0} busy of {resource.capacity}</div> : null}
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Contracted Vendors</h2>
          <div className="mt-3 space-y-3">
            {(vendors.data ?? []).map((vendor) => (
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
        </>
      )}
    </TransportLayout>
  );
}
