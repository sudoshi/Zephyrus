import { Search, X } from "lucide-react";
import { useEffect, useState } from "react";
import type { PathwayQuery } from "@/lib/carePathways/catalogApi";

interface PathwayFiltersProps {
    value: PathwayQuery;
    onChange: (next: PathwayQuery) => void;
}

const inputClass =
    "w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-1.5 text-sm text-healthcare-text-primary placeholder:text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:placeholder:text-healthcare-text-secondary-dark";

const DEFAULT_QUERY: PathwayQuery = { page: 1, per_page: 25 };

export function PathwayFilters({ value, onChange }: PathwayFiltersProps) {
    const [search, setSearch] = useState(value.q ?? "");
    const [drg, setDrg] = useState(value.drg ?? "");

    // Debounce free-text inputs; selects apply immediately.
    useEffect(() => {
        const handle = setTimeout(() => {
            if ((value.q ?? "") !== search || (value.drg ?? "") !== drg) {
                onChange({
                    ...value,
                    q: search || undefined,
                    drg: drg || undefined,
                    page: 1,
                });
            }
        }, 300);
        return () => clearTimeout(handle);
    }, [search, drg, value, onChange]);

    const setSelect = (key: keyof PathwayQuery) => (selected: string) => {
        onChange({
            ...value,
            [key]: selected === "" ? undefined : selected,
            page: 1,
        });
    };

    const hasFilters =
        Boolean(search || drg) ||
        Boolean(
            value.mdc ||
            value.service_line ||
            value.evidence_state ||
            value.disposition ||
            value.institutional_approval_status ||
            value.activation_status,
        );

    return (
        <div className="flex flex-wrap items-center gap-2">
            <label className="relative min-w-56 flex-1">
                <span className="sr-only">Search pathways</span>
                <Search
                    className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                    aria-hidden="true"
                />
                <input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search condition or pathway key…"
                    className={`${inputClass} pl-8`}
                />
            </label>

            <label className="w-24">
                <span className="sr-only">MS-DRG code</span>
                <input
                    type="text"
                    inputMode="numeric"
                    maxLength={3}
                    value={drg}
                    onChange={(event) =>
                        setDrg(event.target.value.replace(/[^0-9]/g, ""))
                    }
                    placeholder="DRG"
                    className={`${inputClass} tabular-nums`}
                />
            </label>

            <label>
                <span className="sr-only">Evidence state</span>
                <select
                    value={value.evidence_state ?? ""}
                    onChange={(event) =>
                        setSelect("evidence_state")(event.target.value)
                    }
                    className={inputClass}
                >
                    <option value="">Evidence: all</option>
                    <option value="verified">Verified</option>
                    <option value="limitations">With limitations</option>
                </select>
            </label>

            <label>
                <span className="sr-only">Disposition</span>
                <select
                    value={value.disposition ?? ""}
                    onChange={(event) =>
                        setSelect("disposition")(event.target.value)
                    }
                    className={inputClass}
                >
                    <option value="">Disposition: all</option>
                    <option value="signoff">Sign-off queue</option>
                    <option value="specialist_review">Specialist review</option>
                    <option value="redesign">Redesign</option>
                </select>
            </label>

            <label>
                <span className="sr-only">Institutional approval</span>
                <select
                    value={value.institutional_approval_status ?? ""}
                    onChange={(event) =>
                        setSelect("institutional_approval_status")(
                            event.target.value,
                        )
                    }
                    className={inputClass}
                >
                    <option value="">Approval: all</option>
                    <option value="not_reviewed">Not reviewed</option>
                    <option value="in_review">In review</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="withdrawn">Withdrawn</option>
                </select>
            </label>

            {hasFilters && (
                <button
                    type="button"
                    onClick={() => {
                        setSearch("");
                        setDrg("");
                        onChange({ ...DEFAULT_QUERY });
                    }}
                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-healthcare-text-secondary hover:bg-healthcare-surface-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-surface-hover-dark"
                >
                    <X className="h-4 w-4" aria-hidden="true" />
                    Clear
                </button>
            )}
        </div>
    );
}
