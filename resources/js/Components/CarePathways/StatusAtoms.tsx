import {
    AlertTriangle,
    CheckCircle2,
    Circle,
    Clock3,
    PauseCircle,
    ShieldCheck,
    XCircle,
} from "lucide-react";
import type { DrgCandidate } from "@/lib/carePathways/catalogSchemas";

// Small shared display atoms for the Catalog Explorer. Status is always
// icon + label — never color alone (canon).

const chipBase =
    "inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium";

export function EvidenceBadge({ status }: { status: string }) {
    const limitations = status.toLowerCase().includes("limitations");

    return limitations ? (
        <span
            className={`${chipBase} border-healthcare-warning/40 text-healthcare-warning dark:border-healthcare-warning-dark/40 dark:text-healthcare-warning-dark`}
            title={status}
        >
            <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
            Limitations
        </span>
    ) : (
        <span
            className={`${chipBase} border-healthcare-success/40 text-healthcare-success dark:border-healthcare-success-dark/40 dark:text-healthcare-success-dark`}
            title={status}
        >
            <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
            Verified
        </span>
    );
}

const approvalDisplay: Record<
    string,
    { label: string; icon: typeof Circle; tone: string }
> = {
    not_reviewed: {
        label: "Not reviewed",
        icon: Circle,
        tone: "border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark",
    },
    in_review: {
        label: "In review",
        icon: Clock3,
        tone: "border-healthcare-info/40 text-healthcare-info dark:border-healthcare-info-dark/40 dark:text-healthcare-info-dark",
    },
    approved: {
        label: "Approved",
        icon: CheckCircle2,
        tone: "border-healthcare-success/40 text-healthcare-success dark:border-healthcare-success-dark/40 dark:text-healthcare-success-dark",
    },
    rejected: {
        label: "Rejected",
        icon: XCircle,
        tone: "border-healthcare-warning/40 text-healthcare-warning dark:border-healthcare-warning-dark/40 dark:text-healthcare-warning-dark",
    },
    withdrawn: {
        label: "Withdrawn",
        icon: XCircle,
        tone: "border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark",
    },
};

export function GovernanceStatus({
    approval,
    activation,
}: {
    approval: string;
    activation: string;
}) {
    const display = approvalDisplay[approval] ?? approvalDisplay.not_reviewed;
    const ApprovalIcon = display.icon;

    return (
        <span className="inline-flex flex-wrap items-center gap-1.5">
            <span className={`${chipBase} ${display.tone}`}>
                <ApprovalIcon className="h-3.5 w-3.5" aria-hidden="true" />
                {display.label}
            </span>
            <span
                className={`${chipBase} border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark`}
            >
                <PauseCircle className="h-3.5 w-3.5" aria-hidden="true" />
                {activation === "inactive"
                    ? "Inactive"
                    : activation === "active"
                      ? "Active"
                      : "Withdrawn"}
            </span>
        </span>
    );
}

const roleTone: Record<string, string> = {
    candidate:
        "border-healthcare-primary/40 text-healthcare-primary dark:border-healthcare-primary-dark/40 dark:text-healthcare-primary-dark",
    supporting:
        "border-healthcare-info/40 text-healthcare-info dark:border-healthcare-info-dark/40 dark:text-healthcare-info-dark",
    retrospective_reconciliation:
        "border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark",
    excluded:
        "border-healthcare-warning/40 text-healthcare-warning dark:border-healthcare-warning-dark/40 dark:text-healthcare-warning-dark",
};

export function DrgChips({
    drgs,
    max = 6,
}: {
    drgs: readonly DrgCandidate[];
    max?: number;
}) {
    const shown = drgs.slice(0, max);
    const overflow = drgs.length - shown.length;

    return (
        <span className="inline-flex flex-wrap items-center gap-1">
            {shown.map((drg) => (
                <span
                    key={drg.ms_drg}
                    className={`${chipBase} tabular-nums ${roleTone[drg.mapping_role] ?? roleTone.candidate}`}
                    title={`${drg.ms_drg} — ${drg.title} (${drg.mapping_role.replaceAll("_", " ")})`}
                >
                    {drg.ms_drg}
                </span>
            ))}
            {overflow > 0 && (
                <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    +{overflow} more
                </span>
            )}
        </span>
    );
}
