import type {
    IntegrationControlPlane,
    IntegrationSource,
    IntegrationSourceInput,
} from "@/features/integrations/api";
import {
    useCreateIntegrationCredential,
    useCreateIntegrationEndpoint,
    useCreateIntegrationSource,
    useCreateSourceEvidence,
    useCreateSourceOnboardingVersion,
    useCancelSourceActivationWindow,
    useDeleteIntegrationCredential,
    useDeleteIntegrationEndpoint,
    useProposeSourceConfiguration,
    useRequestSourceConfigurationApplication,
    useRequestSourceActivation,
    useRequestScheduledSourceActivation,
    useRetireIntegrationSource,
    useSourceConfigurationVersions,
    useSourceLifecycleEvents,
    useSourceOnboarding,
    useAssessSourceReadiness,
    useTransitionSourceLifecycle,
    useUpdateIntegrationEndpoint,
    useUpdateIntegrationSource,
} from "@/features/integrations/hooks";
import { router } from "@inertiajs/react";
import axios from "axios";
import { Archive, KeyRound, Pencil, Plus, Trash2, X } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";

const inputClass =
    "w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark";
const labelClass =
    "space-y-1 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark";
const secondaryButton =
    "inline-flex min-h-9 items-center gap-2 rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-hover disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark";
const primaryButton =
    "inline-flex min-h-9 items-center gap-2 rounded-md bg-healthcare-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-healthcare-primary/90 disabled:opacity-50";

function errorMessage(error: unknown): string | null {
    if (!axios.isAxiosError(error))
        return error ? "Configuration request failed." : null;
    const errors = error.response?.data?.errors as
        | Record<string, string[]>
        | undefined;
    const first = errors ? Object.values(errors).flat()[0] : undefined;
    return (
        first ??
        error.response?.data?.error?.message ??
        error.response?.data?.message ??
        "Configuration request failed."
    );
}

const emptySourceForm = {
    sourceKey: "",
    sourceName: "",
    vendor: "",
    systemClass: "ehr",
    environment: "sandbox",
    baseUrl: "",
    interfaceType: "fhir_r4",
    contractStatus: "planning",
    baaStatus: "planning",
    owner: "",
    cadence: "15",
    phiAllowed: false,
    changeReason: "",
};

