import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { EmptyState, KpiTile, Section, metric } from '@/Components/system';
import { CapabilityMatrix } from '@/Components/Deployment/CapabilityMatrix';
import { FacilitySpaces } from '@/Components/Deployment/FacilitySpaces';
import { ReadinessScorecard } from '@/Components/Deployment/ReadinessScorecard';
import { ServiceLineRegistry } from '@/Components/Deployment/ServiceLineRegistry';
import { TransferNetwork } from '@/Components/Deployment/TransferNetwork';
import { ReviewPill } from '@/Components/Deployment/status';
import { humanize } from '@/Components/Deployment/format';
import {
  useCapabilityMatrix,
  useFacility,
  useFacilitySpaces,
  useOrganization,
  useOrganizations,
  useReadiness,
  useServiceLineCatalog,
  useTransfers,
} from '@/features/deployment/hooks';
import type { FacilityDetail } from '@/features/deployment/types';
import { Head } from '@inertiajs/react';
import {
  ArrowRightLeft,
  BookOpen,
  Building2,
  ClipboardCheck,
  LayoutGrid,
  MapPin,
  ShieldCheck,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ComponentType } from 'react';

const PREFERRED_FACILITY = 'SUMMIT_REGIONAL';

type TabId = 'readiness' | 'matrix' | 'network' | 'spaces' | 'registry';

const TABS: { id: TabId; label: string; icon: ComponentType<{ className?: string }> }[] = [
  { id: 'readiness', label: 'Readiness', icon: ClipboardCheck },
  { id: 'matrix', label: 'Capability matrix', icon: LayoutGrid },
  { id: 'network', label: 'Transfer network', icon: ArrowRightLeft },
  { id: 'spaces', label: 'Facility spaces', icon: Building2 },
  { id: 'registry', label: 'Registry', icon: BookOpen },
];

// Focus is handled by the global :focus-visible rule (ring-2 ring-healthcare-primary
// ring-offset-2) so every control focuses identically — no bespoke ring here.
const SELECT =
  'rounded-md border border-healthcare-border bg-healthcare-surface px-2.5 py-1.5 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark';

function MetricTile({
  label,
  value,
  display,
  status = 'neutral',
  caption,
}: {
  label: string;
  value: number;
  display?: string;
  status?: 'neutral' | 'success' | 'critical' | 'warning' | 'info';
  caption?: string;
}) {
  return (
    <KpiTile metric={metric({ key: `deployment-${label.toLowerCase().replace(/\s+/g, '-')}`, label, value, display, status, caption })} />
  );
}

function DesignationChips({ facility }: { facility: FacilityDetail['facility'] }) {
  const items: string[] = [];
  if (facility.trauma_level_adult) items.push(`Trauma ${facility.trauma_level_adult}`);
  if (facility.stroke_level) items.push(`Stroke ${humanize(facility.stroke_level)}`);
  if (facility.maternal_level) items.push(`Maternal ${facility.maternal_level}`);
  if (facility.neonatal_level) items.push(`NICU ${facility.neonatal_level}`);
  if (facility.burn_center_status) items.push('Burn center');
  if (facility.transplant_center_status) items.push('Transplant');

  if (items.length === 0) return null;
  return (
    <div className="flex flex-wrap gap-1">
      {items.map((label) => (
        <span
          key={label}
          className="inline-flex items-center rounded-md bg-healthcare-primary/10 px-2 py-0.5 text-xs font-medium text-healthcare-primary dark:text-healthcare-primary-dark"
        >
          {label}
        </span>
      ))}
    </div>
  );
}

