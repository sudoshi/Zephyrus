import { Head } from '@inertiajs/react';
import { CheckCircle2, Globe2, KeyRound, LockKeyhole, ShieldCheck, UsersRound } from 'lucide-react';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import {
 AdminMetricStrip,
 AdminSectionHeading,
 type AdminMetric,
} from '@/Pages/Admin/components/AdminPrimitives';

interface RoleProfile {
 role: string;
 label: string;
 capabilities: string[];
 capabilityCount: number;
 globalScope: boolean;
}

interface CapabilityRow {
 capability: string;
 label: string;
 domain: string;
 scopeMode: 'global_only' | 'resource_scoped' | 'facility_or_workforce';
 assignedRoles: string[];
}

interface RolesCapabilitiesProps {
 generatedAt: string;
 sourceOfTruth: string;
 roles: RoleProfile[];
 capabilities: CapabilityRow[];
 aliases: { alias: string; canonical: string }[];
 globalScopeRoles: string[];
 currentPrincipal: {
 userId: number;
 roles: string[];
 capabilities: string[];
 globalScope: boolean;
 };
 counts: {
 roles: number;
 capabilities: number;
 globalScopeRoles: number;
 unclassifiedCapabilities: number;
 };
}

function humanize(value: string): string {
 return value.replaceAll('_', ' ').replace(/([a-z])([A-Z])/g, '$1 $2');
}

function ScopeBadge({ mode }: { mode: CapabilityRow['scopeMode'] }) {
 const labels = {
 global_only: 'Global-only policy',
 resource_scoped: 'Resource scoped',
 facility_or_workforce: 'Facility / workforce scoped',
 };
 return (
 <span className="inline-flex rounded-md border border-healthcare-border bg-healthcare-surface-secondary px-2 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark dark:border-healthcare-border-dark dark:bg-healthcare-surface-hover-dark">
 {labels[mode]}
 </span>
 );
}

