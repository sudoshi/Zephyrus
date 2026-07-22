import type { PatientCommunicationMutationKind } from "@/Pages/PatientCommunications/mutationSafety";
import type {
    PatientCommunicationRouteCandidates,
    PatientCommunicationRoutingKind,
} from "@/Pages/PatientCommunications/routingPolicy";
import { LoaderCircle } from "lucide-react";

export interface RoutingIntent {
    kind: PatientCommunicationRoutingKind;
    title: string;
    description: string;
    payload: Record<string, string | number>;
    successMessage: string;
}

interface SelectOption {
    value: string;
    label: string;
}

function RoutingSelect({
    label,
    value,
    options,
    disabled,
    onChange,
}: {
    label: string;
    value: string;
    options: SelectOption[];
    disabled: boolean;
    onChange: (value: string) => void;
}) {
    return (
        <label className="text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {label}
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                disabled={disabled}
                className="mt-1 min-h-10 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function scopeLabel(
    candidate: PatientCommunicationRouteCandidates["reroute_candidates"][number],
): string {
    if (candidate.scope_type === "unit" && candidate.unit) {
        return candidate.unit.label;
    }
    return candidate.scope_type === "facility"
        ? "Facility-wide team"
        : "Enterprise-wide team";
}

export default function RoutingControls({
    candidates,
    loading,
    blocked,
    mutation,
    intent,
    releaseReason,
    reassignReason,
    rerouteReason,
    targetMembershipUuid,
    targetPoolUuid,
    onLoad,
    onHide,
    onReleaseReason,
    onReassignReason,
    onRerouteReason,
    onTargetMembership,
    onTargetPool,
    onReviewRelease,
    onReviewReassign,
    onReviewReroute,
    onCancelIntent,
    onConfirmIntent,
}: {
    candidates: PatientCommunicationRouteCandidates | null;
    loading: boolean;
    blocked: boolean;
    mutation: PatientCommunicationMutationKind | null;
    intent: RoutingIntent | null;
    releaseReason: string;
    reassignReason: string;
    rerouteReason: string;
    targetMembershipUuid: string;
    targetPoolUuid: string;
    onLoad: () => void;
    onHide: () => void;
    onReleaseReason: (value: string) => void;
    onReassignReason: (value: string) => void;
    onRerouteReason: (value: string) => void;
    onTargetMembership: (value: string) => void;
    onTargetPool: (value: string) => void;
    onReviewRelease: () => void;
    onReviewReassign: () => void;
    onReviewReroute: () => void;
    onCancelIntent: () => void;
    onConfirmIntent: () => void;
}) {
    const actionDisabled = blocked || mutation !== null;
    const noActions =
        candidates !== null &&
        !candidates.actions.can_release &&
        !candidates.actions.can_reassign &&
        !candidates.actions.can_reroute;

    return (
        <section
            className="space-y-3 rounded-md border border-healthcare-border bg-healthcare-background/60 p-3 dark:border-healthcare-border-dark dark:bg-white/5"
            aria-labelledby="routing-controls-heading"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3
                        id="routing-controls-heading"
                        className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                    >
                        Accountable routing
                    </h3>
                    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Candidate lists load only on request and are
                        reauthorized by the server.
                    </p>
                </div>
                {candidates === null ? (
                    <button
                        type="button"
                        onClick={onLoad}
                        disabled={loading || actionDisabled}
                        className="inline-flex min-h-10 items-center gap-2 rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-background disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:hover:bg-white/5"
                    >
                        {loading && (
                            <LoaderCircle
                                className="size-4 animate-spin"
                                aria-hidden="true"
                            />
                        )}
                        {loading ? "Verifying routing…" : "Manage routing"}
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={onHide}
                        disabled={actionDisabled}
                        className="min-h-10 rounded-md px-3 py-2 text-sm font-semibold text-healthcare-primary hover:bg-healthcare-primary/10 disabled:cursor-not-allowed disabled:opacity-60 dark:text-healthcare-primary-dark"
                    >
                        Hide controls
                    </button>
                )}
            </div>

            {noActions && (
                <p className="rounded-md bg-healthcare-surface p-3 text-sm text-healthcare-text-secondary dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
                    No routing change is authorized for your current membership
                    and this conversation state.
                </p>
            )}

            {candidates?.actions.can_release && (
                <div className="grid gap-2 rounded-md border border-healthcare-border bg-healthcare-surface p-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                    <RoutingSelect
                        label="Release reason"
                        value={releaseReason}
                        options={candidates.reason_options.release.map(
                            ({ code, label }) => ({ value: code, label }),
                        )}
                        disabled={blocked}
                        onChange={onReleaseReason}
                    />
                    <button
                        type="button"
                        onClick={onReviewRelease}
                        disabled={actionDisabled || !releaseReason}
                        className="min-h-10 rounded-md border border-healthcare-border px-3 py-2 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-background disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-white/5"
                    >
                        Review release
                    </button>
                </div>
            )}

            {candidates?.actions.can_reassign && (
                <div className="grid gap-2 rounded-md border border-healthcare-border bg-healthcare-surface p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                    <RoutingSelect
                        label="Eligible responder"
                        value={targetMembershipUuid}
                        options={candidates.reassign_candidates.map(
                            ({ membership_uuid, label, membership_role }) => ({
                                value: membership_uuid,
                                label: `${label} · ${membership_role}`,
                            }),
                        )}
                        disabled={blocked}
                        onChange={onTargetMembership}
                    />
                    <RoutingSelect
                        label="Reassignment reason"
                        value={reassignReason}
                        options={candidates.reason_options.reassign.map(
                            ({ code, label }) => ({ value: code, label }),
                        )}
                        disabled={blocked}
                        onChange={onReassignReason}
                    />
                    <button
                        type="button"
                        onClick={onReviewReassign}
                        disabled={
                            actionDisabled ||
                            !targetMembershipUuid ||
                            !reassignReason
                        }
                        className="min-h-10 rounded-md border border-healthcare-border px-3 py-2 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-background disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-white/5"
                    >
                        Review reassignment
                    </button>
                </div>
            )}

            {candidates?.actions.can_reroute && (
                <div className="grid gap-2 rounded-md border border-healthcare-border bg-healthcare-surface p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                    <RoutingSelect
                        label="Destination team"
                        value={targetPoolUuid}
                        options={candidates.reroute_candidates.map(
                            (candidate) => ({
                                value: candidate.pool_uuid,
                                label: `${candidate.label} · ${scopeLabel(candidate)}`,
                            }),
                        )}
                        disabled={blocked}
                        onChange={onTargetPool}
                    />
                    <RoutingSelect
                        label="Reroute reason"
                        value={rerouteReason}
                        options={candidates.reason_options.reroute.map(
                            ({ code, label }) => ({ value: code, label }),
                        )}
                        disabled={blocked}
                        onChange={onRerouteReason}
                    />
                    <button
                        type="button"
                        onClick={onReviewReroute}
                        disabled={
                            actionDisabled || !targetPoolUuid || !rerouteReason
                        }
                        className="min-h-10 rounded-md border border-healthcare-border px-3 py-2 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-background disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-white/5"
                    >
                        Review reroute
                    </button>
                </div>
            )}

            {intent && (
                <div
                    role="alertdialog"
                    aria-modal="false"
                    aria-labelledby="routing-confirmation-title"
                    aria-describedby="routing-confirmation-description"
                    className="rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-4"
                >
                    <h4
                        id="routing-confirmation-title"
                        className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                    >
                        {intent.title}
                    </h4>
                    <p
                        id="routing-confirmation-description"
                        className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                    >
                        {intent.description}
                    </p>
                    <div className="mt-3 flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            onClick={onCancelIntent}
                            disabled={mutation !== null}
                            className="min-h-10 rounded-md px-3 py-2 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-background disabled:opacity-60 dark:text-healthcare-text-primary-dark dark:hover:bg-white/5"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={onConfirmIntent}
                            disabled={actionDisabled}
                            className="min-h-10 rounded-md bg-healthcare-primary px-4 py-2 text-sm font-semibold text-white hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {mutation === intent.kind
                                ? "Applying…"
                                : `Confirm ${intent.kind}`}
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}
