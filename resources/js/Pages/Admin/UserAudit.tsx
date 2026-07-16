import { Fragment, useEffect, useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  ArrowLeft,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ChevronUp,
  Filter,
  RotateCcw,
  ScrollText,
  Search,
} from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import {
  AdminMetricStrip,
  AdminSectionHeading,
  OutcomeBadge,
  type AdminMetric,
} from '@/Pages/Admin/components/AdminPrimitives';
import {
  actorDisplayName,
  formatAuditTime,
  type AuditEvent,
} from '@/Pages/Admin/auditTypes';

type FilterOption = string | { value: string; label: string };

interface AuditFilters {
  search: string;
  action: string;
  category: string;
  outcome: string;
  auth_method: string;
  date_from: string;
  date_to: string;
  per_page: number;
}

interface AuditOptions {
  actions: FilterOption[];
  categories: FilterOption[];
  outcomes: FilterOption[];
  authMethods: FilterOption[];
}

interface AuditStats {
  totalEvents: number;
  loginsToday: number;
  failedLoginsToday: number;
  activeUsers7d: number;
}

interface AuditPaginator {
  data: AuditEvent[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
  prev_page_url: string | null;
  next_page_url: string | null;
}

interface UserAuditProps {
  events: AuditPaginator;
  filters: AuditFilters;
  options: AuditOptions;
  stats: AuditStats;
  redaction?: { piiVisible: boolean; ipRetentionDays: number };
}

const inputClass =
  'h-9 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-2.5 text-sm text-healthcare-text-primary outline-none focus:border-healthcare-info focus:ring-1 focus:ring-healthcare-info dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:focus:border-healthcare-info-dark dark:focus:ring-healthcare-info-dark';

const sensitiveKey = /password|passcode|token|secret|authorization|cookie|credential|private.?key/i;

function sanitizeAuditValue(value: unknown, depth = 0): unknown {
  if (depth > 4) return '[truncated]';
  if (Array.isArray(value)) {
    const visible = value.slice(0, 25).map((item) => sanitizeAuditValue(item, depth + 1));
    return value.length > 25 ? [...visible, `[${value.length - 25} more items]`] : visible;
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>).map(([key, item]) => [
        key,
        sensitiveKey.test(key) ? '[redacted]' : sanitizeAuditValue(item, depth + 1),
      ]),
    );
  }
  if (typeof value === 'string' && value.length > 1000) return `${value.slice(0, 1000)}...`;
  return value;
}

function safeJson(value: unknown): string {
  if (value === null || value === undefined) return 'No recorded details';
  try {
    return JSON.stringify(sanitizeAuditValue(value), null, 2) ?? 'No recorded details';
  } catch {
    return 'Details could not be displayed safely';
  }
}

function humanize(value: string | null | undefined): string {
  return value ? value.replace(/[._-]+/g, ' ') : 'Unknown';
}

function optionValue(option: FilterOption): string {
  return typeof option === 'string' ? option : option.value;
}

function optionLabel(option: FilterOption): string {
  return typeof option === 'string' ? humanize(option) : option.label;
}

function filterQuery(filters: AuditFilters, page?: number): Record<string, string | number> {
  const query: Record<string, string | number> = { per_page: filters.per_page };
  for (const key of ['search', 'action', 'category', 'outcome', 'auth_method', 'date_from', 'date_to'] as const) {
    const value = filters[key].trim();
    if (value) query[key] = value;
  }
  if (page && page > 1) query.page = page;
  return query;
}

