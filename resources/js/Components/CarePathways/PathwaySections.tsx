import { Fingerprint } from "lucide-react";
import { Surface } from "@/Components/ui/Surface";
import { Section } from "@/Components/system";
import type { PathwaySection } from "@/lib/carePathways/catalogSchemas";

// Canonical clinical reading order — mirrors config/care-pathways.php
// source_section_fields. Unknown codes sort after these, alphabetically.
const SECTION_ORDER: readonly string[] = [
    "admission_criteria",
    "risk_stratification",
    "initial_workup_labs",
    "initial_imaging_dx",
    "time_critical_interventions",
    "initial_management",
    "day1_milestones",
    "day2_milestones",
    "day3plus_milestones",
    "consults_multidisciplinary",
    "monitoring_level",
    "nutrition_mobility_vte",
    "discharge_criteria",
    "discharge_planning",
    "expected_los",
    "target_los",
    "quality_metrics",
    "common_complications",
    "readmission_drivers",
    "guideline_source",
    "key_citations",
    "evidence_grade",
    "severity_cc_mcc_notes",
    "pathway_pearls",
    "verification_notes",
    "scope_and_volume_notes",
    "clinical_verification_basis",
    "data_quality_notes",
];

const SECTION_LABELS: Record<string, string> = {
    admission_criteria: "Admission Criteria",
    risk_stratification: "Risk Stratification",
    initial_workup_labs: "Initial Workup — Labs",
    initial_imaging_dx: "Initial Imaging & Diagnostics",
    time_critical_interventions: "Time-Critical Interventions",
    initial_management: "Initial Management",
    day1_milestones: "Day 1 Milestones",
    day2_milestones: "Day 2 Milestones",
    day3plus_milestones: "Day 3+ Milestones",
    consults_multidisciplinary: "Consults & Multidisciplinary Care",
    monitoring_level: "Monitoring Level",
    nutrition_mobility_vte: "Nutrition, Mobility & VTE Prophylaxis",
    discharge_criteria: "Discharge Criteria",
    discharge_planning: "Discharge Planning",
    expected_los: "Expected Length of Stay",
    target_los: "Target Length of Stay",
    quality_metrics: "Quality Metrics",
    common_complications: "Common Complications",
    readmission_drivers: "Readmission Drivers",
    guideline_source: "Guideline Source",
    key_citations: "Key Citations",
    evidence_grade: "Evidence Grade",
    severity_cc_mcc_notes: "Severity CC/MCC Notes",
    pathway_pearls: "Pathway Pearls",
    verification_notes: "Verification Notes",
    scope_and_volume_notes: "Scope & Volume Notes",
    clinical_verification_basis: "Clinical Verification Basis",
    data_quality_notes: "Data Quality Notes",
};

const sectionLabel = (code: string): string =>
    SECTION_LABELS[code] ??
    code
        .replaceAll("_", " ")
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

const orderIndex = (code: string): number => {
    const index = SECTION_ORDER.indexOf(code);
    return index === -1 ? SECTION_ORDER.length : index;
};

function SectionRow({ section }: { section: PathwaySection }) {
    return (
        <details className="group border-b border-healthcare-border last:border-b-0 dark:border-healthcare-border-dark">
            <summary className="flex cursor-pointer items-center justify-between gap-3 px-4 py-2.5 hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark">
                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {sectionLabel(section.section_code)}
                </span>
                <span className="flex shrink-0 items-center gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {section.content_mode !== "source" && (
                        <span className="rounded-full border border-healthcare-border px-2 py-0.5 dark:border-healthcare-border-dark">
                            {section.content_mode}
                        </span>
                    )}
                    <span
                        className="inline-flex items-center gap-1"
                        title={`Source digest ${section.source_digest}`}
                    >
                        <Fingerprint className="h-3.5 w-3.5" aria-hidden="true" />
                        <span className="tabular-nums">
                            {section.source_digest.slice(0, 8)}
                        </span>
                    </span>
                </span>
            </summary>
            <div className="space-y-3 px-4 pb-4 pt-1">
                <p className="max-w-prose whitespace-pre-line text-sm leading-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {section.source_text}
                </p>
                {section.approved_text !== null && (
                    <div className="border-t border-healthcare-border pt-3 dark:border-healthcare-border-dark">
                        <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-success dark:text-healthcare-success-dark">
                            Approved text ({section.review_state})
                        </p>
                        <p className="mt-1 max-w-prose whitespace-pre-line text-sm leading-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {section.approved_text}
                        </p>
                    </div>
                )}
            </div>
        </details>
    );
}

const AUDIENCE_LABELS: Record<string, string> = {
    staff_reference: "Staff reference",
    staff_workflow: "Staff workflow",
    patient: "Patient",
    caregiver: "Caregiver",
};

export function PathwaySections({
    sections,
}: {
    sections: readonly PathwaySection[];
}) {
    const byAudience = new Map<string, PathwaySection[]>();
    for (const section of sections) {
        const group = byAudience.get(section.audience) ?? [];
        byAudience.set(section.audience, [...group, section]);
    }

    return (
        <Section
            title="Clinical content"
            summary={`${sections.length} immutable source sections — editorial approval required before any serving`}
            icon="heroicons:document-text"
        >
            <div className="space-y-4">
                {[...byAudience.entries()].map(([audience, group]) => (
                    <Surface key={audience} className="overflow-hidden">
                        <p className="border-b border-healthcare-border px-4 py-2 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                            {AUDIENCE_LABELS[audience] ?? audience}
                        </p>
                        {[...group]
                            .sort(
                                (a, b) =>
                                    orderIndex(a.section_code) -
                                    orderIndex(b.section_code),
                            )
                            .map((section) => (
                                <SectionRow
                                    key={section.section_uuid}
                                    section={section}
                                />
                            ))}
                    </Surface>
                ))}
            </div>
        </Section>
    );
}