function FacilityIdentity({ detail }: { detail: FacilityDetail }) {
  const f = detail.facility;
  return (
    <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {f.facility_name}
          </div>
          <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span className="inline-flex items-center gap-1">
              <ShieldCheck className="size-3.5" aria-hidden="true" /> {humanize(f.idn_role)}
            </span>
            {f.state && (
              <span className="inline-flex items-center gap-1">
                <MapPin className="size-3.5" aria-hidden="true" /> {[f.county, f.region, f.state].filter(Boolean).join(', ')}
              </span>
            )}
            {f.licensed_beds !== null && <span className="tabular-nums">{f.licensed_beds} licensed beds</span>}
            {f.cad_facility_code && <span className="tabular-nums">CAD {f.cad_facility_code}</span>}
          </div>
        </div>
        <DesignationChips facility={f} />
      </div>
    </div>
  );
}

function LoadingPanel({ label }: { label: string }) {
  return (
    <div className="rounded-lg border border-healthcare-border p-6 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
      {label}
    </div>
  );
}

export default function DeploymentConsole() {
  const [org, setOrg] = useState<string | null>(null);
  const [facility, setFacility] = useState<string | null>(null);
  const [tab, setTab] = useState<TabId>('readiness');

  const organizations = useOrganizations();
  const catalog = useServiceLineCatalog();
  const orgDetail = useOrganization(org);
  const facilityDetail = useFacility(facility);
  const readiness = useReadiness(facility);
  const matrix = useCapabilityMatrix(facility);
  const spaces = useFacilitySpaces(facility);
  const transfers = useTransfers(facility ? { facility } : {});

  // Default to the first organization once the list resolves.
  useEffect(() => {
    if (org === null && organizations.data && organizations.data.length > 0) {
      setOrg(organizations.data[0].organization_key);
    }
  }, [org, organizations.data]);

  // Default to the preferred facility (else the first) when an org resolves.
  const orgFacilities = orgDetail.data?.facilities ?? [];
  useEffect(() => {
    if (orgFacilities.length === 0) return;
    const stillValid = facility && orgFacilities.some((f) => f.facility_key === facility);
    if (stillValid) return;
    const preferred = orgFacilities.find((f) => f.facility_key === PREFERRED_FACILITY);
    setFacility((preferred ?? orgFacilities[0]).facility_key);
  }, [orgFacilities, facility]);

  const readinessData = readiness.data;
  const readinessKpi = useMemo(() => {
    if (!readinessData) return { value: 0, display: '—', status: 'neutral' as const, caption: undefined as string | undefined };
    const pass = readinessData.summary.pass ?? 0;
    const fail = readinessData.summary.fail ?? 0;
    return {
      value: pass,
      display: `${pass}/${readinessData.checks.length}`,
      status: readinessData.deployment_ready ? ('success' as const) : ('critical' as const),
      caption: `${fail} blocking · ${readinessData.summary.warn ?? 0} to review`,
    };
  }, [readinessData]);

  const orgPickerContent = (
    <div className="flex flex-wrap items-center gap-2">
      <select className={SELECT} value={org ?? ''} onChange={(e) => { setOrg(e.target.value); setFacility(null); }} aria-label="Organization">
        {(organizations.data ?? []).map((o) => (
          <option key={o.organization_key} value={o.organization_key}>
            {o.name}
          </option>
        ))}
      </select>
      <select className={SELECT} value={facility ?? ''} onChange={(e) => setFacility(e.target.value)} aria-label="Facility" disabled={orgFacilities.length === 0}>
        {orgFacilities.map((f) => (
          <option key={f.facility_key} value={f.facility_key}>
            {f.facility_name}
          </option>
        ))}
      </select>
    </div>
  );

  return (
    <DashboardLayout>
      <Head title="Deployment Console" />
      <PageContentLayout
        title="Deployment Console"
        subtitle="IDN geography, capability matrix, transfer network, and per-facility deployment readiness"
        headerContent={organizations.data && organizations.data.length > 0 ? orgPickerContent : null}
      >
        {organizations.isLoading ? (
          <LoadingPanel label="Loading deployment registry..." />
        ) : !organizations.data || organizations.data.length === 0 ? (
          <EmptyState
            message="No organizations imported yet. Run deployment:import-facilities to populate the IDN registry."
            icon="heroicons:building-office-2"
          />
        ) : (
          <div className="space-y-4">
            {/* KPI wall */}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <MetricTile label="Facilities" value={orgFacilities.length} caption={orgDetail.data?.name ?? undefined} />
              <MetricTile label="Service lines" value={catalog.data?.service_lines.length ?? 0} caption="Enterprise registry" />
              <MetricTile
                label="Readiness"
                value={readinessKpi.value}
                display={readinessKpi.display}
                status={readinessKpi.status}
                caption={readinessKpi.caption}
              />
              <MetricTile label="Capabilities" value={facilityDetail.data?.capabilities.length ?? 0} caption="Service-line coverage" />
            </div>

            {/* Facility identity */}
            {facilityDetail.data && <FacilityIdentity detail={facilityDetail.data} />}

            {/* Tab bar */}
            <div className="inline-flex flex-wrap gap-1 rounded-lg border border-healthcare-border bg-healthcare-surface p-1 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              {TABS.map(({ id, label, icon: Icon }) => {
                const active = tab === id;
                return (
                  <button
                    key={id}
                    type="button"
                    onClick={() => setTab(id)}
                    aria-current={active ? 'page' : undefined}
                    className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors duration-150 ${
                      active
                        ? 'bg-healthcare-primary text-white'
                        : 'text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark'
                    }`}
                  >
                    <Icon className="size-4" />
                    {label}
                  </button>
                );
              })}
            </div>

            {/* Tab content */}
            {tab === 'readiness' && (
              <Section title="Deployment readiness" summary="The 13 acceptance criteria, most-blocking first" icon="heroicons:clipboard-document-check">
                {readiness.isLoading || !readinessData ? <LoadingPanel label="Evaluating readiness..." /> : <ReadinessScorecard report={readinessData} />}
              </Section>
            )}

            {tab === 'matrix' && (
              <Section title="Capability matrix" summary="Service line × capability level, with evidence and trust" icon="heroicons:squares-2x2">
                {matrix.isLoading || !matrix.data ? <LoadingPanel label="Loading capability matrix..." /> : <CapabilityMatrix cells={matrix.data.cells} />}
              </Section>
            )}

            {tab === 'network' && (
              <Section title="Transfer network" summary="Interfacility transfer routes weighted by typical transport minutes" icon="heroicons:arrows-right-left">
                {transfers.isLoading || !transfers.data || !facility ? (
                  <LoadingPanel label="Loading transfer network..." />
                ) : (
                  <TransferNetwork edges={transfers.data} focusKey={facility} />
                )}
              </Section>
            )}

            {tab === 'spaces' && (
              <Section title="Facility spaces" summary="Physical spaces mapped to service lines, capability tags, and prod units/beds" icon="heroicons:building-office">
                {spaces.isLoading || !spaces.data ? <LoadingPanel label="Loading facility spaces..." /> : <FacilitySpaces spaces={spaces.data} />}
              </Section>
            )}

            {tab === 'registry' && (
              <Section title="Service-line registry" summary="The enterprise taxonomy every facility is scored against" icon="heroicons:book-open">
                {catalog.isLoading || !catalog.data ? <LoadingPanel label="Loading registry..." /> : <ServiceLineRegistry catalog={catalog.data} />}
              </Section>
            )}

            <StatusPillLegend />
          </div>
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
}

// A quiet legend so the trust vocabulary is self-documenting on the wall display.
// Renders the real ReviewPills so the legend icons match the table cells exactly.
function StatusPillLegend() {
  return (
    <div className="flex flex-wrap items-center gap-2 pt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      <span className="uppercase tracking-wide">Trust</span>
      <ReviewPill status="client_verified" />
      <ReviewPill status="source_verified" />
      <ReviewPill status="assumed" />
    </div>
  );
}