export default function UserAudit({ events, filters, options, stats, redaction }: UserAuditProps) {
  const [draft, setDraft] = useState<AuditFilters>(filters);
  const [expandedEvent, setExpandedEvent] = useState<string | null>(null);

  useEffect(() => setDraft(filters), [filters]);

  const visit = (nextFilters: AuditFilters, page?: number) => {
    router.get('/admin/user-audit', filterQuery(nextFilters, page), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const submitFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setExpandedEvent(null);
    visit(draft);
  };

  const resetFilters = () => {
    const reset: AuditFilters = {
      search: '',
      action: '',
      category: '',
      outcome: '',
      auth_method: '',
      date_from: '',
      date_to: '',
      per_page: filters.per_page || 25,
    };
    setDraft(reset);
    setExpandedEvent(null);
    visit(reset);
  };

  const metrics: AdminMetric[] = [
    { label: 'Total events', value: stats.totalEvents },
    { label: 'Logins today', value: stats.loginsToday },
    {
      label: 'Failed logins today',
      value: stats.failedLoginsToday,
      tone: stats.failedLoginsToday > 0 ? 'critical' : 'default',
    },
    { label: 'Active users, 7 days', value: stats.activeUsers7d },
  ];

  return (
    <DashboardLayout>
      <Head title="User Audit · Zephyrus Administration" />
      <PageContentLayout
        title="User Audit"
        subtitle="Authentication, page access, and state-changing activity"
        headerContent={
          <Link
            href="/admin"
            className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm font-medium text-healthcare-info hover:bg-healthcare-hover dark:text-healthcare-info-dark dark:hover:bg-healthcare-hover-dark"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            Administration
          </Link>
        }
      >
        <div className="space-y-4">
          <AdminMetricStrip metrics={metrics} />

          {redaction && !redaction.piiVisible && (
            <p className="rounded-md border border-healthcare-border bg-healthcare-surface-secondary p-3 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-secondary-dark">
              Audit-only view: actor emails are partially masked and client IP addresses are withheld. Identity administration rights are required for unredacted records.
            </p>
          )}

          <section aria-label="Audit filters">
            <form
              onSubmit={submitFilters}
              className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
            >
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                <label className="xl:col-span-2">
                  <span className="mb-1 block text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Search
                  </span>
                  <span className="relative block">
                    <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
                    <input
                      type="search"
                      value={draft.search}
                      onChange={(event) => setDraft({ ...draft, search: event.target.value })}
                      placeholder="Actor, action, route, or IP"
                      className={`${inputClass} pl-8`}
                    />
                  </span>
                </label>
                <label>
                  <span className="mb-1 block text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Action</span>
                  <select value={draft.action} onChange={(event) => setDraft({ ...draft, action: event.target.value })} className={inputClass}>
                    <option value="">All actions</option>
                    {options.actions.map((option) => <option key={optionValue(option)} value={optionValue(option)}>{optionLabel(option)}</option>)}
                  </select>
                </label>
                <label>
                  <span className="mb-1 block text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Category</span>
                  <select value={draft.category} onChange={(event) => setDraft({ ...draft, category: event.target.value })} className={inputClass}>
                    <option value="">All categories</option>
                    {options.categories.map((option) => <option key={optionValue(option)} value={optionValue(option)}>{optionLabel(option)}</option>)}
                  </select>
                </label>
                <label>
                  <span className="mb-1 block text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Outcome</span>
                  <select value={draft.outcome} onChange={(event) => setDraft({ ...draft, outcome: event.target.value })} className={inputClass}>
                    <option value="">All outcomes</option>
                    {options.outcomes.map((option) => <option key={optionValue(option)} value={optionValue(option)}>{optionLabel(option)}</option>)}
                  </select>
                </label>
                <label>
                  <span className="mb-1 block text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Auth method</span>
                  <select value={draft.auth_method} onChange={(event) => setDraft({ ...draft, auth_method: event.target.value })} className={inputClass}>
                    <option value="">All methods</option>
                    {options.authMethods.map((option) => <option key={optionValue(option)} value={optionValue(option)}>{optionLabel(option)}</option>)}
                  </select>
                </label>
                <label>
                  <span className="mb-1 block text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">From</span>
                  <input type="date" value={draft.date_from} onChange={(event) => setDraft({ ...draft, date_from: event.target.value })} className={inputClass} />
                </label>
                <label>
                  <span className="mb-1 block text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">To</span>
                  <input type="date" value={draft.date_to} onChange={(event) => setDraft({ ...draft, date_to: event.target.value })} className={inputClass} />
                </label>
              </div>
              <div className="mt-3 flex flex-wrap items-end justify-between gap-3 border-t border-healthcare-border pt-3 dark:border-healthcare-border-dark">
                <label className="flex items-center gap-2 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Rows
                  <select
                    aria-label="Rows per page"
                    value={draft.per_page}
                    onChange={(event) => setDraft({ ...draft, per_page: Number(event.target.value) })}
                    className="h-8 rounded-md border border-healthcare-border bg-healthcare-surface px-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
                  >
                    {[25, 50, 100].map((size) => <option key={size} value={size}>{size}</option>)}
                  </select>
                </label>
                <div className="flex items-center gap-2">
                  <button type="button" onClick={resetFilters} className="inline-flex h-9 items-center gap-1.5 rounded-md border border-healthcare-border px-3 text-sm font-medium text-healthcare-text-secondary hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark">
                    <RotateCcw className="h-4 w-4" aria-hidden="true" />
                    Reset
                  </button>
                  <button type="submit" className="inline-flex h-9 items-center gap-1.5 rounded-md bg-healthcare-primary px-3 text-sm font-medium text-white hover:bg-healthcare-primary/90">
                    <Filter className="h-4 w-4" aria-hidden="true" />
                    Apply filters
                  </button>
                </div>
              </div>
            </form>
          </section>

          <section>
            <AdminSectionHeading
              title="Accountability activity"
              description={events.total > 0 ? `Showing ${events.from ?? 0}-${events.to ?? 0} of ${events.total.toLocaleString()} events` : 'No events match the current filters'}
            />
            {events.data.length === 0 ? (
              <div className="rounded-md border border-dashed border-healthcare-border px-4 py-10 text-center dark:border-healthcare-border-dark">
                <ScrollText className="mx-auto h-6 w-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
                <p className="mt-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">No accountability activity found</p>
                <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Adjust or reset the filters to broaden the audit view.</p>
              </div>
            ) : (
              <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                <table className="min-w-[960px] w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                  <thead className="bg-healthcare-surface-secondary dark:bg-healthcare-surface-hover-dark">
                    <tr>
                      <th scope="col" className="w-10 px-2 py-2"><span className="sr-only">Details</span></th>
                      {['Time', 'Actor', 'Event', 'Outcome', 'Source / IP'].map((label) => (
                        <th key={label} scope="col" className="whitespace-nowrap px-3 py-2 text-left text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {events.data.map((event, index) => {
                      const expanded = expandedEvent === event.eventUuid;
                      const detailId = `audit-event-detail-${index}`;
                      return (
                        <Fragment key={event.eventUuid}>
                          <tr className="align-top">
                            <td className="px-2 py-2">
                              <button
                                type="button"
                                aria-label={`${expanded ? 'Hide' : 'Show'} details for ${humanize(event.action)}`}
                                aria-expanded={expanded}
                                aria-controls={detailId}
                                onClick={() => setExpandedEvent(expanded ? null : event.eventUuid)}
                                className="flex h-7 w-7 items-center justify-center rounded-md text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark"
                              >
                                {expanded ? <ChevronUp className="h-4 w-4" aria-hidden="true" /> : <ChevronDown className="h-4 w-4" aria-hidden="true" />}
                              </button>
                            </td>
                            <td className="whitespace-nowrap px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              <time dateTime={event.occurredAt}>{formatAuditTime(event.occurredAt)}</time>
                            </td>
                            <td className="px-3 py-2">
                              <span className="block font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{actorDisplayName(event)}</span>
                              <span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{event.actor?.username || event.actorRole || 'System activity'}</span>
                            </td>
                            <td className="min-w-56 px-3 py-2">
                              <span className="block font-medium capitalize text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{humanize(event.action)}</span>
                              <span className="block text-xs capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{humanize(event.category)}{event.authMethod ? ` · ${humanize(event.authMethod)}` : ''}</span>
                            </td>
                            <td className="whitespace-nowrap px-3 py-2"><OutcomeBadge outcome={event.outcome} /></td>
                            <td className="px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              <span className="block">{event.sourceSurface || 'Unknown source'}</span>
                              <span className="block font-mono text-xs">{event.clientIp || 'IP unavailable'}</span>
                            </td>
                          </tr>
                          {expanded ? (
                            <tr id={detailId}>
                              <td colSpan={6} className="bg-healthcare-surface-secondary px-4 py-3 dark:bg-healthcare-background-dark">
                                <div className="grid gap-4 lg:grid-cols-3">
                                  <dl className="grid grid-cols-[auto_1fr] content-start gap-x-3 gap-y-1 text-xs">
                                    <dt className="font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Route</dt>
                                    <dd className="break-all text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{event.routeName || event.routeUri || 'Not recorded'}</dd>
                                    <dt className="font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Request</dt>
                                    <dd className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{event.httpMethod || 'Unknown'} · {event.responseStatus ?? 'No status'}</dd>
                                    <dt className="font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Target</dt>
                                    <dd className="break-all text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{event.targetType ? `${event.targetType}${event.targetId !== null ? ` / ${event.targetId}` : ''}` : 'Not recorded'}</dd>
                                    <dt className="font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Reason</dt>
                                    <dd className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{event.reasonCode ? humanize(event.reasonCode) : 'Not recorded'}</dd>
                                    <dt className="font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">User agent</dt>
                                    <dd className="break-all text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{event.userAgent || 'Not recorded'}</dd>
                                  </dl>
                                  <div>
                                    <h3 className="text-xs font-semibold uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Changes</h3>
                                    <pre className="mt-1 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-md border border-healthcare-border bg-healthcare-surface p-2 text-xs text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark">{safeJson(event.changes)}</pre>
                                  </div>
                                  <div>
                                    <h3 className="text-xs font-semibold uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Metadata</h3>
                                    <pre className="mt-1 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-md border border-healthcare-border bg-healthcare-surface p-2 text-xs text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark">{safeJson(event.metadata)}</pre>
                                  </div>
                                </div>
                              </td>
                            </tr>
                          ) : null}
                        </Fragment>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            <nav aria-label="Audit pagination" className="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm">
              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Page {events.current_page} of {Math.max(events.last_page, 1)}</span>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={!events.prev_page_url}
                  onClick={() => visit(filters, events.current_page - 1)}
                  className="inline-flex h-9 items-center gap-1 rounded-md border border-healthcare-border px-3 font-medium text-healthcare-text-secondary hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark"
                >
                  <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Previous
                </button>
                <button
                  type="button"
                  disabled={!events.next_page_url}
                  onClick={() => visit(filters, events.current_page + 1)}
                  className="inline-flex h-9 items-center gap-1 rounded-md border border-healthcare-border px-3 font-medium text-healthcare-text-secondary hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark"
                >
                  Next <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
            </nav>
          </section>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
