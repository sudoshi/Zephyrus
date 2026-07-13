import type { AdminScopeContract, PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Building2, Check, RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const selectClass = 'min-h-9 min-w-36 rounded-md border border-healthcare-border bg-healthcare-surface px-2 text-xs font-medium text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark';
const buttonClass = 'inline-flex min-h-9 items-center gap-1.5 rounded-md border border-healthcare-border px-2.5 text-xs font-semibold text-healthcare-text-primary transition hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark';

function returnPath(): string {
  if (typeof window === 'undefined') return '/admin';
  return `${window.location.pathname}${window.location.search}`;
}

export default function AdminScopeSelector() {
  const contract = usePage<PageProps>().props.adminScope as AdminScopeContract | null | undefined;
  const [organizationId, setOrganizationId] = useState('');
  const [facilityId, setFacilityId] = useState('');
  const [sourceId, setSourceId] = useState('');
  const [processing, setProcessing] = useState(false);

  useEffect(() => {
    setOrganizationId(contract?.current?.organization.id.toString() ?? '');
    setFacilityId(contract?.current?.facility?.id.toString() ?? '');
    setSourceId(contract?.current?.source?.id.toString() ?? '');
  }, [contract?.current?.revision]);

  const facilities = useMemo(
    () => contract?.facilities.filter((facility) => facility.organizationId === Number(organizationId)) ?? [],
    [contract?.facilities, organizationId],
  );
  const sources = useMemo(
    () => contract?.sources.filter((source) => source.facilityId === Number(facilityId)) ?? [],
    [contract?.sources, facilityId],
  );

  if (!contract) return null;

  const apply = () => {
    if (!organizationId) return;
    setProcessing(true);
    router.put(contract.updateUrl, {
      organization_id: Number(organizationId),
      facility_id: facilityId ? Number(facilityId) : null,
      source_id: sourceId ? Number(sourceId) : null,
      return_path: returnPath(),
    }, {
      preserveScroll: true,
      onFinish: () => setProcessing(false),
    });
  };

  const clear = () => {
    setProcessing(true);
    router.delete(contract.clearUrl, {
      data: { return_path: returnPath() },
      preserveScroll: true,
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <div className="flex max-w-full flex-wrap items-center justify-end gap-2" data-testid="admin-scope-selector">
      <span className="inline-flex items-center gap-1 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <Building2 className="size-4" aria-hidden="true" /> Active scope
      </span>
      <label className="sr-only" htmlFor="admin-scope-organization">Organization</label>
      <select
        id="admin-scope-organization"
        aria-label="Active organization"
        value={organizationId}
        onChange={(event) => { setOrganizationId(event.target.value); setFacilityId(''); setSourceId(''); }}
        className={selectClass}
        disabled={processing || contract.organizations.length === 0}
      >
        <option value="">Select organization</option>
        {contract.organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
      </select>
      <label className="sr-only" htmlFor="admin-scope-facility">Facility</label>
      <select
        id="admin-scope-facility"
        aria-label="Active facility"
        value={facilityId}
        onChange={(event) => { setFacilityId(event.target.value); setSourceId(''); }}
        className={selectClass}
        disabled={processing || !organizationId}
      >
        <option value="">Organization only</option>
        {facilities.map((facility) => <option key={facility.id} value={facility.id}>{facility.name}</option>)}
      </select>
      <label className="sr-only" htmlFor="admin-scope-source">Integration source</label>
      <select
        id="admin-scope-source"
        aria-label="Active integration source"
        value={sourceId}
        onChange={(event) => setSourceId(event.target.value)}
        className={selectClass}
        disabled={processing || !facilityId}
      >
        <option value="">Facility only</option>
        {sources.map((source) => <option key={source.id} value={source.id}>{source.name}</option>)}
      </select>
      <button type="button" className={buttonClass} disabled={processing || !organizationId} onClick={apply}>
        <Check className="size-3.5" aria-hidden="true" /> Apply
      </button>
      {contract.current ? (
        <button type="button" className={buttonClass} disabled={processing} onClick={clear}>
          <RotateCcw className="size-3.5" aria-hidden="true" /> Clear
        </button>
      ) : null}
      {contract.organizations.length === 0 ? (
        <span className="w-full text-right text-xs text-healthcare-warning dark:text-healthcare-warning-dark">No effective enterprise scope grants are available.</span>
      ) : null}
    </div>
  );
}