export default function RolesCapabilities({
 generatedAt,
 sourceOfTruth,
 roles,
 capabilities,
 aliases,
 globalScopeRoles,
 currentPrincipal,
 counts,
}: RolesCapabilitiesProps) {
 const metrics: AdminMetric[] = [
 { label: 'Canonical roles', value: counts.roles },
 { label: 'Capabilities', value: counts.capabilities },
 { label: 'Global-scope roles', value: counts.globalScopeRoles, tone: counts.globalScopeRoles > 0 ? 'warning' : 'default' },
 { label: 'Role aliases', value: aliases.length },
 { label: 'Your effective roles', value: currentPrincipal.roles.length },
 { label: 'Your capabilities', value: currentPrincipal.capabilities.length },
 { label: 'Unclassified', value: counts.unclassifiedCapabilities, tone: counts.unclassifiedCapabilities > 0 ? 'critical' : 'default' },
 ];

 return (
 <DashboardLayout>
 <Head title="Roles & Capabilities" />
 <PageContentLayout
 title="Roles & Capabilities"
 subtitle="Read-only projection of the canonical authorization policy and your effective access"
 headerContent={null}
 >
 <div className="space-y-5">
 <AdminMetricStrip metrics={metrics} />

 <div className="grid gap-3 lg:grid-cols-2">
 <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 <div className="flex items-start gap-3">
 <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-healthcare-info/10 text-healthcare-info dark:text-healthcare-info-dark">
 <ShieldCheck className="h-5 w-5" aria-hidden="true" />
 </span>
 <div>
 <h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Your effective principal</h2>
 <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">User #{currentPrincipal.userId} · {currentPrincipal.globalScope ? 'global-scope role present' : 'resource scope required'}</p>
 </div>
 </div>
 <dl className="mt-4 space-y-3">
 <div>
 <dt className="text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Effective roles</dt>
 <dd className="mt-1 flex flex-wrap gap-1.5">{currentPrincipal.roles.map((role) => <span key={role} className="rounded-md bg-healthcare-surface-secondary px-2 py-1 text-xs font-medium text-healthcare-text-primary dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-primary-dark">{humanize(role)}</span>)}</dd>
 </div>
 <div>
 <dt id="effective-capabilities-label" className="text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Effective capabilities</dt>
 <dd className="mt-1"><div tabIndex={0} role="group" aria-labelledby="effective-capabilities-label" className="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-healthcare-primary">{currentPrincipal.capabilities.map((capability) => <code key={capability} className="rounded bg-healthcare-surface-secondary px-1.5 py-1 text-xs text-healthcare-text-primary dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-primary-dark">{capability}</code>)}</div></dd>
 </div>
 </dl>
 </section>

 <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 <div className="flex items-start gap-3">
 <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark">
 <LockKeyhole className="h-5 w-5" aria-hidden="true" />
 </span>
 <div>
 <h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Policy boundary</h2>
 <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">This page is a projection, not a grant store. No roles, permissions, scopes, or identities can be changed here.</p>
 </div>
 </div>
 <dl className="mt-4 space-y-3 text-sm">
 <div><dt className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Source of truth</dt><dd className="mt-1 font-mono text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{sourceOfTruth}</dd></div>
 <div><dt className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Global-scope roles</dt><dd className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{globalScopeRoles.map(humanize).join(', ') || 'None'}</dd></div>
 <div><dt className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Generated</dt><dd className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{new Date(generatedAt).toLocaleString()}</dd></div>
 </dl>
 </section>
 </div>

 <section>
 <AdminSectionHeading title="Canonical role profiles" description="A role grants only the capabilities listed in the server policy; unknown roles grant nothing" />
 <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
 <thead className="bg-healthcare-surface-secondary dark:bg-healthcare-surface-hover-dark">
 <tr>
 {['Role', 'Scope posture', 'Capabilities'].map((label) => <th key={label} scope="col" className="px-3 py-2 text-left text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</th>)}
 </tr>
 </thead>
 <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
 {roles.map((role) => (
 <tr key={role.role}>
 <td className="whitespace-nowrap px-3 py-2 align-top">
 <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{role.label}</p>
 <code className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{role.role}</code>
 </td>
 <td className="whitespace-nowrap px-3 py-2 align-top">
 <span className={`inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ${role.globalScope ? 'bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark' : 'bg-healthcare-surface-secondary text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark dark:bg-healthcare-surface-hover-dark'}`}>
 {role.globalScope ? <Globe2 className="h-3.5 w-3.5" aria-hidden="true" /> : <KeyRound className="h-3.5 w-3.5" aria-hidden="true" />}
 {role.globalScope ? 'Global scope' : 'Scope evaluated'}
 </span>
 </td>
 <td className="min-w-[36rem] px-3 py-2 align-top">
 <div className="flex flex-wrap gap-1.5">
 {role.capabilities.map((capability) => <code key={capability} className="rounded bg-healthcare-surface-secondary px-1.5 py-0.5 text-xs text-healthcare-text-primary dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-primary-dark">{capability}</code>)}
 </div>
 <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{role.capabilityCount} {role.capabilityCount === 1 ? 'capability' : 'capabilities'}</p>
 </td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>
 </section>

 <section>
 <AdminSectionHeading title="Capability catalog" description="Stable application contracts, their policy domains, scope posture, and configured role assignments" />
 <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
 <thead className="bg-healthcare-surface-secondary dark:bg-healthcare-surface-hover-dark">
 <tr>{['Capability', 'Domain', 'Scope mode', 'Assigned roles'].map((label) => <th key={label} scope="col" className="px-3 py-2 text-left text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</th>)}</tr>
 </thead>
 <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
 {capabilities.map((capability) => (
 <tr key={capability.capability}>
 <td className="whitespace-nowrap px-3 py-2"><p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{capability.label}</p><code className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{capability.capability}</code></td>
 <td className="whitespace-nowrap px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{capability.domain}</td>
 <td className="whitespace-nowrap px-3 py-2"><ScopeBadge mode={capability.scopeMode} /></td>
 <td className="min-w-72 px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{capability.assignedRoles.map(humanize).join(', ') || 'No role profile; direct capability only'}</td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>
 </section>

 <section>
 <AdminSectionHeading title="Normalization rules" description="Compatibility aliases converge before capability evaluation" />
 <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
 {aliases.map(({ alias, canonical }) => (
 <div key={alias} className="flex items-center gap-3 rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
 <UsersRound className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
 <code className="text-xs text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{alias}</code>
 <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">→</span>
 <code className="text-xs text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{canonical}</code>
 </div>
 ))}
 <div className="flex items-center gap-3 rounded-md border border-healthcare-success/40 bg-healthcare-success/5 p-3">
 <CheckCircle2 className="h-4 w-4 text-healthcare-success" aria-hidden="true" />
 <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Inactive users and unknown roles always fail closed.</span>
 </div>
 </div>
 </section>
 </div>
 </PageContentLayout>
 </DashboardLayout>
 );
}