export function SourceConfiguration({
    data,
    selectedSourceId,
    hasFacilityScope,
}: {
    data: IntegrationControlPlane;
    selectedSourceId: number | null;
    hasFacilityScope: boolean;
}) {
    const create = useCreateIntegrationSource();
    const update = useUpdateIntegrationSource();
    const propose = useProposeSourceConfiguration();
    const requestApplication = useRequestSourceConfigurationApplication();
    const requestActivation = useRequestSourceActivation();
    const transitionLifecycle = useTransitionSourceLifecycle();
    const retire = useRetireIntegrationSource();
    const versions = useSourceConfigurationVersions(selectedSourceId);
    const lifecycle = useSourceLifecycleEvents(selectedSourceId);
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<IntegrationSource | null>(null);
    const [form, setForm] = useState(emptySourceForm);
    const selectedSource = data.sources.find(
        (source) => source.sourceId === selectedSourceId,
    );
    const availableTransitions: Record<string, string[]> = {
        draft: ["discovery", "configured"],
        discovery: ["draft", "configured"],
        configured: ["discovery", "validating"],
        validating: ["configured"],
        live: ["degraded", "suspended"],
        degraded: ["suspended"],
        suspended: ["configured", "validating"],
    };

    const edit = (source: IntegrationSource) => {
        setEditing(source);
        setForm({
            sourceKey: source.sourceKey,
            sourceName: source.sourceName,
            vendor: source.vendor ?? "",
            systemClass: source.systemClass,
            environment: source.environment,
            baseUrl: "",
            interfaceType: source.interfaceType,
            contractStatus: source.contractStatus,
            baaStatus: source.baaStatus,
            owner: source.owner ?? "",
            cadence: source.expectedCadenceMinutes?.toString() ?? "",
            phiAllowed: source.phiAllowed,
            changeReason: "",
        });
        setOpen(true);
    };

    const close = () => {
        setOpen(false);
        setEditing(null);
        setForm(emptySourceForm);
        create.reset();
        update.reset();
        propose.reset();
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const common: Partial<IntegrationSourceInput> = {
            source_name: form.sourceName,
            vendor: form.vendor || null,
            system_class: form.systemClass,
            environment: form.environment,
            interface_type: form.interfaceType,
            contract_status: form.contractStatus,
            baa_status: form.baaStatus,
            phi_allowed: form.phiAllowed,
            owner: form.owner || null,
            expected_cadence_minutes: form.cadence
                ? Number(form.cadence)
                : null,
            ...(form.baseUrl ? { base_url: form.baseUrl } : {}),
        };
        if (editing) {
            const versionedInput = {
                ...common,
                expected_configuration_version_id:
                    editing.currentConfigurationVersionId ?? undefined,
                change_reason: form.changeReason,
            };
            if (
                [
                    "approved",
                    "scheduled",
                    "live",
                    "degraded",
                    "suspended",
                    "retired",
                ].includes(editing.lifecycleState)
            ) {
                propose.mutate(
                    { sourceId: editing.sourceId, input: versionedInput },
                    { onSuccess: close },
                );
            } else {
                update.mutate(
                    { sourceId: editing.sourceId, input: versionedInput },
                    { onSuccess: close },
                );
            }
        } else {
            create.mutate(
                {
                    ...common,
                    source_key: form.sourceKey,
                    source_name: form.sourceName,
                    system_class: form.systemClass,
                    environment: form.environment,
                    interface_type: form.interfaceType,
                    contract_status: form.contractStatus,
                    baa_status: form.baaStatus,
                } as IntegrationSourceInput,
                { onSuccess: close },
            );
        }
    };
    const mutationError = errorMessage(
        create.error ??
            update.error ??
            propose.error ??
            requestApplication.error ??
            requestActivation.error ??
            transitionLifecycle.error ??
            retire.error,
    );
    const requestGovernedApplication = async (
        sourceId: number,
        configurationVersionId: number,
    ) => {
        const reason = window.prompt(
            "Reason for governed configuration application (10–500 characters; no PHI or credentials):",
        );
        if (!reason || reason.trim().length < 10) return;
        try {
            await requestApplication.mutateAsync({
                sourceId,
                configurationVersionId,
                reason: reason.trim(),
            });
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 428) {
                const url = error.response.data?.error?.reauthentication_url;
                if (typeof url === "string") router.visit(url);
            }
        }
    };
    const transition = (toState: string) => {
        if (!selectedSource) return;
        const reason = window.prompt(
            `Reason for ${selectedSource.lifecycleState} → ${toState} (10–500 characters):`,
        );
        if (!reason || reason.trim().length < 10) return;
        transitionLifecycle.mutate({
            sourceId: selectedSource.sourceId,
            toState,
            reason: reason.trim(),
        });
    };
    const requestActivationApproval = async () => {
        if (!selectedSource) return;
        const reason = window.prompt(
            "Production activation reason and evidence summary (10–500 characters; no PHI or credentials):",
        );
        if (!reason || reason.trim().length < 10) return;
        try {
            await requestActivation.mutateAsync({
                sourceId: selectedSource.sourceId,
                reason: reason.trim(),
            });
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 428) {
                const url = error.response.data?.error?.reauthentication_url;
                if (typeof url === "string") router.visit(url);
            }
        }
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Source Configuration
                </h3>
                <button
                    type="button"
                    className={secondaryButton}
                    disabled={!hasFacilityScope}
                    onClick={() => setOpen(true)}
                >
                    <Plus className="size-4" /> Add source
                </button>
            </div>
            {open && (
                <form
                    onSubmit={submit}
                    className="grid gap-3 rounded-md border border-healthcare-border p-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-healthcare-border-dark"
                >
                    {!editing && (
                        <label className={labelClass}>
                            Source key
                            <input
                                required
                                value={form.sourceKey}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        sourceKey: e.target.value,
                                    })
                                }
                                className={inputClass}
                                placeholder="epic.fhir.production"
                            />
                        </label>
                    )}
                    <label className={labelClass}>
                        Name
                        <input
                            required
                            value={form.sourceName}
                            onChange={(e) =>
                                setForm({ ...form, sourceName: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        Vendor
                        <input
                            value={form.vendor}
                            onChange={(e) =>
                                setForm({ ...form, vendor: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        System class
                        <select
                            value={form.systemClass}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    systemClass: e.target.value,
                                })
                            }
                            className={inputClass}
                        >
                            {[
                                "ehr",
                                "bed_flow",
                                "workforce",
                                "transport",
                                "evs",
                                "orders_results",
                                "perioperative",
                                "pharmacy",
                                "imaging",
                                "ems",
                                "facilities",
                                "rtls",
                                "nurse_call",
                                "erp",
                                "supply_chain",
                                "payer",
                                "hie",
                                "public_health",
                                "other",
                            ].map((v) => (
                                <option key={v} value={v}>
                                    {v.replaceAll("_", " ")}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Interface
                        <select
                            value={form.interfaceType}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    interfaceType: e.target.value,
                                })
                            }
                            className={inputClass}
                        >
                            {[
                                "fhir_r4",
                                "hl7v2",
                                "rest_api",
                                "webhook",
                                "sftp",
                                "file",
                                "mqtt",
                                "dicomweb",
                                "x12",
                                "ccda",
                                "direct",
                                "other",
                            ].map((v) => (
                                <option key={v} value={v}>
                                    {v.replaceAll("_", " ")}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Environment
                        <select
                            value={form.environment}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    environment: e.target.value,
                                })
                            }
                            className={inputClass}
                        >
                            {["sandbox", "test", "staging", "production"].map(
                                (v) => (
                                    <option key={v}>{v}</option>
                                ),
                            )}
                        </select>
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        HTTPS base URL
                        <input
                            value={form.baseUrl}
                            onChange={(e) =>
                                setForm({ ...form, baseUrl: e.target.value })
                            }
                            className={inputClass}
                            placeholder={
                                editing && editing.baseUrlConfigured
                                    ? `Leave blank to keep ${editing.baseUrlOrigin}`
                                    : "https://approved-host.example/fhir/r4"
                            }
                        />
                    </label>
                    <label className={labelClass}>
                        Contract
                        <select
                            value={form.contractStatus}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    contractStatus: e.target.value,
                                })
                            }
                            className={inputClass}
                        >
                            {[
                                "unknown",
                                "planning",
                                "review",
                                "executed",
                                "expired",
                                "not_required",
                            ].map((v) => (
                                <option key={v}>{v}</option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        BAA
                        <select
                            value={form.baaStatus}
                            onChange={(e) =>
                                setForm({ ...form, baaStatus: e.target.value })
                            }
                            className={inputClass}
                        >
                            {[
                                "unknown",
                                "planning",
                                "review",
                                "executed",
                                "expired",
                                "not_required",
                            ].map((v) => (
                                <option key={v}>{v}</option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Owner
                        <input
                            value={form.owner}
                            onChange={(e) =>
                                setForm({ ...form, owner: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        Cadence (minutes)
                        <input
                            type="number"
                            min="1"
                            max="10080"
                            value={form.cadence}
                            onChange={(e) =>
                                setForm({ ...form, cadence: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className="flex items-center gap-2 self-end py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        <input
                            type="checkbox"
                            checked={form.phiAllowed}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    phiAllowed: e.target.checked,
                                })
                            }
                        />{" "}
                        PHI approved
                    </label>
                    {editing && (
                        <label className={`${labelClass} sm:col-span-2 lg:col-span-4`}>
                            Change reason
                            <textarea
                                required
                                minLength={10}
                                maxLength={500}
                                value={form.changeReason}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        changeReason: e.target.value,
                                    })
                                }
                                className={inputClass}
                                placeholder="Describe the operational reason and expected impact."
                            />
                        </label>
                    )}
                    <div className="flex items-end gap-2 lg:col-span-4">
                        <button
                            type="submit"
                            disabled={
                                create.isPending ||
                                update.isPending ||
                                propose.isPending
                            }
                            className={primaryButton}
                        >
                            {editing &&
                            [
                                "approved",
                                "scheduled",
                                "live",
                                "degraded",
                                "suspended",
                                "retired",
                            ].includes(editing.lifecycleState)
                                ? "Create immutable proposal"
                                : editing
                                  ? "Save new version"
                                  : "Create source"}
                        </button>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={close}
                        >
                            <X className="size-4" /> Cancel
                        </button>
                    </div>
                </form>
            )}
            {mutationError && (
                <div
                    role="alert"
                    className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark"
                >
                    {mutationError}
                </div>
            )}
            <div className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">
                {data.sources.map((source) => (
                    <div
                        key={source.sourceId}
                        className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                    >
                        <div>
                            <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {source.sourceName}
                            </div>
                            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {source.sourceKey} ·{" "}
                                {source.baseUrlOrigin ?? "No base URL"}
                            </div>
                            <div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Lifecycle: {source.lifecycleState} · Configuration v
                                {source.currentConfigurationVersionNumber ?? "unversioned"}
                            </div>
                        </div>
                        <div className="flex gap-1">
                            <button
                                type="button"
                                title="Edit source"
                                className={secondaryButton}
                                disabled={
                                    selectedSourceId !== source.sourceId ||
                                    source.lifecycleState === "retired"
                                }
                                onClick={() => edit(source)}
                            >
                                <Pencil className="size-4" /> Edit
                            </button>
                            {source.goLiveStatus !== "retired" && (
                                <button
                                    type="button"
                                    title="Retire source"
                                    className={secondaryButton}
                                    disabled={
                                        retire.isPending ||
                                        selectedSourceId !== source.sourceId
                                    }
                                    onClick={() =>
                                        (() => {
                                            const reason = window.prompt(
                                                `Reason for retiring ${source.sourceName} (10–500 characters):`,
                                            );
                                            if (
                                                reason &&
                                                reason.trim().length >= 10
                                            ) {
                                                retire.mutate({
                                                    sourceId: source.sourceId,
                                                    reason: reason.trim(),
                                                });
                                            }
                                        })()
                                    }
                                >
                                    <Archive className="size-4" /> Retire
                                </button>
                            )}
                        </div>
                    </div>
                ))}
                {data.sources.length === 0 && (
                    <div className="p-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No sources configured.
                    </div>
                )}
            </div>
            {selectedSourceId !== null && (
                <>
                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                        <h4 className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Immutable configuration history
                        </h4>
                        <div className="mt-2 space-y-2">
                            {(versions.data ?? []).slice(0, 5).map((version) => (
                                <div key={version.configurationVersionId} className="text-xs">
                                    <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        v{version.versionNumber}{version.isEffective ? " · effective" : " · proposal"}
                                    </div>
                                    <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {version.changeReason} · {version.changedFields.join(", ") || "initial snapshot"}
                                    </div>
                                    <code className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {version.configurationSha256.slice(0, 16)}…
                                    </code>
                                    {!version.isEffective && (
                                        <button
                                            type="button"
                                            className={`${secondaryButton} mt-1`}
                                            disabled={requestApplication.isPending}
                                            onClick={() =>
                                                requestGovernedApplication(
                                                    version.sourceId,
                                                    version.configurationVersionId,
                                                )
                                            }
                                        >
                                            Request governed apply
                                        </button>
                                    )}
                                </div>
                            ))}
                            {versions.isLoading && <div className="text-xs">Loading versions…</div>}
                        </div>
                    </div>
                    <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                        <h4 className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Lifecycle event stream
                        </h4>
                        <div className="mt-2 space-y-2">
                            {selectedSource && (
                                <div className="flex flex-wrap gap-1 pb-1">
                                    {selectedSource.environment ===
                                        "production" &&
                                        [
                                            "validating",
                                            "approved",
                                            "scheduled",
                                            "degraded",
                                            "suspended",
                                        ].includes(
                                            selectedSource.lifecycleState,
                                        ) && (
                                            <button
                                                type="button"
                                                className={primaryButton}
                                                disabled={
                                                    requestActivation.isPending
                                                }
                                                onClick={
                                                    requestActivationApproval
                                                }
                                            >
                                                Request production activation
                                            </button>
                                        )}
                                    {(
                                        availableTransitions[
                                            selectedSource.lifecycleState
                                        ] ?? []
                                    ).map((state) => (
                                        <button
                                            key={state}
                                            type="button"
                                            className={secondaryButton}
                                            disabled={transitionLifecycle.isPending}
                                            onClick={() => transition(state)}
                                        >
                                            Move to {state}
                                        </button>
                                    ))}
                                </div>
                            )}
                            {(lifecycle.data ?? []).slice(0, 5).map((event) => (
                                <div key={event.lifecycleEventId} className="text-xs">
                                    <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {event.fromState ?? "created"} → {event.toState} · v{event.configurationVersionNumber}
                                    </div>
                                    <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {event.reason}
                                    </div>
                                </div>
                            ))}
                            {lifecycle.isLoading && <div className="text-xs">Loading lifecycle…</div>}
                        </div>
                    </div>
                </div>
                {selectedSource && <SourceOnboardingWorkspace source={selectedSource} />}
                </>
            )}
        </div>
    );
}

function localDateTime(minutesFromNow: number): string {
    const date = new Date(Date.now() + minutesFromNow * 60_000);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
}

function SourceOnboardingWorkspace({ source }: { source: IntegrationSource }) {
    const onboarding = useSourceOnboarding(source.sourceId);
    const saveProfile = useCreateSourceOnboardingVersion();
    const addEvidence = useCreateSourceEvidence();
    const assess = useAssessSourceReadiness();
    const schedule = useRequestScheduledSourceActivation();
    const cancelWindow = useCancelSourceActivationWindow();
    const [profile, setProfile] = useState({
        systemVersion: "",
        protocolProfile: "",
        ownerName: "",
        ownerEmail: "",
        stewardName: "",
        stewardEmail: "",
        escalationName: "",
        escalationEmail: "",
        networkRouteKey: "",
        dataClassification: "unknown",
        permittedPurpose: "",
        phiPermissionBasis: "",
        retentionPolicyKey: "",
        retentionDays: "",
        credentialStrategy: "",
        conformanceStatus: "not_tested",
        supportEntitlement: "unknown",
        vendorSupportIdentifier: "",
        maintenanceTimezone:
            Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
        maintenanceWeekday: "0",
        maintenanceStart: "02:00",
        maintenanceDuration: "60",
            availabilityPercent: "99.9",
            evaluationWindowMinutes: "1440",
            freshnessMinutes: "15",
            completenessPercent: "99.9",
            latencyMs: "5000",
        errorRatePercent: "1",
        acknowledgementSeconds: "30",
        reconciliationVariancePercent: "1",
        changeReason: "",
    });
    const [evidence, setEvidence] = useState({
        type: "contract",
        status: "verified",
        label: "",
        reference: "",
        artifactSha256: "",
        issuedAt: "",
        expiresAt: "",
        reason: "",
    });
    const [activation, setActivation] = useState({
        activateAt: localDateTime(120),
        windowEndsAt: localDateTime(180),
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
        reason: "",
    });

    useEffect(() => {
        const current = onboarding.data?.currentProfile;
        if (!current) return;
        const contact = (role: string) =>
            current.contacts.find((item) => item.role === role);
        const maintenance = current.maintenanceWindows[0];
        const slo = current.sloDefinition;
        setProfile({
            systemVersion: current.systemVersion ?? "",
            protocolProfile: current.protocolProfile ?? "",
            ownerName: current.ownerName ?? contact("owner")?.name ?? "",
            ownerEmail: contact("owner")?.email ?? "",
            stewardName: current.stewardName ?? contact("steward")?.name ?? "",
            stewardEmail: contact("steward")?.email ?? "",
            escalationName: contact("escalation")?.name ?? "",
            escalationEmail: contact("escalation")?.email ?? "",
            networkRouteKey: current.networkRouteKey ?? "",
            dataClassification: current.dataClassification,
            permittedPurpose: current.permittedPurpose ?? "",
            phiPermissionBasis: current.phiPermissionBasis ?? "",
            retentionPolicyKey: current.retentionPolicyKey ?? "",
            retentionDays: current.retentionDays?.toString() ?? "",
            credentialStrategy: current.credentialStrategy ?? "",
            conformanceStatus: current.conformanceStatus,
            supportEntitlement: current.supportEntitlement,
            vendorSupportIdentifier: current.vendorSupportIdentifier ?? "",
            maintenanceTimezone:
                current.maintenanceTimezone ??
                Intl.DateTimeFormat().resolvedOptions().timeZone ??
                "UTC",
            maintenanceWeekday: maintenance?.weekday?.toString() ?? "0",
            maintenanceStart: maintenance?.start_local ?? "02:00",
            maintenanceDuration:
                maintenance?.duration_minutes?.toString() ?? "60",
            availabilityPercent: String(slo.availability_percent ?? "99.9"),
            evaluationWindowMinutes: String(
                slo.evaluation_window_minutes ?? "1440",
            ),
            freshnessMinutes: String(slo.freshness_minutes ?? "15"),
            completenessPercent: String(
                slo.completeness_percent ?? "99.9",
            ),
            latencyMs: String(slo.latency_ms ?? "5000"),
            errorRatePercent: String(slo.error_rate_percent ?? "1"),
            acknowledgementSeconds: String(
                slo.acknowledgement_seconds ?? "30",
            ),
            reconciliationVariancePercent: String(
                slo.reconciliation_variance_percent ?? "1",
            ),
            changeReason: "",
        });
    }, [onboarding.data?.currentProfile]);

    const submitProfile = (event: FormEvent) => {
        event.preventDefault();
        const current = onboarding.data?.currentProfile;
        if (!current) return;
        const contacts = [
            {
                role: "owner",
                name: profile.ownerName,
                email: profile.ownerEmail || null,
            },
            {
                role: "steward",
                name: profile.stewardName,
                email: profile.stewardEmail || null,
            },
            {
                role: "escalation",
                name: profile.escalationName,
                email: profile.escalationEmail || null,
            },
        ].filter((contact) => contact.name.trim() !== "");
        saveProfile.mutate({
            sourceId: source.sourceId,
            input: {
                expected_onboarding_version_id: current.onboardingVersionId,
                change_reason: profile.changeReason,
                system_version: profile.systemVersion || null,
                protocol_profile: profile.protocolProfile || null,
                owner_name: profile.ownerName || null,
                steward_name: profile.stewardName || null,
                network_route_key: profile.networkRouteKey || null,
                data_classification: profile.dataClassification,
                permitted_purpose: profile.permittedPurpose || null,
                phi_permission_basis: profile.phiPermissionBasis || null,
                retention_policy_key: profile.retentionPolicyKey || null,
                retention_days: profile.retentionDays
                    ? Number(profile.retentionDays)
                    : null,
                credential_strategy: profile.credentialStrategy || null,
                conformance_status: profile.conformanceStatus,
                support_entitlement: profile.supportEntitlement,
                vendor_support_identifier:
                    profile.vendorSupportIdentifier || null,
                maintenance_timezone: profile.maintenanceTimezone || null,
                contacts,
                maintenance_windows: profile.maintenanceStart
                    ? [
                          {
                              weekday: Number(profile.maintenanceWeekday),
                              start_local: profile.maintenanceStart,
                              duration_minutes: Number(
                                  profile.maintenanceDuration,
                              ),
                              purpose: "Planned vendor maintenance",
                          },
                      ]
                    : [],
                slo_definition: {
                    evaluation_window_minutes: Number(
                        profile.evaluationWindowMinutes,
                    ),
                    availability_percent: Number(
                        profile.availabilityPercent,
                    ),
                    freshness_minutes: Number(profile.freshnessMinutes),
                    completeness_percent: Number(
                        profile.completenessPercent,
                    ),
                    latency_ms: Number(profile.latencyMs),
                    error_rate_percent: Number(profile.errorRatePercent),
                    acknowledgement_seconds: Number(
                        profile.acknowledgementSeconds,
                    ),
                    reconciliation_variance_percent: Number(
                        profile.reconciliationVariancePercent,
                    ),
                },
            },
        });
    };

    const submitEvidence = (event: FormEvent) => {
        event.preventDefault();
        const current = onboarding.data?.evidence.find(
            (item) => item.evidenceType === evidence.type,
        );
        addEvidence.mutate(
            {
                sourceId: source.sourceId,
                input: {
                    evidence_type: evidence.type,
                    evidence_status: evidence.status,
                    display_label: evidence.label,
                    reference_uri: evidence.reference,
                    artifact_sha256: evidence.artifactSha256 || null,
                    issued_at: evidence.issuedAt
                        ? new Date(evidence.issuedAt).toISOString()
                        : null,
                    expires_at: evidence.expiresAt
                        ? new Date(evidence.expiresAt).toISOString()
                        : null,
                    supersedes_evidence_id:
                        current?.evidenceRecordId ?? null,
                    reason: evidence.reason,
                },
            },
            {
                onSuccess: () =>
                    setEvidence({
                        ...evidence,
                        label: "",
                        reference: "",
                        artifactSha256: "",
                        issuedAt: "",
                        expiresAt: "",
                        reason: "",
                    }),
            },
        );
    };

    const requestSchedule = async (event: FormEvent) => {
        event.preventDefault();
        try {
            await schedule.mutateAsync({
                sourceId: source.sourceId,
                input: {
                    activate_at: new Date(activation.activateAt).toISOString(),
                    window_ends_at: new Date(
                        activation.windowEndsAt,
                    ).toISOString(),
                    requested_timezone: activation.timezone,
                    reason: activation.reason,
                },
            });
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 428) {
                const url = error.response.data?.error?.reauthentication_url;
                if (typeof url === "string") router.visit(url);
            }
        }
    };

    const workspaceError = errorMessage(
        onboarding.error ??
            saveProfile.error ??
            addEvidence.error ??
            assess.error ??
            schedule.error ??
            cancelWindow.error,
    );
    if (onboarding.isLoading) {
        return <div className="text-sm">Loading onboarding authority…</div>;
    }
    if (!onboarding.data) {
        return (
            <div role="alert" className="text-sm text-healthcare-critical">
                {workspaceError ?? "Onboarding authority is unavailable."}
            </div>
        );
    }
    const readiness = onboarding.data.readiness;
    const currentEvidence = onboarding.data.evidence.filter(
        (item, index, rows) =>
            rows.findIndex(
                (candidate) => candidate.evidenceType === item.evidenceType,
            ) === index,
    );

    return (
        <section className="space-y-3 rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        Production onboarding and activation readiness
                    </h4>
                    <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Immutable profile v
                        {onboarding.data.currentProfile.versionNumber} · {" "}
                        {readiness.passedCount}/{readiness.requirementCount} gates
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <span
                        className={`rounded-full px-2 py-1 text-xs font-semibold ${readiness.status === "ready" ? "bg-healthcare-success/15 text-healthcare-success" : "bg-healthcare-warning/15 text-healthcare-warning"}`}
                    >
                        {readiness.score}% · {readiness.status.replaceAll("_", " ")}
                    </span>
                    <button
                        type="button"
                        className={secondaryButton}
                        disabled={assess.isPending}
                        onClick={() =>
                            assess.mutate({ sourceId: source.sourceId })
                        }
                    >
                        Record assessment
                    </button>
                </div>
            </div>
            <div className="flex flex-wrap gap-1">
                {readiness.supportBadges.map((badge) => (
                    <span
                        key={badge}
                        className="rounded bg-healthcare-surface px-2 py-1 text-xs font-semibold text-healthcare-text-secondary dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark"
                    >
                        {badge}
                    </span>
                ))}
            </div>
            {workspaceError && (
                <div role="alert" className="text-sm text-healthcare-critical">
                    {workspaceError}
                </div>
            )}
            <details open className="rounded border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <summary className="cursor-pointer text-sm font-semibold">
                    1. System, governance, operations, and SLO profile
                </summary>
                <form onSubmit={submitProfile} className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ["System version", "systemVersion"],
                        ["Protocol/profile", "protocolProfile"],
                        ["Owner name", "ownerName"],
                        ["Owner email", "ownerEmail"],
                        ["Steward name", "stewardName"],
                        ["Steward email", "stewardEmail"],
                        ["Escalation contact", "escalationName"],
                        ["Escalation email", "escalationEmail"],
                        ["Network route key", "networkRouteKey"],
                        ["Retention policy", "retentionPolicyKey"],
                        ["Retention days", "retentionDays"],
                        ["Vendor support ID", "vendorSupportIdentifier"],
                    ].map(([label, key]) => (
                        <label key={key} className={labelClass}>
                            {label}
                            <input
                                value={profile[key as keyof typeof profile]}
                                onChange={(event) =>
                                    setProfile({
                                        ...profile,
                                        [key]: event.target.value,
                                    })
                                }
                                className={inputClass}
                                type={key.includes("Email") ? "email" : key === "retentionDays" ? "number" : "text"}
                            />
                        </label>
                    ))}
                    <label className={labelClass}>
                        Data classification
                        <select
                            className={inputClass}
                            value={profile.dataClassification}
                            onChange={(event) =>
                                setProfile({
                                    ...profile,
                                    dataClassification: event.target.value,
                                })
                            }
                        >
                            {["unknown", "public", "internal", "confidential", "restricted_phi"].map((value) => <option key={value}>{value}</option>)}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Credential strategy
                        <select className={inputClass} value={profile.credentialStrategy} onChange={(event) => setProfile({ ...profile, credentialStrategy: event.target.value })}>
                            <option value="">Not selected</option>
                            {["oauth2", "smart_backend_services", "mtls", "api_key", "basic_auth", "managed_interface_engine", "other"].map((value) => <option key={value}>{value}</option>)}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Conformance
                        <select className={inputClass} value={profile.conformanceStatus} onChange={(event) => setProfile({ ...profile, conformanceStatus: event.target.value })}>
                            {["not_tested", "planned", "testing", "passed", "failed", "expired"].map((value) => <option key={value}>{value}</option>)}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Support entitlement
                        <select className={inputClass} value={profile.supportEntitlement} onChange={(event) => setProfile({ ...profile, supportEntitlement: event.target.value })}>
                            {["unknown", "none", "standard", "premium", "critical"].map((value) => <option key={value}>{value}</option>)}
                        </select>
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        Permitted purpose
                        <textarea className={inputClass} value={profile.permittedPurpose} onChange={(event) => setProfile({ ...profile, permittedPurpose: event.target.value })} maxLength={500} />
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        PHI permission basis
                        <input className={inputClass} value={profile.phiPermissionBasis} onChange={(event) => setProfile({ ...profile, phiPermissionBasis: event.target.value })} />
                    </label>
                    <label className={labelClass}>Maintenance timezone<input className={inputClass} value={profile.maintenanceTimezone} onChange={(event) => setProfile({ ...profile, maintenanceTimezone: event.target.value })} /></label>
                    <label className={labelClass}>Maintenance weekday (0–6)<input type="number" min="0" max="6" className={inputClass} value={profile.maintenanceWeekday} onChange={(event) => setProfile({ ...profile, maintenanceWeekday: event.target.value })} /></label>
                    <label className={labelClass}>Maintenance start<input type="time" className={inputClass} value={profile.maintenanceStart} onChange={(event) => setProfile({ ...profile, maintenanceStart: event.target.value })} /></label>
                    <label className={labelClass}>Duration minutes<input type="number" min="15" max="1440" className={inputClass} value={profile.maintenanceDuration} onChange={(event) => setProfile({ ...profile, maintenanceDuration: event.target.value })} /></label>
                    {[
                        ["Evaluation window minutes", "evaluationWindowMinutes"],
                        ["Availability %", "availabilityPercent"],
                        ["Freshness minutes", "freshnessMinutes"],
                        ["Completeness %", "completenessPercent"],
                        ["Latency ms", "latencyMs"],
                        ["Error rate %", "errorRatePercent"],
                        ["Acknowledgement seconds", "acknowledgementSeconds"],
                        ["Reconciliation variance %", "reconciliationVariancePercent"],
                    ].map(([label, key]) => (
                        <label key={key} className={labelClass}>{label}<input type="number" step="any" className={inputClass} value={profile[key as keyof typeof profile]} onChange={(event) => setProfile({ ...profile, [key]: event.target.value })} /></label>
                    ))}
                    <label className={`${labelClass} sm:col-span-2 lg:col-span-4`}>
                        Version reason
                        <textarea required minLength={10} maxLength={500} className={inputClass} value={profile.changeReason} onChange={(event) => setProfile({ ...profile, changeReason: event.target.value })} />
                    </label>
                    <div className="lg:col-span-4"><button type="submit" className={primaryButton} disabled={saveProfile.isPending}>Save immutable onboarding version</button></div>
                </form>
            </details>
            <details className="rounded border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <summary className="cursor-pointer text-sm font-semibold">
                    2. Evidence pointers ({currentEvidence.length})
                </summary>
                <div className="mt-3 grid gap-3 lg:grid-cols-2">
                    <form onSubmit={submitEvidence} className="grid gap-3 sm:grid-cols-2">
                        <label className={labelClass}>Evidence type<select className={inputClass} value={evidence.type} onChange={(event) => setEvidence({ ...evidence, type: event.target.value })}>{["contract", "baa", "dua", "conformance_report", "vendor_approval", "customer_uat", "test_results", "security_review", "change_ticket", "cutover_plan", "rollback_plan"].map((value) => <option key={value}>{value}</option>)}</select></label>
                        <label className={labelClass}>Status<select className={inputClass} value={evidence.status} onChange={(event) => setEvidence({ ...evidence, status: event.target.value })}>{["pending", "verified", "not_required", "failed", "expired", "revoked"].map((value) => <option key={value}>{value}</option>)}</select></label>
                        <label className={`${labelClass} sm:col-span-2`}>Display label<input required className={inputClass} value={evidence.label} onChange={(event) => setEvidence({ ...evidence, label: event.target.value })} /></label>
                        <label className={`${labelClass} sm:col-span-2`}>Opaque document/ticket reference<input required className={inputClass} value={evidence.reference} onChange={(event) => setEvidence({ ...evidence, reference: event.target.value })} placeholder="https://approved-repository.example/evidence/id" /></label>
                        <label className={`${labelClass} sm:col-span-2`}>Artifact SHA-256<input className={inputClass} value={evidence.artifactSha256} onChange={(event) => setEvidence({ ...evidence, artifactSha256: event.target.value })} /></label>
                        <label className={labelClass}>Issued at<input type="datetime-local" className={inputClass} value={evidence.issuedAt} onChange={(event) => setEvidence({ ...evidence, issuedAt: event.target.value })} /></label>
                        <label className={labelClass}>Expires at<input type="datetime-local" className={inputClass} value={evidence.expiresAt} onChange={(event) => setEvidence({ ...evidence, expiresAt: event.target.value })} /></label>
                        <label className={`${labelClass} sm:col-span-2`}>Evidence reason<textarea required minLength={10} maxLength={500} className={inputClass} value={evidence.reason} onChange={(event) => setEvidence({ ...evidence, reason: event.target.value })} /></label>
                        <div className="sm:col-span-2"><button type="submit" className={primaryButton} disabled={addEvidence.isPending}>Record append-only evidence pointer</button></div>
                    </form>
                    <div className="space-y-2">
                        {currentEvidence.map((item) => (
                            <div key={item.evidenceRecordId} className="rounded bg-healthcare-surface p-2 text-xs dark:bg-healthcare-surface-dark">
                                <div className="font-semibold">{item.evidenceType.replaceAll("_", " ")} · {item.evidenceStatus}</div>
                                <div>{item.displayLabel}</div>
                                <code className="text-xs">ref {item.referenceFingerprint}…</code>
                            </div>
                        ))}
                    </div>
                </div>
            </details>
            <details className="rounded border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <summary className="cursor-pointer text-sm font-semibold">
                    3. Readiness gaps ({readiness.requirementCount - readiness.passedCount})
                </summary>
                <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {readiness.requirements.map((requirement) => (
                        <div key={requirement.code} className={`rounded p-2 text-xs ${requirement.status === "passed" ? "bg-healthcare-success/10" : "bg-healthcare-warning/10"}`}>
                            <div className="font-semibold">{requirement.status === "passed" ? "Passed" : "Required"} · {requirement.code}</div>
                            <div>{requirement.message}</div>
                        </div>
                    ))}
                </div>
            </details>
            <details className="rounded border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <summary className="cursor-pointer text-sm font-semibold">
                    4. Future-dated governed activation
                </summary>
                <form onSubmit={requestSchedule} className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label className={labelClass}>Activate at<input required type="datetime-local" className={inputClass} value={activation.activateAt} onChange={(event) => setActivation({ ...activation, activateAt: event.target.value })} /></label>
                    <label className={labelClass}>Window closes<input required type="datetime-local" className={inputClass} value={activation.windowEndsAt} onChange={(event) => setActivation({ ...activation, windowEndsAt: event.target.value })} /></label>
                    <label className={labelClass}>Requested timezone<input required className={inputClass} value={activation.timezone} onChange={(event) => setActivation({ ...activation, timezone: event.target.value })} /></label>
                    <label className={`${labelClass} sm:col-span-2 lg:col-span-4`}>Activation reason<textarea required minLength={10} maxLength={500} className={inputClass} value={activation.reason} onChange={(event) => setActivation({ ...activation, reason: event.target.value })} /></label>
                    <div className="lg:col-span-4"><button type="submit" className={primaryButton} disabled={schedule.isPending || readiness.status !== "ready"}>Request independently approved activation window</button></div>
                </form>
                <div className="mt-3 space-y-2">
                    {onboarding.data.activationWindows.map((window) => (
                        <div key={window.activationWindowId} className="rounded bg-healthcare-surface p-2 text-xs dark:bg-healthcare-surface-dark">
                            <div className="font-semibold">{window.status} · {new Date(window.activateAtIso).toLocaleString()}–{new Date(window.windowEndsAtIso).toLocaleString()}</div>
                            <div>Configuration {window.configurationVersionId} · onboarding {window.onboardingVersionId} · attempts {window.attemptCount}/{window.maxAttempts}</div>
                            {window.lastErrorCode && <div className="text-healthcare-critical">{window.lastErrorCode}</div>}
                            {["pending_approval", "scheduled", "leased"].includes(window.status) && (
                                <button
                                    type="button"
                                    className={`${secondaryButton} mt-2`}
                                    disabled={cancelWindow.isPending}
                                    onClick={async () => {
                                        const reason = globalThis.window.prompt("Cancellation reason (10–500 characters; no PHI or credentials):");
                                        if (!reason || reason.trim().length < 10) return;
                                        try {
                                            await cancelWindow.mutateAsync({ sourceId: source.sourceId, windowUuid: window.activationWindowUuid, reason: reason.trim() });
                                        } catch (error) {
                                            if (axios.isAxiosError(error) && error.response?.status === 428) {
                                                const url = error.response.data?.error?.reauthentication_url;
                                                if (typeof url === "string") router.visit(url);
                                            }
                                        }
                                    }}
                                >
                                    Cancel activation window
                                </button>
                            )}
                        </div>
                    ))}
                    {onboarding.data.activationWindows.length === 0 && <div className="text-xs text-healthcare-text-secondary">No activation windows recorded.</div>}
                </div>
            </details>
        </section>
    );
}

