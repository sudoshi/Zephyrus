import { Fingerprint } from "lucide-react";
import { Surface } from "@/Components/ui/Surface";
import { Section } from "@/Components/system";
import type { VersionEnvelope } from "@/lib/carePathways/catalogSchemas";

type VersionData = VersionEnvelope["data"];

function Digest({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <Fingerprint className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span className="font-medium">{label}</span>
            <span className="truncate tabular-nums" title={value}>
                {value}
            </span>
        </div>
    );
}

const changeText = (value: unknown): string => {
    if (value === null || value === undefined || value === "") return "—";
    return typeof value === "string" ? value : JSON.stringify(value);
};

export function GovernanceLedger({
    governance,
    version,
    changes,
}: {
    governance: VersionData["governance"];
    version: VersionData["version"];
    changes: VersionData["changes"];
}) {
    const flags = Array.isArray(version.unresolved_flags)
        ? version.unresolved_flags
        : [];

    return (
        <Section
            title="Governance ledger"
            summary="Append-only reviews, approvals, and source-change history"
            icon="heroicons:scale"
        >
            <div className="grid gap-4 lg:grid-cols-2">
                <Surface className="p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Reviews ({governance.reviews.length}) · Approvals (
                        {governance.approvals.length})
                    </p>
                    {governance.reviews.length === 0 &&
                    governance.approvals.length === 0 ? (
                        <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            No reviews or approvals recorded — this version has
                            not entered institutional review.
                        </p>
                    ) : (
                        <div className="mt-2 space-y-3">
                            {governance.reviews.map((review) => (
                                <div key={review.review_uuid} className="text-sm">
                                    <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {review.reviewer_role} —{" "}
                                        {review.decision.replaceAll("_", " ")}
                                    </p>
                                    <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {review.review_scope} ·{" "}
                                        {review.reviewed_at ?? "—"}
                                    </p>
                                    <p className="mt-0.5 max-w-prose text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {review.reason}
                                    </p>
                                </div>
                            ))}
                            {governance.approvals.map((approval) => (
                                <div
                                    key={approval.approval_uuid}
                                    className="text-sm"
                                >
                                    <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {approval.approval_type.replaceAll(
                                            "_",
                                            " ",
                                        )}{" "}
                                        — {approval.decision}
                                    </p>
                                    <p className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {approval.decided_at ?? "—"}
                                    </p>
                                </div>
                            ))}
                        </div>
                    )}
                </Surface>

                <Surface className="p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Provenance
                    </p>
                    <div className="mt-2 space-y-1.5">
                        <Digest label="Content" value={version.content_digest} />
                        <Digest label="Source" value={version.source_digest} />
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Effective{" "}
                            <span className="tabular-nums">
                                {version.effective_period.start ?? "—"}
                            </span>{" "}
                            →{" "}
                            <span className="tabular-nums">
                                {version.effective_period.end ?? "open"}
                            </span>
                            {" · "}source cutoff{" "}
                            <span className="tabular-nums">
                                {version.source_cutoff_date ?? "—"}
                            </span>
                        </div>
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Unresolved flags:{" "}
                            <span className="tabular-nums">{flags.length}</span>
                            {flags.length > 0 && (
                                <span> — {flags.map(changeText).join(", ")}</span>
                            )}
                        </div>
                    </div>

                    <p className="mt-4 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Source changes ({changes.length})
                    </p>
                    {changes.length === 0 ? (
                        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            No source-change entries for this pathway.
                        </p>
                    ) : (
                        <div className="mt-2 space-y-2">
                            {changes.map((change, index) => (
                                <div
                                    key={changeText(change.change_uuid) + index}
                                    className="text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                >
                                    <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {changeText(change.source_field).replaceAll(
                                            "_",
                                            " ",
                                        )}
                                        {" · "}
                                        <span className="tabular-nums">
                                            {changeText(change.changed_on)}
                                        </span>
                                    </p>
                                    <p className="max-w-prose">
                                        {changeText(change.reason)}
                                    </p>
                                </div>
                            ))}
                        </div>
                    )}
                </Surface>
            </div>
        </Section>
    );
}