export function EndpointConfiguration({
    data,
    selectedSourceId,
}: {
    data: IntegrationControlPlane;
    selectedSourceId: number | null;
}) {
    const create = useCreateIntegrationEndpoint();
    const update = useUpdateIntegrationEndpoint();
    const remove = useDeleteIntegrationEndpoint();
    const [sourceId, setSourceId] = useState(0);
    const [open, setOpen] = useState(false);
    const availableSources = selectedSourceId
        ? data.sources.filter((source) => source.sourceId === selectedSourceId)
        : [];
    const [form, setForm] = useState({
        endpointType: "api_base",
        url: "",
        authType: "oauth2",
        tlsMode: "system_ca",
        owner: "",
        cadence: "15",
    });
    useEffect(() => {
        setSourceId(selectedSourceId ?? 0);
    }, [selectedSourceId]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        create.mutate(
            {
                sourceId,
                input: {
                    endpoint_type: form.endpointType,
                    url: form.url,
                    auth_type: form.authType,
                    tls_mode: form.tlsMode,
                    is_active: true,
                    owner: form.owner || null,
                    expected_cadence_minutes: form.cadence
                        ? Number(form.cadence)
                        : null,
                },
            },
            {
                onSuccess: () => {
                    setOpen(false);
                    setForm({ ...form, url: "" });
                },
            },
        );
    };
    const error = errorMessage(create.error ?? update.error ?? remove.error);
    return (
        <div className="space-y-3">
            <div className="flex justify-end">
                <button
                    type="button"
                    className={secondaryButton}
                    disabled={!availableSources.length}
                    onClick={() => setOpen(true)}
                >
                    <Plus className="size-4" /> Add endpoint
                </button>
            </div>
            {open && (
                <form
                    onSubmit={submit}
                    className="grid gap-3 rounded-md border border-healthcare-border p-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-healthcare-border-dark"
                >
                    <label className={labelClass}>
                        Source
                        <select
                            value={sourceId}
                            onChange={(e) =>
                                setSourceId(Number(e.target.value))
                            }
                            className={inputClass}
                        >
                            {availableSources.map((s) => (
                                <option key={s.sourceId} value={s.sourceId}>
                                    {s.sourceName}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Endpoint type
                        <select
                            value={form.endpointType}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    endpointType: e.target.value,
                                })
                            }
                            className={inputClass}
                        >
                            {[
                                "api_base",
                                "fhir_base",
                                "smart_discovery",
                                "oauth_token",
                                "webhook",
                                "interface_gateway",
                                "dicomweb",
                                "bulk_export",
                                "other",
                            ].map((v) => (
                                <option key={v}>{v}</option>
                            ))}
                        </select>
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        HTTPS URL
                        <input
                            required
                            value={form.url}
                            onChange={(e) =>
                                setForm({ ...form, url: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        Authentication
                        <select
                            value={form.authType}
                            onChange={(e) =>
                                setForm({ ...form, authType: e.target.value })
                            }
                            className={inputClass}
                        >
                            {[
                                "none",
                                "oauth2",
                                "smart_backend",
                                "mtls",
                                "api_key_ref",
                                "basic_ref",
                            ].map((v) => (
                                <option key={v}>{v}</option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        TLS
                        <select
                            value={form.tlsMode}
                            onChange={(e) =>
                                setForm({ ...form, tlsMode: e.target.value })
                            }
                            className={inputClass}
                        >
                            {["system_ca", "pinned_ca", "mtls"].map((v) => (
                                <option key={v}>{v}</option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Owner
                        <input
                            value={form.owner}
                            onChange={(e) =>
                                setForm({ ...form, owner: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        Cadence (minutes)
                        <input
                            type="number"
                            min="1"
                            value={form.cadence}
                            onChange={(e) =>
                                setForm({ ...form, cadence: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <div className="flex gap-2 lg:col-span-4">
                        <button
                            className={primaryButton}
                            disabled={create.isPending}
                        >
                            Create endpoint
                        </button>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            )}
            {error && (
                <div
                    role="alert"
                    className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark"
                >
                    {error}
                </div>
            )}
            <div className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">
                {data.endpoints.map((endpoint) => (
                    <div
                        key={endpoint.endpointId}
                        className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                    >
                        <div>
                            <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {endpoint.sourceName} ·{" "}
                                {endpoint.endpointType.replaceAll("_", " ")}
                            </div>
                            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {endpoint.urlOrigin ?? "No URL"} ·{" "}
                                {endpoint.isActive ? "Active" : "Disabled"}
                            </div>
                        </div>
                        <div className="flex gap-1">
                            <button
                                type="button"
                                className={secondaryButton}
                                disabled={
                                    update.isPending ||
                                    selectedSourceId !== endpoint.sourceId
                                }
                                onClick={() =>
                                    update.mutate({
                                        sourceId: endpoint.sourceId,
                                        endpointId: endpoint.endpointId,
                                        input: {
                                            is_active: !endpoint.isActive,
                                        },
                                    })
                                }
                            >
                                {endpoint.isActive ? "Disable" : "Enable"}
                            </button>
                            <button
                                type="button"
                                title="Delete endpoint"
                                aria-label="Delete endpoint"
                                className={secondaryButton}
                                disabled={
                                    remove.isPending ||
                                    selectedSourceId !== endpoint.sourceId
                                }
                                onClick={() =>
                                    window.confirm("Delete this endpoint?") &&
                                    remove.mutate({
                                        sourceId: endpoint.sourceId,
                                        endpointId: endpoint.endpointId,
                                    })
                                }
                            >
                                <Trash2 className="size-4" />
                            </button>
                        </div>
                    </div>
                ))}
                {data.endpoints.length === 0 && (
                    <div className="p-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No endpoints configured.
                    </div>
                )}
            </div>
        </div>
    );
}

export function CredentialConfiguration({
    data,
    selectedSourceId,
}: {
    data: IntegrationControlPlane;
    selectedSourceId: number | null;
}) {
    const create = useCreateIntegrationCredential();
    const remove = useDeleteIntegrationCredential();
    const [open, setOpen] = useState(false);
    const [sourceId, setSourceId] = useState(0);
    const availableSources = selectedSourceId
        ? data.sources.filter((source) => source.sourceId === selectedSourceId)
        : [];
    const [form, setForm] = useState({
        key: "",
        type: "smart_backend_services",
        secretRef: "",
        certificateRef: "",
        jwksUri: "",
        rotatesAt: "",
        validFrom: "",
        expiresAt: "",
        owner: "",
        changeReason: "Initialize this governed credential reference authority.",
    });
    useEffect(() => {
        setSourceId(selectedSourceId ?? 0);
    }, [selectedSourceId]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        create.mutate(
            {
                sourceId,
                input: {
                    credential_key: form.key,
                    credential_type: form.type,
                    secret_ref: form.secretRef || null,
                    certificate_ref: form.certificateRef || null,
                    jwks_uri: form.jwksUri || null,
                    rotates_at: form.rotatesAt || null,
                    valid_from: form.validFrom || null,
                    expires_at: form.expiresAt || null,
                    is_active: true,
                    owner: form.owner || null,
                    change_reason: form.changeReason,
                },
            },
            {
                onSuccess: () => {
                    setOpen(false);
                    setForm({
                        ...form,
                        key: "",
                        secretRef: "",
                        certificateRef: "",
                        jwksUri: "",
                    });
                },
            },
        );
    };
    const error = errorMessage(create.error ?? remove.error);
    return (
        <div className="space-y-3">
            <div className="flex justify-end">
                <button
                    type="button"
                    className={secondaryButton}
                    disabled={!availableSources.length}
                    onClick={() => setOpen(true)}
                >
                    <KeyRound className="size-4" /> Add reference
                </button>
            </div>
            {open && (
                <form
                    onSubmit={submit}
                    className="grid gap-3 rounded-md border border-healthcare-border p-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-healthcare-border-dark"
                >
                    <label className={labelClass}>
                        Source
                        <select
                            value={sourceId}
                            onChange={(e) =>
                                setSourceId(Number(e.target.value))
                            }
                            className={inputClass}
                        >
                            {availableSources.map((s) => (
                                <option key={s.sourceId} value={s.sourceId}>
                                    {s.sourceName}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Credential key
                        <input
                            required
                            value={form.key}
                            onChange={(e) =>
                                setForm({ ...form, key: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        Type
                        <select
                            value={form.type}
                            onChange={(e) =>
                                setForm({ ...form, type: e.target.value })
                            }
                            className={inputClass}
                        >
                            {[
                                "smart_backend_services",
                                "oauth2_client",
                                "mtls",
                                "api_key",
                                "basic_auth",
                                "jwks",
                            ].map((v) => (
                                <option key={v}>
                                    {v.replaceAll("_", " ")}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className={labelClass}>
                        Valid from
                        <input
                            type="datetime-local"
                            value={form.validFrom}
                            onChange={(e) =>
                                setForm({ ...form, validFrom: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        Expires at
                        <input
                            type="datetime-local"
                            value={form.expiresAt}
                            onChange={(e) =>
                                setForm({ ...form, expiresAt: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={labelClass}>
                        Rotation deadline
                        <input
                            type="date"
                            value={form.rotatesAt}
                            onChange={(e) =>
                                setForm({ ...form, rotatesAt: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        Secret manager reference
                        <input
                            value={form.secretRef}
                            onChange={(e) =>
                                setForm({ ...form, secretRef: e.target.value })
                            }
                            className={inputClass}
                            placeholder="vault://path/to/secret"
                        />
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        Certificate reference
                        <input
                            value={form.certificateRef}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    certificateRef: e.target.value,
                                })
                            }
                            className={inputClass}
                            placeholder="vault://path/to/certificate"
                        />
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        JWKS URI
                        <input
                            value={form.jwksUri}
                            onChange={(e) =>
                                setForm({ ...form, jwksUri: e.target.value })
                            }
                            className={inputClass}
                            placeholder="https://approved-host.example/.well-known/jwks.json"
                        />
                    </label>
                    <label className={labelClass}>
                        Owner
                        <input
                            value={form.owner}
                            onChange={(e) =>
                                setForm({ ...form, owner: e.target.value })
                            }
                            className={inputClass}
                        />
                    </label>
                    <label className={`${labelClass} sm:col-span-2`}>
                        Authority reason
                        <input
                            required
                            minLength={10}
                            maxLength={500}
                            value={form.changeReason}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    changeReason: e.target.value,
                                })
                            }
                            className={inputClass}
                            placeholder="10–500 characters; no PHI or credentials"
                        />
                    </label>
                    <div className="flex items-end gap-2">
                        <button
                            className={primaryButton}
                            disabled={create.isPending}
                        >
                            Save reference
                        </button>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            )}
            {error && (
                <div
                    role="alert"
                    className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark"
                >
                    {error}
                </div>
            )}
            <div className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">
                {data.credentials.map((credential) => (
                    <div
                        key={credential.credentialId}
                        className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                    >
                        <div>
                            <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {credential.sourceName} ·{" "}
                                {credential.credentialKey}
                            </div>
                            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {credential.credentialType.replaceAll("_", " ")}{" "}
                                · {credential.status}
                            </div>
                        </div>
                        {credential.sourceCredentialId !== null && (
                            <div className="flex gap-1">
                                <button
                                    type="button"
                                    title="Revoke credential reference"
                                    aria-label="Revoke credential reference"
                                    className={secondaryButton}
                                    disabled={
                                        remove.isPending ||
                                        selectedSourceId !== credential.sourceId
                                    }
                                    onClick={() => {
                                        const reason = window.prompt(
                                            "Credential revocation reason (10–500 characters; no PHI or credentials):",
                                        );
                                        if (!reason || reason.trim().length < 10)
                                            return;
                                        remove.mutate({
                                            sourceId: credential.sourceId,
                                            credentialId:
                                                credential.sourceCredentialId!,
                                            reason: reason.trim(),
                                        });
                                    }}
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        )}
                    </div>
                ))}
                {data.credentials.length === 0 && (
                    <div className="p-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No credential references configured.
                    </div>
                )}
            </div>
        </div>
    );
}
